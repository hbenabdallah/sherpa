<?php

declare(strict_types=1);

namespace App\TUI;

use Mcp\Schema\Elicitation\AbstractSchemaDefinition;
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\EnumSchemaDefinition;
use Mcp\Schema\Elicitation\MultiSelectEnumSchemaDefinition;
use Mcp\Schema\Elicitation\NumberSchemaDefinition;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Elicitation\TitledEnumSchemaDefinition;
use Mcp\Schema\Elicitation\TitledMultiSelectEnumSchemaDefinition;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;
use ReflectionClass;

/**
 * Renders an elicitation form in the terminal and collects user input.
 *
 * Supports form-mode elicitation with string, integer, number, boolean,
 * enum, and array (multi-select) field types.
 *
 * @phpstan-type Field array{name: string, definition: AbstractSchemaDefinition, required: bool}
 */
final readonly class ElicitationForm
{
    /** Sentinel returned by handleEditingKey when the value is accepted. */
    private const int DONE = -1;

    /** Sentinel returned by handleEditingKey when the whole form is cancelled. */
    private const int CANCEL = -2;

    /** Sentinel returned by handleEditingKey when editing is cancelled (keep previous value). */
    private const int STOP_EDITING = -3;

    /** Sentinel returned by handleNavigationKey when the user wants to edit the current field. */
    private const int EDIT = -4;

    /** Sentinel returned by handleNavigationKey when the user wants to submit the form. */
    private const int SUBMIT = -5;

    public function __construct(
        private Terminal $terminal,
    ) {}

    /**
     * Present the elicitation form to the user and return their response.
     */
    public function present(ElicitRequest $request): ElicitResult
    {
        if ($request->mode->value !== 'form' || !$request->requestedSchema instanceof ElicitationSchema) {
            // URL mode or no schema - cannot present a form
            return new ElicitResult(ElicitAction::Cancel);
        }

        $schema = $request->requestedSchema;
        $fields = $this->buildFields($schema);

        if ($fields === []) {
            return new ElicitResult(ElicitAction::Cancel);
        }

        try {
            $this->terminal->rawMode();
            $this->terminal->discardPendingInput();

            $values = $this->collectInput($fields, $request->message);

            if ($values === null) {
                // User cancelled (Esc or Ctrl+C)
                return new ElicitResult(ElicitAction::Cancel);
            }

            return new ElicitResult(ElicitAction::Accept, $values);
        } finally {
            $this->terminal->restoreMode();
        }
    }

    /**
     * Build field definitions from the elicitation schema.
     *
     * @return array<int, Field>
     */
    private function buildFields(ElicitationSchema $schema): array
    {
        $fields = [];
        foreach ($schema->properties as $name => $definition) {
            $required = in_array($name, $schema->required, true);
            $fields[] = ['name' => $name, 'definition' => $definition, 'required' => $required];
        }

        return $fields;
    }

    /**
     * Collect user input for all fields.
     *
     * @param array<int, Field> $fields
     * @return array<string, mixed>|null The collected values, or null if cancelled
     */
    private function collectInput(array $fields, string $message): ?array
    {
        $values = [];
        $currentField = 0;
        $editing = false;
        $buffer = '';
        $validationError = '';

        // Get defaults from schema
        foreach ($fields as $field) {
            $default = $field['definition']->getDefault();
            if ($default !== null) {
                $values[$field['name']] = $default;
            }
        }

        while (true) {
            $this->renderForm($fields, $values, $currentField, $message, $editing, $buffer, $validationError);

            $key = $this->terminal->readKey();

            // Handle EOF - treat as cancel to prevent infinite loop
            if ($key === '') {
                return null;
            }

            if ($editing) {
                $result = $this->handleEditingKey($key, $fields[$currentField], $buffer, $values);
                if (is_int($result)) {
                    // Control sentinel
                    if ($result === self::DONE) {
                        $editing = false;
                        $buffer = '';
                        $validationError = '';
                        // After accepting a value, if on the last field, try to submit
                        if ($currentField === count($fields) - 1) {
                            $error = $this->validateRequired($fields, $values);
                            if ($error === '') {
                                return $values;
                            }
                            $validationError = $error;
                        }
                    } elseif ($result === self::CANCEL) {
                        return null;
                    } elseif ($result === self::STOP_EDITING) {
                        // Esc pressed while editing: cancel editing, keep previous value
                        $editing = false;
                        $buffer = '';
                    }
                } else {
                    // Buffer update (string)
                    $buffer = $result;
                }
            } else {
                $result = $this->handleNavigationKey($key, $fields, $currentField, $values);
                if ($result === self::SUBMIT) {
                    // Validate required fields
                    $error = $this->validateRequired($fields, $values);
                    if ($error === '') {
                        return $values;
                    }
                    $validationError = $error;
                } elseif ($result === self::CANCEL) {
                    return null;
                } elseif ($result === self::EDIT) {
                    $editing = true;
                    $buffer = $this->formatValue($fields[$currentField]['definition'], $values[$fields[$currentField]['name']] ?? null);
                    $validationError = '';
                } else {
                    // Field index (non-negative int)
                    $currentField = $result;
                    $validationError = '';
                }
            }
        }
    }

    /**
     * Handle keys while editing a field value.
     *
     * @param Field $field
     * @param array<string, mixed> $values
     */
    private function handleEditingKey(string $key, array $field, string $buffer, array &$values): int|string
    {
        $definition = $field['definition'];

        // Enter - accept the value
        if (in_array($key, ["\r", "\n"], true)) {
            $parsed = $this->parseValue($definition, $buffer);
            if ($parsed !== null || $buffer === '') {
                if ($parsed !== null) {
                    $values[$field['name']] = $parsed;
                } elseif ($field['required']) {
                    // Required field left empty - stay in editing mode
                    return $buffer;
                } else {
                    // Optional field left empty - remove the value
                    unset($values[$field['name']]);
                }

                return self::DONE;
            }

            return $buffer;
        }

        // Escape - cancel editing (keep previous value), not cancel the whole form
        if ($key === "\e") {
            return self::STOP_EDITING;
        }

        // Backspace
        if ($key === "\x7f" || $key === "\x08") {
            return $buffer !== '' ? mb_substr($buffer, 0, -1) : '';
        }

        // Printable character
        if (strlen($key) === 1 && $key >= ' ' && $key <= '~') {
            return $buffer . $key;
        }

        return $buffer;
    }

    /**
     * Handle navigation keys (not editing).
     *
     * @param array<int, Field> $fields
     * @param array<string, mixed> $values
     */
    private function handleNavigationKey(string $key, array $fields, int $currentField, array &$values): int
    {
        // Up/Down - navigate fields
        if (in_array($key, ["\e[A", "\eOA"], true)) { // Up
            return max(0, $currentField - 1);
        }
        if (in_array($key, ["\e[B", "\eOB"], true)) { // Down
            return min(count($fields) - 1, $currentField + 1);
        }

        // Enter - edit current field
        if (in_array($key, ["\r", "\n"], true)) {
            return self::EDIT;
        }

        // Escape - cancel
        if ($key === "\e") {
            return self::CANCEL;
        }

        // Space - toggle boolean, edit others
        if ($key === ' ') {
            $field = $fields[$currentField];
            if ($field['definition'] instanceof BooleanSchemaDefinition) {
                $values[$field['name']] = !($values[$field['name']] ?? false);

                return $currentField;
            }

            return self::EDIT;
        }

        return $currentField;
    }

    /**
     * Parse a string value according to the field definition.
     * Returns null if the value is invalid for the field type.
     */
    private function parseValue(AbstractSchemaDefinition $definition, string $value): mixed
    {
        if ($value === '') {
            return null;
        }

        return match (true) {
            $definition instanceof StringSchemaDefinition => $value,
            $definition instanceof EnumSchemaDefinition => $this->validateEnumValue($definition, $value),
            $definition instanceof TitledEnumSchemaDefinition => $this->validateEnumValue($definition, $value),
            $definition instanceof NumberSchemaDefinition => is_numeric($value) ? (float) $value : null,
            $definition instanceof BooleanSchemaDefinition => $this->parseBoolean($value),
            $definition instanceof MultiSelectEnumSchemaDefinition => $this->validateMultiSelectValues($definition, $value),
            $definition instanceof TitledMultiSelectEnumSchemaDefinition => $this->validateMultiSelectValues($definition, $value),
            default => $value,
        };
    }

    /**
     * Validate a single enum value against the allowed options.
     *
     * @param EnumSchemaDefinition|TitledEnumSchemaDefinition $definition
     */
    private function validateEnumValue(AbstractSchemaDefinition $definition, string $value): ?string
    {
        $options = $this->getEnumOptions($definition);
        foreach ($options as $option) {
            if ($option === $value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Validate multi-select values against the allowed options.
     *
     * @param MultiSelectEnumSchemaDefinition|TitledMultiSelectEnumSchemaDefinition $definition
     * @return array<int, string>|null
     */
    private function validateMultiSelectValues(AbstractSchemaDefinition $definition, string $value): ?array
    {
        $options = $this->getEnumOptions($definition);
        $selected = array_map(trim(...), explode(',', $value));
        $selected = array_filter($selected, static fn(string $v): bool => $v !== '');

        foreach ($selected as $item) {
            if (!in_array($item, $options, true)) {
                return null;
            }
        }

        return $selected;
    }

    /**
     * Get the allowed options from an enum definition.
     *
     * @return array<int, string>
     */
    private function getEnumOptions(AbstractSchemaDefinition $definition): array
    {
        $reflection = new ReflectionClass($definition);
        if (!$reflection->hasProperty('options')) {
            return [];
        }

        $property = $reflection->getProperty('options');
        $options = $property->getValue($definition);

        if (!is_array($options)) {
            return [];
        }

        return array_map(strval(...), $options);
    }

    /**
     * Parse a boolean string value.
     */
    private function parseBoolean(string $value): ?bool
    {
        return match (strtolower($value)) {
            'true', '1', 'yes', 'y' => true,
            'false', '0', 'no', 'n' => false,
            default => null,
        };
    }

    /**
     * Format a value for display in the input buffer.
     */
    private function formatValue(AbstractSchemaDefinition $definition, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return match (true) {
            $definition instanceof MultiSelectEnumSchemaDefinition, $definition instanceof TitledMultiSelectEnumSchemaDefinition => implode(', ', (array) $value),
            $definition instanceof BooleanSchemaDefinition => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }

    /**
     * Validate that all required fields have values.
     * Returns an empty string if valid, or an error message.
     *
     * @param array<int, Field> $fields
     * @param array<string, mixed> $values
     */
    private function validateRequired(array $fields, array $values): string
    {
        foreach ($fields as $field) {
            if (!$field['required']) {
                continue;
            }

            $name = $field['name'];
            if (!isset($values[$name]) || $values[$name] === '') {
                $title = $this->getTitle($field['definition']) ?? $name;

                return "Missing required field: {$title}";
            }
        }

        return '';
    }

    /**
     * Render the form to the terminal.
     *
     * @param array<int, Field> $fields
     * @param array<string, mixed> $values
     */
    private function renderForm(array $fields, array $values, int $currentField, string $message, bool $editing, string $buffer, string $validationError): void
    {
        [$cols] = $this->terminal->size();
        $width = min(76, $cols - 4);
        $inner = $width - 4;

        // Clear screen and move to top
        echo "\e[H\e[2J";

        // Title box
        $title = ' Elicitation Request ';
        $yellow = Terminal::YELLOW;
        $side = $yellow . Terminal::V . Terminal::RESET;

        echo '  ' . $yellow . Terminal::TL . Terminal::H . Terminal::BOLD . $title . Terminal::RESET . $yellow
            . str_repeat(Terminal::H, max(0, $width - 3 - mb_strwidth($title))) . Terminal::TR . Terminal::RESET . "\n";

        // Message
        $this->renderWrapped($message, $inner, Terminal::WHITE, $side);

        // Separator
        echo '  ' . $side . ' ' . Terminal::GRAY . str_repeat('─', $inner) . Terminal::RESET . ' ' . $side . "\n";

        // Fields
        foreach ($fields as $index => $field) {
            $isCurrent = $index === $currentField;
            $name = $field['name'];
            $definition = $field['definition'];
            $required = $field['required'];
            $value = $values[$name] ?? null;

            $label = $this->buildLabel($name, $definition, $required);
            $displayValue = $editing && $isCurrent ? $buffer : $this->formatDisplayValue($definition, $value);

            $lineColor = $isCurrent ? ($editing ? Terminal::GREEN : Terminal::CYAN) : Terminal::GRAY;
            $valueColor = $isCurrent ? ($editing ? Terminal::BOLD . Terminal::WHITE : Terminal::YELLOW) : Terminal::WHITE;

            $prefix = $isCurrent ? ($editing ? '> ' : '> ') : '  ';
            $line = $prefix . $label . ': ' . $displayValue;

            echo '  ' . $side . ' ' . $lineColor . mb_strimwidth($line, 0, $inner, '...') . Terminal::RESET
                . str_repeat(' ', max(0, $inner - mb_strwidth($line))) . ' ' . $side . "\n";

            // Show description and options for current field
            if ($isCurrent) {
                $description = $this->getDescription($definition);
                if ($description !== null) {
                    $this->renderWrapped($description, $inner, Terminal::GRAY, $side);
                }

                // Show enum options for the current field
                $options = $this->getFieldOptions($definition);
                if ($options !== [] && !$editing) {
                    $optionsText = 'Options: ' . implode(', ', $options);
                    $this->renderWrapped($optionsText, $inner, Terminal::GRAY, $side);
                }
            }
        }

        // Separator
        echo '  ' . $side . ' ' . Terminal::GRAY . str_repeat('─', $inner) . Terminal::RESET . ' ' . $side . "\n";

        // Validation error
        if ($validationError !== '') {
            $this->renderWrapped($validationError, $inner, Terminal::RED, $side);
        }

        // Help text
        $help = $editing
            ? 'Enter: accept  Esc: cancel editing  Backspace: delete'
            : 'Up/Down: navigate  Enter: edit  Space: toggle boolean  Esc: cancel';
        $this->renderWrapped($help, $inner, Terminal::GRAY, $side);

        // Bottom border
        echo '  ' . $yellow . Terminal::BL . str_repeat(Terminal::H, $width - 2) . Terminal::BR . Terminal::RESET . "\n";
    }

    /**
     * Build a label for a field.
     */
    private function buildLabel(string $name, AbstractSchemaDefinition $definition, bool $required): string
    {
        $title = $this->getTitle($definition) ?? $name;

        return $title . ($required ? ' *' : '');
    }

    /**
     * Get the title from a schema definition.
     */
    private function getTitle(AbstractSchemaDefinition $definition): ?string
    {
        $reflection = new ReflectionClass($definition);
        $property = $reflection->getProperty('title');

        return $property->getValue($definition);
    }

    /**
     * Get the description from a schema definition.
     */
    private function getDescription(AbstractSchemaDefinition $definition): ?string
    {
        $reflection = new ReflectionClass($definition);
        $property = $reflection->getProperty('description');

        return $property->getValue($definition);
    }

    /**
     * Get the options for a field (enum or multi-select).
     *
     * @return array<int, string>
     */
    private function getFieldOptions(AbstractSchemaDefinition $definition): array
    {
        if ($definition instanceof EnumSchemaDefinition || $definition instanceof TitledEnumSchemaDefinition) {
            return $this->getEnumOptions($definition);
        }

        if ($definition instanceof MultiSelectEnumSchemaDefinition || $definition instanceof TitledMultiSelectEnumSchemaDefinition) {
            return $this->getEnumOptions($definition);
        }

        return [];
    }

    /**
     * Format a value for display (not editing). Returns plain text without ANSI codes.
     */
    private function formatDisplayValue(AbstractSchemaDefinition $definition, mixed $value): string
    {
        if ($value === null) {
            return '(empty)';
        }

        return match (true) {
            $definition instanceof BooleanSchemaDefinition => $value ? 'true' : 'false',
            $definition instanceof MultiSelectEnumSchemaDefinition, $definition instanceof TitledMultiSelectEnumSchemaDefinition => implode(', ', (array) $value),
            default => (string) $value,
        };
    }

    /**
     * Render wrapped text within the form.
     */
    private function renderWrapped(string $text, int $width, string $color, string $side): void
    {
        $lines = $this->wrap($text, $width);
        foreach ($lines as $line) {
            echo '  ' . $side . ' ' . $color . $line . Terminal::RESET
                . str_repeat(' ', max(0, $width - mb_strwidth($line))) . ' ' . $side . "\n";
        }
    }

    /**
     * Wrap text to fit width.
     *
     * @return array<int, string>
     */
    private function wrap(string $text, int $width): array
    {
        $words = explode(' ', $text);
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $test = $current === '' ? $word : $current . ' ' . $word;
            if (mb_strwidth($test) > $width) {
                if ($current !== '') {
                    $lines[] = $current;
                    $current = $word;
                } else {
                    // Word longer than width - split it
                    $lines[] = mb_strimwidth($word, 0, $width, '');
                    $current = mb_substr($word, $width);
                }
            } else {
                $current = $test;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }
}

<?php

declare(strict_types=1);

namespace App\TUI;

use Mcp\Schema\Elicitation\AbstractSchemaDefinition;
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use Mcp\Schema\Elicitation\EnumSchemaDefinition;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\MultiSelectEnumSchemaDefinition;
use Mcp\Schema\Elicitation\NumberSchemaDefinition;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Elicitation\TitledEnumSchemaDefinition;
use Mcp\Schema\Elicitation\TitledMultiSelectEnumSchemaDefinition;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;

/**
 * Renders an elicitation form in the terminal and collects user input.
 *
 * Supports form-mode elicitation with string, integer, number, boolean,
 * enum, and array (multi-select) field types.
 */
final class ElicitationForm
{
    /** Maximum rows for the form before scrolling. */
    private const int MAX_ROWS = 20;

    /** @var string */
    private const DONE = 'done';

    /** @var string */
    private const CANCEL = 'cancel';

    /** @var string */
    private const EDIT = 'edit';

    /** @var string */
    private const SUBMIT = 'submit';

    public function __construct(
        private readonly Terminal $terminal,
    ) {}

    /**
     * Present the elicitation form to the user and return their response.
     *
     * @return ElicitResult The user's action and provided content (if accepted)
     */
    public function present(ElicitRequest $request): ElicitResult
    {
        if ($request->mode->value !== 'form' || $request->requestedSchema === null) {
            // URL mode or no schema - cannot present a form
            return new ElicitResult(ElicitAction::Cancel);
        }

        $schema = $request->requestedSchema;
        $fields = $this->buildFields($schema);

        if ($fields === []) {
            return new ElicitResult(ElicitAction::Cancel);
        }

        $this->terminal->rawMode();
        $this->terminal->discardPendingInput();

        try {
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
     * @return array<int, array{name: string, definition: AbstractSchemaDefinition, required: bool}>
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
     * @param array<int, array{name: string, definition: AbstractSchemaDefinition, required: bool}> $fields
     * @return array<string, mixed>|null The collected values, or null if cancelled
     */
    private function collectInput(array $fields, string $message): ?array
    {
        $values = [];
        $currentField = 0;
        $editing = false;
        $buffer = '';

        // Get defaults from schema
        foreach ($fields as $field) {
            $default = $field['definition']->getDefault();
            if ($default !== null) {
                $values[$field['name']] = $default;
            }
        }

        while (true) {
            $this->renderForm($fields, $values, $currentField, $message, $editing, $buffer);

            $key = $this->terminal->readKey();

            if ($editing) {
                $result = $this->handleEditingKey($key, $fields[$currentField], $buffer, $values);
                if ($result === self::DONE) {
                    $editing = false;
                    $buffer = '';
                } elseif ($result === self::CANCEL) {
                    return null;
                } elseif (is_string($result)) {
                    $buffer = $result;
                }
            } else {
                $result = $this->handleNavigationKey($key, $fields, $currentField, $values);
                if ($result === self::SUBMIT) {
                    // Validate required fields
                    if ($this->validateRequired($fields, $values)) {
                        return $values;
                    }
                    // Show error and continue
                } elseif ($result === self::CANCEL) {
                    return null;
                } elseif ($result === self::EDIT) {
                    $editing = true;
                    $buffer = $this->formatValue($fields[$currentField]['definition'], $values[$fields[$currentField]['name']] ?? null);
                } elseif (is_int($result)) {
                    $currentField = $result;
                }
            }
        }
    }

    /**
     * Handle keys while editing a field value.
     *
     * @return string|'done'|'cancel'
     */
    private function handleEditingKey(string $key, array $field, string $buffer, array &$values): string
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
                }
                return self::DONE;
            }
            return $buffer;
        }

        // Escape - cancel editing
        if ($key === "\e") {
            return self::CANCEL;
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
     * @return int|'submit'|'cancel'|'edit'
     */
    private function handleNavigationKey(string $key, array $fields, int $currentField, array &$values): mixed
    {
        // Up/Down - navigate fields
        if (in_array($key, ["\e[A", "\eOA"], true)) { // Up
            return max(0, $currentField - 1);
        }
        if (in_array($key, ["\e[B", "\eOB"], true)) { // Down
            return min(count($fields) - 1, $currentField + 1);
        }

        // Enter - edit current field or submit if on last field
        if (in_array($key, ["\r", "\n"], true)) {
            if ($currentField === count($fields) - 1) {
                return self::SUBMIT;
            }
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
     */
    private function parseValue(AbstractSchemaDefinition $definition, string $value): mixed
    {
        if ($value === '') {
            return null;
        }

        return match (true) {
            $definition instanceof StringSchemaDefinition => $value,
            $definition instanceof EnumSchemaDefinition, $definition instanceof TitledEnumSchemaDefinition => $value,
            $definition instanceof NumberSchemaDefinition => is_numeric($value) ? (float) $value : null,
            $definition instanceof BooleanSchemaDefinition => in_array(strtolower($value), ['true', '1', 'yes', 'y'], true),
            $definition instanceof MultiSelectEnumSchemaDefinition, $definition instanceof TitledMultiSelectEnumSchemaDefinition => array_map('trim', explode(',', $value)),
            default => $value,
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
     */
    private function validateRequired(array $fields, array $values): bool
    {
        foreach ($fields as $field) {
            if ($field['required'] && (!isset($values[$field['name']]) || $values[$field['name']] === '')) {
                return false;
            }
        }
        return true;
    }

    /**
     * Render the form to the terminal.
     */
    private function renderForm(array $fields, array $values, int $currentField, string $message, bool $editing, string $buffer): void
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

            // Show description for current field
            if ($isCurrent && $description = $this->getDescription($definition)) {
                $this->renderWrapped($description, $inner, Terminal::GRAY, $side);
            }
        }

        // Separator
        echo '  ' . $side . ' ' . Terminal::GRAY . str_repeat('─', $inner) . Terminal::RESET . ' ' . $side . "\n";

        // Help text
        $help = $editing
            ? 'Enter: accept  Esc: cancel editing  Backspace: delete'
            : 'Up/Down: navigate  Enter: edit/submit  Space: toggle boolean  Esc: cancel';
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
        $reflection = new \ReflectionClass($definition);
        $property = $reflection->getProperty('title');
        $property->setAccessible(true);
        return $property->getValue($definition);
    }

    /**
     * Get the description from a schema definition.
     */
    private function getDescription(AbstractSchemaDefinition $definition): ?string
    {
        $reflection = new \ReflectionClass($definition);
        $property = $reflection->getProperty('description');
        $property->setAccessible(true);
        return $property->getValue($definition);
    }

    /**
     * Format a value for display (not editing).
     */
    private function formatDisplayValue(AbstractSchemaDefinition $definition, mixed $value): string
    {
        if ($value === null) {
            return Terminal::DIM . '(empty)' . Terminal::RESET;
        }

        return match (true) {
            $definition instanceof BooleanSchemaDefinition => $value ? Terminal::GREEN . 'true' . Terminal::RESET : Terminal::RED . 'false' . Terminal::RESET,
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
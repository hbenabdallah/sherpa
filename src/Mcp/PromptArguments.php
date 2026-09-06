<?php

namespace App\Mcp;

/**
 * How a typed line becomes an MCP prompt's arguments, and its result a message.
 */
final class PromptArguments
{
    /**
     * name=value (quoted when it holds spaces) for any argument; the rest of
     * the line goes to the first argument still empty, so a prompt with one
     * argument takes the line as typed.
     *
     * @param array<int, array{name: string, description: string, required: bool}> $declared
     *
     * @return array<string, string>
     */
    public static function parse(string $line, array $declared): array
    {
        $names = array_column($declared, 'name');
        $values = [];

        $rest = preg_replace_callback(
            '/(?<=^|\s)([\w.-]+)=("([^"]*)"|\S*)/u',
            function (array $m) use ($names, &$values): string {
                if (!in_array($m[1], $names, true)) {
                    return $m[0];
                }
                $values[$m[1]] = isset($m[3]) && $m[2] !== '' && $m[2][0] === '"' ? $m[3] : $m[2];

                return '';
            },
            $line,
        ) ?? $line;

        $rest = trim((string) preg_replace('/\s+/u', ' ', $rest));
        if ($rest !== '') {
            foreach ($names as $name) {
                if (($values[$name] ?? '') === '') {
                    $values[$name] = $rest;
                    break;
                }
            }
        }

        return $values;
    }

    /**
     * @param array<int, array{name: string, description: string, required: bool}> $declared
     */
    public static function usage(array $declared): string
    {
        return implode(' ', array_map(
            static fn(array $argument) => $argument['required'] ? "<{$argument['name']}>" : "[{$argument['name']}]",
            $declared,
        ));
    }

    /**
     * The prompt's messages as one user message. Usually there is one, from
     * the user; any other is kept with its role, so nothing the server wrote
     * is lost.
     *
     * @param array<int, array{role: string, text: string}> $messages
     */
    public static function asMessage(array $messages): string
    {
        $messages = array_values(array_filter($messages, static fn(array $m) => trim($m['text']) !== ''));
        $onlyUser = array_filter($messages, static fn(array $m) => $m['role'] !== 'user') === [];

        return trim(implode("\n\n", array_map(
            static fn(array $m) => $onlyUser ? trim($m['text']) : "[{$m['role']}]\n" . trim($m['text']),
            $messages,
        )));
    }
}

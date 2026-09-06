<?php

declare(strict_types=1);

namespace App\Agent\Tool;

/**
 * Recovers tool calls that a model emitted as plain text instead of in the
 * structured `tool_calls` field.
 *
 * Small quantised local models routinely get this wrong. qwen2.5-coder:7b, for
 * example, is told by its template to wrap calls in <tool_call></tool_call> so
 * Ollama can parse them, and frequently emits the correct JSON payload with the
 * tags missing — at which point the call arrives as prose and the agent loop
 * mistakes it for a final answer.
 *
 * To keep this from firing on a model that is merely *discussing* JSON, a
 * candidate is only accepted when it has a string `name` naming a tool that
 * actually exists, and an object (or absent) `arguments`.
 */
final class TextToolCallParser
{
    /**
     * @param  string[] $knownToolNames
     * @return array<int, array{function: array{name: string, arguments: array}}>
     */
    public function parse(string $content, array $knownToolNames): array
    {
        if (trim($content) === '' || $knownToolNames === []) {
            return [];
        }

        $calls = [];
        $seen = [];

        foreach ($this->candidates($content) as $candidate) {
            $decoded = json_decode($candidate, true);
            if (!is_array($decoded)) {
                continue;
            }

            // Accept both {"name":..,"arguments":..} and {"tool":..,"args":..}.
            $name = $decoded['name'] ?? $decoded['tool'] ?? null;
            $arguments = $decoded['arguments'] ?? $decoded['args'] ?? [];

            if (!is_string($name) || !in_array($name, $knownToolNames, true)) {
                continue;
            }
            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true);
            }
            if (!is_array($arguments)) {
                continue;
            }

            // The same call can surface via several extraction strategies.
            $fingerprint = $name . '|' . json_encode($arguments);
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;

            $calls[] = ['function' => ['name' => $name, 'arguments' => $arguments]];
        }

        return $calls;
    }

    /**
     * JSON fragments worth attempting, most explicit first.
     *
     * @return string[]
     */
    private function candidates(string $content): array
    {
        $out = [];

        // 1. <tool_call>{...}</tool_call>, the shape the template asks for.
        if (preg_match_all('#<tool_call>(.*?)</tool_call>#s', $content, $m)) {
            foreach ($m[1] as $inner) {
                $out = array_merge($out, $this->objectsIn($inner));
            }
        }

        // 2. Fenced code blocks, where models like to park their JSON.
        if (preg_match_all('#```(?:json|tool_call)?\s*(.*?)```#s', $content, $m)) {
            foreach ($m[1] as $inner) {
                $out = array_merge($out, $this->objectsIn($inner));
            }
        }

        // 3. Bare braces anywhere in the text.
        return array_merge($out, $this->objectsIn($content));
    }

    /**
     * Extract balanced top-level {...} spans, ignoring braces inside strings.
     *
     * @return string[]
     */
    private function objectsIn(string $text): array
    {
        $objects = [];
        $depth = 0;
        $start = null;
        $inString = false;
        $escaped = false;

        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
            } elseif ($char === '}') {
                if ($depth > 0) {
                    $depth--;
                    if ($depth === 0 && $start !== null) {
                        $objects[] = substr($text, $start, $i - $start + 1);
                        $start = null;
                    }
                }
            }
        }

        return $objects;
    }
}

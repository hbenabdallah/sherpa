<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * Sherpa's history in the shape of Anthropic's Messages API, which differs
 * from the OpenAI dialect in ways the server enforces: the system prompt is a
 * field of its own, content is a list of blocks, a tool result is a block in a
 * user message, and roles alternate.
 *
 * Two things matter for cost and correctness beyond the shape:
 *  - Caching. Every turn resends the whole conversation; Anthropic only caches
 *    what is marked. The system block carries a breakpoint (tools and system
 *    together), and the top-level marker moves one along the conversation.
 *  - Thinking blocks. They are kept with the assistant message that produced
 *    them and sent back unchanged, but a block is bound to the history before
 *    it: once Sherpa rewrites that history (compaction), the older blocks are
 *    stripped. Removing a leading run of blocks is allowed; editing under
 *    them is not.
 */
final class AnthropicWire
{
    public const VERSION = '2023-06-01';

    /** Where Sherpa keeps an assistant message's thinking blocks, verbatim. */
    public const THINKING = 'anthropic_thinking';

    /** A reply needs room for the thinking that precedes it on current models. */
    public const MAX_TOKENS = 16000;

    public static function handles(string $baseUrl): bool
    {
        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

        return $host === 'anthropic.com' || str_ends_with($host, '.anthropic.com');
    }

    /** @return array<string, string> */
    public static function headers(string $key, string $accept): array
    {
        $headers = [
            'Content-Type'      => 'application/json',
            'Accept'            => $accept,
            'anthropic-version' => self::VERSION,
        ];

        if ($key !== '') {
            $headers['x-api-key'] = $key;
        }

        return $headers;
    }

    /**
     * No temperature: current models refuse sampling parameters. No thinking
     * setting either, so each model runs its own default.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools    OpenAI-style schemas
     * @param int                              $keepThinkingFrom messages before this index lose their thinking blocks
     *
     * @return array<string, mixed>
     */
    public static function payload(string $model, array $messages, array $tools, int $maxTokens, int $keepThinkingFrom = 0): array
    {
        [$system, $turns] = self::split($messages, $keepThinkingFrom);

        $payload = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'stream'     => true,
            // The moving breakpoint: each request reads what the last one wrote.
            'cache_control' => ['type' => 'ephemeral'],
        ];

        if ($system !== '') {
            $payload['system'] = [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]];
        }

        if ($tools !== []) {
            $payload['tools'] = array_map(self::tool(...), $tools);
        }

        $payload['messages'] = $turns;

        return $payload;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private static function split(array $messages, int $keepThinkingFrom): array
    {
        $system = '';
        $turns = [];

        foreach (array_values($messages) as $index => $message) {
            $role = (string) ($message['role'] ?? 'user');
            $content = (string) ($message['content'] ?? '');

            // The first system message is the system prompt; a later one is an
            // instruction for the model all the same, said in a user turn,
            // since not every model accepts system messages mid-conversation.
            if ($role === 'system') {
                if ($turns === [] && $system === '') {
                    $system = $content;
                } elseif (trim($content) !== '') {
                    self::append($turns, 'user', [['type' => 'text', 'text' => $content]]);
                }

                continue;
            }

            if ($role === 'tool') {
                self::append($turns, 'user', [[
                    'type'        => 'tool_result',
                    'tool_use_id' => (string) ($message['tool_call_id'] ?? ''),
                    'content'     => $content === '' ? '(no output)' : $content,
                ]]);

                continue;
            }

            if ($role === 'assistant') {
                $blocks = $index >= $keepThinkingFrom ? self::thinking($message) : [];

                if (trim($content) !== '') {
                    $blocks[] = ['type' => 'text', 'text' => $content];
                }

                foreach ($message['tool_calls'] ?? [] as $call) {
                    $arguments = $call['function']['arguments'] ?? [];
                    if (is_string($arguments)) {
                        $arguments = json_decode($arguments, true);
                    }

                    $blocks[] = [
                        'type'  => 'tool_use',
                        'id'    => (string) ($call['id'] ?? ''),
                        'name'  => (string) ($call['function']['name'] ?? ''),
                        'input' => is_array($arguments) && $arguments !== [] ? $arguments : new \stdClass(),
                    ];
                }

                if ($blocks !== []) {
                    self::append($turns, 'assistant', $blocks);
                }

                continue;
            }

            if (trim($content) !== '') {
                self::append($turns, 'user', [['type' => 'text', 'text' => $content]]);
            }
        }

        // The conversation opens on a user turn; a summary kept as an assistant
        // message would otherwise open it.
        if ($turns !== [] && $turns[0]['role'] !== 'user') {
            array_unshift($turns, ['role' => 'user', 'content' => [['type' => 'text', 'text' => '(earlier conversation, summarised)']]]);
        }

        return [$system, $turns];
    }

    /**
     * Roles alternate, so consecutive messages of one role become one turn.
     * Tool results lead their user turn, as the API requires.
     *
     * @param array<int, array<string, mixed>> $turns
     * @param array<int, array<string, mixed>> $blocks
     */
    private static function append(array &$turns, string $role, array $blocks): void
    {
        $last = array_key_last($turns);

        if ($last === null || $turns[$last]['role'] !== $role) {
            $turns[] = ['role' => $role, 'content' => $blocks];

            return;
        }

        $turns[$last]['content'] = array_merge($turns[$last]['content'], $blocks);

        if ($role === 'user') {
            usort($turns[$last]['content'], static fn(array $a, array $b) => ($b['type'] === 'tool_result') <=> ($a['type'] === 'tool_result'));
        }
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<int, array<string, mixed>>
     */
    private static function thinking(array $message): array
    {
        $blocks = $message[self::THINKING] ?? [];

        return is_array($blocks) ? array_values(array_filter($blocks, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $tool
     *
     * @return array<string, mixed>
     */
    private static function tool(array $tool): array
    {
        $function = $tool['function'] ?? $tool;
        $schema = $function['parameters'] ?? [];

        if (!is_array($schema) || $schema === []) {
            $schema = ['type' => 'object', 'properties' => new \stdClass()];
        }
        if (($schema['properties'] ?? null) === []) {
            $schema['properties'] = new \stdClass();
        }

        return [
            'name'         => (string) ($function['name'] ?? ''),
            'description'  => (string) ($function['description'] ?? ''),
            'input_schema' => $schema,
        ];
    }

    /**
     * Messages that are no longer the history the last request was built on:
     * returns the index up to which thinking blocks must now be dropped, or
     * null when $current still extends $previous.
     *
     * @param array<int, array<string, mixed>> $previous
     * @param array<int, array<string, mixed>> $current
     */
    public static function rewrittenUpTo(array $previous, array $current): ?int
    {
        $previous = array_values($previous);
        $current = array_values($current);

        if (count($current) < count($previous)) {
            return count($current);
        }

        foreach ($previous as $i => $message) {
            if (self::fingerprint($message) !== self::fingerprint($current[$i])) {
                return count($current);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $message */
    private static function fingerprint(array $message): string
    {
        return hash('xxh128', (string) json_encode($message));
    }

    /**
     * Anthropic's list gives the window as max_input_tokens.
     *
     * @param array<string, mixed> $entry
     */
    public static function model(array $entry): ModelInfo
    {
        $window = $entry['max_input_tokens'] ?? null;

        return new ModelInfo(
            name: (string) $entry['id'],
            contextLength: is_numeric($window) ? (int) $window : null,
        );
    }

    /** The 400 that says thinking blocks no longer match their history. */
    public static function isStaleThinking(string $body): bool
    {
        return str_contains($body, 'thinking') && (str_contains($body, 'signature') || str_contains($body, 'different conversation'));
    }
}

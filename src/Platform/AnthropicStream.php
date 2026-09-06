<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * One streamed reply of Anthropic's Messages API, read event by event: text is
 * handed on as it comes, tool calls and thinking blocks are assembled from
 * their deltas, and the usage adds up what the server read from its cache.
 */
final class AnthropicStream
{
    private string $content = '';

    /** @var array<int, array<string, mixed>> blocks by index, as they are built */
    private array $blocks = [];

    /** @var array<int, string> tool input JSON, by block index */
    private array $inputs = [];

    private int $input = 0;
    private int $cacheRead = 0;
    private int $cacheWrite = 0;
    private int $output = 0;
    private bool $counted = false;

    private ?string $stopReason = null;
    private ?float $firstTokenAt = null;

    /**
     * @param (callable(string): void)|null $onToken
     * @param (callable(bool): void)|null   $onWait
     */
    public function __construct(
        private readonly mixed $onToken = null,
        private readonly mixed $onWait = null,
    ) {}

    /**
     * One SSE line. The `event:` lines only repeat the type the data carries.
     *
     * @throws \RuntimeException on an error event, mid-stream
     */
    public function line(string $line): void
    {
        $line = trim($line);
        if (!str_starts_with($line, 'data:')) {
            return;
        }

        $event = json_decode(trim(substr($line, 5)), true);
        if (!is_array($event)) {
            return;
        }

        match ($event['type'] ?? '') {
            'message_start'       => $this->usage($event['message']['usage'] ?? []),
            'content_block_start' => $this->start((int) ($event['index'] ?? 0), $event['content_block'] ?? []),
            'content_block_delta' => $this->delta((int) ($event['index'] ?? 0), $event['delta'] ?? []),
            'message_delta'       => $this->end($event),
            'error'               => throw new \RuntimeException((string) ($event['error']['message'] ?? 'the stream reported an error')),
            default               => null,
        };
    }

    /** @param array<string, mixed> $usage */
    private function usage(array $usage): void
    {
        $this->counted = $this->counted || $usage !== [];
        $this->input = max($this->input, (int) ($usage['input_tokens'] ?? 0));
        $this->cacheRead = max($this->cacheRead, (int) ($usage['cache_read_input_tokens'] ?? 0));
        $this->cacheWrite = max($this->cacheWrite, (int) ($usage['cache_creation_input_tokens'] ?? 0));
        $this->output = max($this->output, (int) ($usage['output_tokens'] ?? 0));
    }

    /** @param array<string, mixed> $block */
    private function start(int $index, array $block): void
    {
        $this->blocks[$index] = $block;

        $type = $block['type'] ?? '';
        if ($type === 'thinking' || $type === 'redacted_thinking') {
            // Generation has begun, even with nothing to show.
            $this->firstTokenAt ??= microtime(true);
            if ($this->onWait !== null) {
                ($this->onWait)(true);
            }
        } elseif ($type === 'tool_use') {
            $this->firstTokenAt ??= microtime(true);
            $this->inputs[$index] = '';
        } elseif ($type === 'text' && is_string($block['text'] ?? null) && $block['text'] !== '') {
            $this->text($block['text']);
            $this->blocks[$index]['text'] = '';
        }
    }

    /** @param array<string, mixed> $delta */
    private function delta(int $index, array $delta): void
    {
        match ($delta['type'] ?? '') {
            'text_delta'       => $this->text((string) ($delta['text'] ?? '')),
            'input_json_delta' => $this->inputs[$index] = ($this->inputs[$index] ?? '') . (string) ($delta['partial_json'] ?? ''),
            'thinking_delta'   => $this->blocks[$index]['thinking'] = ($this->blocks[$index]['thinking'] ?? '') . (string) ($delta['thinking'] ?? ''),
            'signature_delta'  => $this->blocks[$index]['signature'] = ($this->blocks[$index]['signature'] ?? '') . (string) ($delta['signature'] ?? ''),
            default            => null,
        };
    }

    private function text(string $text): void
    {
        if ($text === '') {
            return;
        }

        $this->firstTokenAt ??= microtime(true);
        $this->content .= $text;

        if ($this->onToken !== null) {
            ($this->onToken)($text);
        }
    }

    /** @param array<string, mixed> $event */
    private function end(array $event): void
    {
        $this->stopReason = $event['delta']['stop_reason'] ?? $this->stopReason;
        $this->usage(is_array($event['usage'] ?? null) ? $event['usage'] : []);
    }

    /**
     * The reply in the shape of the platform interface, thinking blocks kept
     * aside to be sent back with it.
     *
     * @return array<string, mixed>
     */
    public function message(): array
    {
        $content = $this->content;

        if ($this->stopReason === 'refusal' && trim($content) === '') {
            $content = 'The model declined this request (refusal).';
        }

        $message = ['role' => 'assistant', 'content' => $content];

        ksort($this->blocks);
        $calls = [];
        $thinking = [];

        foreach ($this->blocks as $index => $block) {
            $type = $block['type'] ?? '';

            if ($type === 'tool_use') {
                $raw = $this->inputs[$index] ?? '';
                $decoded = json_decode($raw === '' ? '{}' : $raw, true);

                $calls[] = [
                    'id'       => (string) ($block['id'] ?? ''),
                    'function' => ['name' => (string) ($block['name'] ?? ''), 'arguments' => is_array($decoded) ? $decoded : $raw],
                ];
            } elseif ($type === 'thinking' || $type === 'redacted_thinking') {
                $thinking[] = $block;
            }
        }

        if ($calls !== []) {
            $message['tool_calls'] = $calls;
        }
        if ($thinking !== []) {
            $message[AnthropicWire::THINKING] = $thinking;
        }

        return $message;
    }

    /**
     * The whole prompt, whether it was read from the cache, written to it or
     * neither: that is what the window holds.
     *
     * @return array{prompt: int, completion: int, cached?: int}|null
     */
    public function lastUsage(): ?array
    {
        if (!$this->counted) {
            return null;
        }

        $usage = [
            'prompt'     => $this->input + $this->cacheRead + $this->cacheWrite,
            'completion' => $this->output,
        ];

        if ($this->cacheRead > 0) {
            $usage['cached'] = $this->cacheRead;
        }

        return $usage;
    }

    public function firstTokenAt(): ?float
    {
        return $this->firstTokenAt;
    }

    public function stopReason(): ?string
    {
        return $this->stopReason;
    }
}

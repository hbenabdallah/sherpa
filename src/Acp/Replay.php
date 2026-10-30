<?php

declare(strict_types=1);

namespace App\Acp;

use App\Agent\PlanMode;
use App\TUI\Terminal;
use stdClass;

/**
 * A saved conversation sent back to an editor that loads it, as session
 * updates in the order it happened: what was asked, what was answered, the
 * tools in between with what they returned.
 */
final readonly class Replay
{
    /** A tool's result, replayed: enough to recognise it, the model keeps the rest. */
    private const int RESULT_CHARS = 2_000;

    public function __construct(private Connection $connection, private string $sessionId) {}

    /** @param list<array<string, mixed>> $messages */
    public function send(array $messages): void
    {
        foreach ($messages as $index => $message) {
            $content = is_string($message['content'] ?? null) ? $message['content'] : '';

            match ($message['role'] ?? '') {
                'user'      => $this->chunk('user_message_chunk', "user_{$index}", PlanMode::unframe($content)),
                'assistant' => $this->assistant("agent_{$index}", $content, is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : []),
                'tool'      => $this->result($message, $content),
                default     => null,
            };
        }
    }

    /** @param array<int, mixed> $calls */
    private function assistant(string $messageId, string $content, array $calls): void
    {
        $this->chunk('agent_message_chunk', $messageId, $content);

        foreach ($calls as $call) {
            $id = is_array($call) && is_string($call['id'] ?? null) ? $call['id'] : null;
            $name = is_array($call) ? (string) ($call['function']['name'] ?? '') : '';
            if ($id === null || $name === '') {
                continue;
            }

            $args = $call['function']['arguments'] ?? [];
            $args = is_string($args) ? (json_decode($args, true) ?? []) : $args;
            $args = is_array($args) ? $args : [];

            $this->update([
                'sessionUpdate' => 'tool_call',
                'toolCallId'    => $id,
                'title'         => ToolDescriptions::title($name, $args),
                'kind'          => ToolDescriptions::kind($name),
                'status'        => 'completed',
                'rawInput'      => $args === [] ? new stdClass() : $args,
            ]);
        }
    }

    /** @param array<string, mixed> $message */
    private function result(array $message, string $content): void
    {
        if (!is_string($message['tool_call_id'] ?? null)) {
            return;
        }

        $text = Terminal::plain($content);
        if (mb_strlen($text) > self::RESULT_CHARS) {
            $text = mb_substr($text, 0, self::RESULT_CHARS) . '…';
        }

        $this->update([
            'sessionUpdate' => 'tool_call_update',
            'toolCallId'    => $message['tool_call_id'],
            'status'        => 'completed',
            'content'       => [['type' => 'content', 'content' => ['type' => 'text', 'text' => $text]]],
        ]);
    }

    private function chunk(string $kind, string $messageId, string $text): void
    {
        if (trim($text) === '') {
            return;
        }

        $this->update(['sessionUpdate' => $kind, 'messageId' => $messageId, 'content' => ['type' => 'text', 'text' => $text]]);
    }

    /** @param array<string, mixed> $update */
    private function update(array $update): void
    {
        $this->connection->notify('session/update', ['sessionId' => $this->sessionId, 'update' => $update]);
    }
}

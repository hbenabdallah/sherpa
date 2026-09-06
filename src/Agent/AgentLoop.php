<?php

namespace App\Agent;

use App\Agent\Tool\TextToolCallParser;
use App\Agent\Tool\ToolCall;
use App\Agent\Tool\Toolbox;
use App\Permission\PermissionBroker;
use App\Platform\PlatformInterface;
use App\Runtime\Interrupt;
use App\TUI\ChatPane;

class AgentLoop
{
    private const MAX_ITERATIONS = 10;
    private const INTERRUPTED = 'Interrompu par l\'utilisateur.';

    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly Toolbox $toolbox,
        private readonly PermissionBroker $permissions,
        private readonly ChatPane $chat,
        private readonly ContextBudget $budget,
        private readonly HistoryCompactor $compactor,
        private readonly Interrupt $interrupt,
        private readonly TextToolCallParser $textToolCalls = new TextToolCallParser(),
    ) {}

    public function run(MessageBag $bag): Result
    {
        $toolSchemas = array_map(
            fn($def) => $def->toFunctionSchema(),
            $this->toolbox->getDefinitions(),
        );

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            // Compact before sending, not after. Overflowing num_ctx is silent:
            // llama.cpp drops tokens from the front of the prompt, taking the
            // system prompt and project memory with them.
            $this->keepWithinBudget($bag, $toolSchemas);

            $buffer = '';
            $this->chat->beginAssistantMessage();

            // Stream text tokens, buffer for tool-call detection
            $message = $this->platform->stream(
                messages: $bag->all(),
                tools: $toolSchemas,
                onToken: function (string $token) use (&$buffer) {
                    $this->chat->appendToken($token);
                    $buffer .= $token;
                },
            );

            // Ollama reports the true prompt size; use it to sharpen the
            // character-based estimate for the next turn.
            $usage = $this->platform->lastUsage();
            if ($usage !== null) {
                $this->budget->calibrate($usage['prompt'], $bag->all(), $toolSchemas);
            }

            $content = $message['content'] ?? $buffer;

            // Small local models often emit a tool call as prose instead of in
            // the structured field — qwen2.5-coder:7b drops the <tool_call> tags
            // Ollama needs to parse it. Recover those before concluding that a
            // reply with no tool_calls is a final answer.
            $recoveredFromText = false;
            if (empty($message['tool_calls'])) {
                $recovered = $this->textToolCalls->parse(
                    $content,
                    array_map(fn($def) => $def->name, $this->toolbox->getDefinitions()),
                );

                if ($recovered !== []) {
                    $message['tool_calls'] = $recovered;
                    $recoveredFromText = true;
                }
            }

            // Flushed only now: the pane withholds a reply that opens like a tool
            // call, and the verdict on that is what was just computed.
            $this->chat->flushBuffer(wasToolCall: $recoveredFromText);

            // Cancelled mid-reply. The partial text is deliberately not written
            // to history: it may be half a tool call, and a truncated turn is
            // better left out than left in as something the model must explain.
            if ($this->interrupt->requested()) {
                return new Result(self::INTERRUPTED, iterations: $i + 1, interrupted: true);
            }

            // No tool calls → final answer
            if (empty($message['tool_calls'])) {
                $bag->assistant($content);
                return new Result($content, iterations: $i + 1);
            }

            // Add the assistant message (with tool calls) to history
            $bag->add($message);

            // Execute each tool call
            foreach ($message['tool_calls'] as $rawCall) {
                $fn = $rawCall['function'];
                $args = $fn['arguments'] ?? [];
                if (is_string($args)) {
                    $args = json_decode($args, true) ?? [];
                }

                $call = new ToolCall(
                    id: 'call_' . bin2hex(random_bytes(4)),
                    name: $fn['name'],
                    arguments: $args,
                );

                // Once the assistant message carrying tool_calls is in history,
                // every one of them needs a matching tool message or the next
                // request is malformed. Cancelled calls get a stub, not a gap.
                if ($this->interrupt->requested()) {
                    $bag->tool($call->name, self::INTERRUPTED);
                    continue;
                }

                $def = $this->toolbox->find($call->name);
                $this->chat->showToolCall($call->name, $args);

                $allowed = $this->permissions->check($call, $def);

                if (!$allowed) {
                    $result = new \App\Agent\Tool\ToolResult($call->id, 'Refusé par l\'utilisateur.', isError: true);
                } else {
                    $result = $this->toolbox->execute($call);
                }

                $this->chat->showToolResult($call->name, $result->content, $result->isError);

                $bag->tool($call->name, $result->content);
            }

            if ($this->interrupt->requested()) {
                return new Result(self::INTERRUPTED, iterations: $i + 1, interrupted: true);
            }
        }

        return new Result(
            'Nombre maximum d\'itérations atteint.',
            iterations: self::MAX_ITERATIONS,
            maxIterationsReached: true,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $toolSchemas
     */
    private function keepWithinBudget(MessageBag $bag, array $toolSchemas): void
    {
        if (!$this->budget->needsCompaction($bag->all(), $toolSchemas)) {
            return;
        }

        $before = $this->budget->estimateRequest($bag->all(), $toolSchemas);
        $actions = $this->compactor->compact($bag, $toolSchemas);

        if ($actions === []) {
            return;
        }

        $after = $this->budget->estimateRequest($bag->all(), $toolSchemas);

        $this->chat->showInfo(sprintf(
            'Contexte compacté (%s) : ~%d → ~%d tokens.',
            implode(', ', $actions),
            $before,
            $after,
        ));
    }
}

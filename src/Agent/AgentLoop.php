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
    /**
     * The hard stop, and only that.
     *
     * This was ten, which on a machine without a graphics card was a mercy: ten
     * turns at three minutes each is half an hour, and someone had to be let
     * out. But it was capping *work*, not catching a fault — grep, read, read,
     * patch, run the tests, read the failure, patch, run them again, confirm is
     * already nine steps of an ordinary request, and the tenth turn arrived as
     * "limite d'itérations atteinte" on a job half done.
     *
     * A model genuinely stuck repeats itself, so that is detected directly below
     * and caught within three turns on any machine. This number goes back to
     * being what it should always have been: the value at which something has
     * gone wrong in a way nobody anticipated.
     */
    private const MAX_ITERATIONS = 40;

    /**
     * Identical tool calls this many turns running means the model is circling
     * rather than working — re-reading the same file, re-running the same
     * failing command — and no further turn is going to tell it anything new.
     */
    private const REPEATED_CALL_LIMIT = 3;

    private const INTERRUPTED = 'Interrompu par l\'utilisateur.';
    private const STUCK = 'Arrêté : le modèle a répété le même appel d\'outil sans progresser.';

    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly Toolbox $toolbox,
        private readonly PermissionBroker $permissions,
        private readonly ChatPane $chat,
        private readonly ContextBudget $budget,
        private readonly HistoryCompactor $compactor,
        private readonly Interrupt $interrupt,
        private readonly TextToolCallParser $textToolCalls = new TextToolCallParser(),
        // Fed from the platform's own timings below, so every policy that used
        // to assume a machine can measure one instead.
        private readonly ?InferenceSpeed $speed = null,
    ) {}

    public function run(MessageBag $bag): Result
    {
        $toolSchemas = array_map(
            fn($def) => $def->toFunctionSchema(),
            $this->toolbox->getDefinitions(),
        );

        $lastFingerprint = null;
        $repeats = 0;

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

            // The same final chunk carries the durations. Nothing here needs
            // them, which is exactly why they were being dropped — the callers
            // that do are the ones deciding what this machine can afford.
            $timings = $this->platform->lastTimings();
            if ($timings !== null) {
                $this->speed?->observe($timings['prefill'], $timings['decode']);
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

            $fingerprint = $this->fingerprint($message['tool_calls']);
            $repeats = $fingerprint === $lastFingerprint ? $repeats + 1 : 1;
            $lastFingerprint = $fingerprint;

            // Add the assistant message (with tool calls) to history
            $bag->add($message);

            // Circling. The stubs matter as much as the stop: the assistant
            // message carrying these calls is now in history, and every one of
            // them needs a matching tool message or the next request — the one
            // the user types after reading the warning — is malformed.
            if ($repeats >= self::REPEATED_CALL_LIMIT) {
                foreach ($message['tool_calls'] as $rawCall) {
                    $bag->tool((string) ($rawCall['function']['name'] ?? 'tool'), self::STUCK);
                }

                return new Result(self::STUCK, iterations: $i + 1, repetitionDetected: true);
            }

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
     * A stable identity for one turn's set of tool calls.
     *
     * Arguments are sorted by key and the calls themselves are sorted, so the
     * same two reads requested in the other order still count as the same
     * attempt — a model going round in circles rarely does so in a tidy order.
     *
     * @param array<int, array<string, mixed>> $toolCalls
     */
    private function fingerprint(array $toolCalls): string
    {
        $parts = [];

        foreach ($toolCalls as $call) {
            $function = $call['function'] ?? [];
            $arguments = $function['arguments'] ?? [];

            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true) ?? $arguments;
            }
            if (is_array($arguments)) {
                ksort($arguments);
            }

            $parts[] = ($function['name'] ?? '?') . '(' . json_encode($arguments) . ')';
        }

        sort($parts);

        return implode('|', $parts);
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

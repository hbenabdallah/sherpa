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
     * The hard stop, and only that. Ten capped work rather than caught a fault:
     * grep, read, read, patch, test, read, patch, test, confirm is nine steps
     * of an ordinary request. A model genuinely stuck repeats itself, which is
     * detected below within three turns.
     */
    private const MAX_ITERATIONS = 40;

    /**
     * Identical tool calls this many turns running means the model is circling
     * rather than working — re-reading the same file, re-running the same
     * failing command — and no further turn is going to tell it anything new.
     */
    private const REPEATED_CALL_LIMIT = 3;

    private const INTERRUPTED = 'Interrompu par l\'utilisateur.';
    private const STUCK = 'Stopped: the model repeated the same tool call without getting anywhere.';
    private const ABANDONED = 'Not run: the turn stopped on an error before this call.';
    private const EMPTY_RETRY = 'Empty reply from the model: trying again.';
    private const EMPTY = 'The model returned two empty replies in a row. Rephrase the request, or change model with /model.';

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
        // Watches how the model goes looking for code, so that question gets
        // the same kind of number the memory side already has.
        private readonly ?SearchTrace $trace = null,
    ) {}

    /**
     * The turn boundary lives here rather than around each return: this method
     * leaves by six paths — an answer, two interruptions, a repetition, the
     * cap, an exception — and a trace closing on some of them measures the
     * others as never having happened. Unanswered calls are answered here too,
     * before that history goes anywhere.
     */
    public function run(MessageBag $bag): Result
    {
        $this->trace?->beginTurn();

        try {
            return $this->runTurn($bag);
        } finally {
            $bag->closeOpenToolCalls(self::ABANDONED);
            $this->trace?->endTurn();
        }
    }

    private function runTurn(MessageBag $bag): Result
    {
        $toolSchemas = $this->toolbox->schemas();

        $lastFingerprint = null;
        $repeats = 0;
        $retriedEmpty = false;

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
                onWait: fn(bool $reasoning) => $this->chat->showWaiting($reasoning),
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

            // No tool calls → final answer, unless there is nothing in it: a
            // reasoning model can send back no text and no call, which used to
            // end the turn in silence. Asked again once, then said plainly.
            if (empty($message['tool_calls'])) {
                if (trim($content) === '') {
                    if (!$retriedEmpty) {
                        $retriedEmpty = true;
                        $this->chat->showInfo(self::EMPTY_RETRY);
                        continue;
                    }

                    $this->chat->showError(self::EMPTY);

                    return new Result(self::EMPTY, iterations: $i + 1);
                }

                $bag->assistant($content);
                return new Result($content, iterations: $i + 1);
            }

            $message['tool_calls'] = $this->normaliseToolCalls($message['tool_calls']);

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
                    $bag->tool((string) ($rawCall['function']['name'] ?? 'tool'), self::STUCK, $rawCall['id']);
                }

                return new Result(self::STUCK, iterations: $i + 1, repetitionDetected: true);
            }

            // Execute each tool call
            foreach ($message['tool_calls'] as $rawCall) {
                $fn = $rawCall['function'];
                $args = $fn['arguments'];

                $call = new ToolCall(
                    id: $rawCall['id'],
                    name: $fn['name'],
                    arguments: $args,
                );

                // Once the assistant message carrying tool_calls is in history,
                // every one of them needs a matching tool message or the next
                // request is malformed. Cancelled calls get a stub, not a gap.
                if ($this->interrupt->requested()) {
                    $bag->tool($call->name, self::INTERRUPTED, $call->id);
                    continue;
                }

                $def = $this->toolbox->find($call->name);
                $this->chat->showToolCall($call->name, $args);

                $allowed = $this->permissions->check($call, $def);

                if (!$allowed) {
                    $result = new \App\Agent\Tool\ToolResult($call->id, 'Refused by the user.', isError: true);
                } else {
                    $result = $this->toolbox->execute($call);
                }

                $this->chat->showToolResult($call->name, $result->content, $result->isError);
                $this->trace?->observe($call->name, $result->isError);

                $bag->tool($call->name, $result->content, $call->id);
            }

            if ($this->interrupt->requested()) {
                return new Result(self::INTERRUPTED, iterations: $i + 1, interrupted: true);
            }
        }

        return new Result(
            'Maximum number of iterations reached.',
            iterations: self::MAX_ITERATIONS,
            maxIterationsReached: true,
        );
    }

    /**
     * Put tool calls in the shape the history contract promises. An id on every
     * call, since a hosted API matches results to calls by id: Ollama sends
     * none, so one is made up and kept, nine alphanumeric characters because
     * that is the narrowest format any backend insists on (Mistral's).
     * Arguments are decoded once here, or a streamed string stays a string.
     *
     * @param array<int, array<string, mixed>> $toolCalls
     *
     * @return array<int, array{id: string, function: array{name: string, arguments: array<string, mixed>}}>
     */
    private function normaliseToolCalls(array $toolCalls): array
    {
        $normalised = [];

        foreach (array_values($toolCalls) as $call) {
            $arguments = $call['function']['arguments'] ?? [];
            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true);
            }

            $id = $call['id'] ?? null;

            $call['id'] = is_string($id) && $id !== '' ? $id : self::newCallId();
            $call['function']['name'] = (string) ($call['function']['name'] ?? '');
            $call['function']['arguments'] = is_array($arguments) ? $arguments : [];

            $normalised[] = $call;
        }

        return $normalised;
    }

    private static function newCallId(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $id = '';

        for ($i = 0; $i < 9; $i++) {
            $id .= $alphabet[random_int(0, 61)];
        }

        return $id;
    }

    /**
     * A stable identity for one turn's tool calls: arguments and calls are both
     * sorted, so the same two reads in another order count as the same attempt
     * — a model going in circles rarely does so tidily.
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
            'Context compacted (%s): ~%d → ~%d tokens.',
            implode(', ', $actions),
            $before,
            $after,
        ));
    }
}

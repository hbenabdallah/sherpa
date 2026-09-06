<?php

namespace App\Agent;

use App\Agent\Tool\TextToolCallParser;
use App\Agent\Tool\ToolCall;
use App\Agent\Tool\ToolResult;
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

    private const INTERRUPTED = 'Interrupted by the user.';
    private const STUCK = 'Stopped: the model repeated the same tool call without getting anywhere.';
    private const ABANDONED = 'Not run: the turn stopped on an error before this call.';
    private const EMPTY_RETRY = 'Empty reply from the model: trying again.';
    /** Longer than this, a reply is an answer, whatever it says about tools. */
    private const ANNOUNCEMENT_MAX_CHARS = 800;

    private const NUDGE_SHOWN = 'The model described a tool call without making it: asking it to go ahead.';
    private const NUDGE = 'You described what you are going to do but did not call the tool. Call it now, through the tool interface, rather than describing it.';
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
        $state = new TurnState();

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $iteration = $i + 1;

            // Compact before sending, not after. Overflowing num_ctx is silent:
            // llama.cpp drops tokens from the front of the prompt, taking the
            // system prompt and project memory with them.
            $this->keepWithinBudget($bag, $toolSchemas);

            [$message, $content] = $this->ask($bag, $toolSchemas);

            // Cancelled mid-reply. The partial text is deliberately not written
            // to history: it may be half a tool call, and a truncated turn is
            // better left out than left in as something the model must explain.
            if ($this->interrupt->requested()) {
                return new Result(self::INTERRUPTED, iterations: $iteration, interrupted: true);
            }

            if (empty($message['tool_calls'])) {
                $result = $this->conclude($bag, $message, $content, $state, $iteration);
                if ($result === null) {
                    continue;
                }

                return $result;
            }

            $message['tool_calls'] = $this->normaliseToolCalls($message['tool_calls']);
            $bag->add($message);

            $stuck = $this->checkRepetition($bag, $message['tool_calls'], $content, $state, $iteration);
            if ($stuck !== null) {
                return $stuck;
            }

            $this->runCalls($bag, $message['tool_calls']);

            if ($this->interrupt->requested()) {
                return new Result(self::INTERRUPTED, iterations: $iteration, interrupted: true);
            }
        }

        return new Result(
            'Maximum number of iterations reached.',
            iterations: self::MAX_ITERATIONS,
            maxIterationsReached: true,
        );
    }

    /**
     * One request: the reply streamed to the screen, what it teaches about
     * this machine and model, and tool calls recovered from its text.
     *
     * @param array<int, array<string, mixed>> $toolSchemas
     *
     * @return array{0: array<string, mixed>, 1: string} the message, and its text
     */
    private function ask(MessageBag $bag, array $toolSchemas): array
    {
        $buffer = '';
        $this->chat->beginAssistantMessage();

        $message = $this->platform->stream(
            messages: $bag->all(),
            tools: $toolSchemas,
            onToken: function (string $token) use (&$buffer) {
                $this->chat->appendToken($token);
                $buffer .= $token;
            },
            onWait: fn(bool $reasoning) => $this->chat->showWaiting($reasoning),
        );

        $this->learnFrom($bag, $toolSchemas);

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
                $this->signatures(),
            );

            if ($recovered !== []) {
                $message['tool_calls'] = $recovered;
                $recoveredFromText = true;
            }
        }

        // Flushed only now: the pane withholds a reply that opens like a tool
        // call, and the verdict on that is what was just computed.
        $this->chat->flushBuffer(wasToolCall: $recoveredFromText);

        return [$message, $content];
    }

    /**
     * What the reply's final chunk reported, put to use: the true prompt size
     * sharpens the character-based estimate, the durations measure this
     * machine for the callers deciding what it can afford.
     *
     * @param array<int, array<string, mixed>> $toolSchemas
     */
    private function learnFrom(MessageBag $bag, array $toolSchemas): void
    {
        $usage = $this->platform->lastUsage();
        if ($usage !== null) {
            $this->budget->calibrate($usage['prompt'], $bag->all(), $toolSchemas);
        }

        $timings = $this->platform->lastTimings();
        if ($timings !== null) {
            $this->speed?->observe($timings['prefill'], $timings['decode']);
        }
    }

    /**
     * A reply with no tool call: the answer, unless it is empty or only
     * announces a call — each of which gets one more request.
     *
     * @param array<string, mixed> $message
     *
     * @return Result|null null to ask the model again
     */
    private function conclude(MessageBag $bag, array $message, string $content, TurnState $state, int $iteration): ?Result
    {
        // A reasoning model can send back no text and no call. Asked again
        // once, then said plainly rather than ending in silence.
        if (trim($content) === '') {
            if (!$state->retriedEmpty) {
                $state->retriedEmpty = true;
                $this->chat->showInfo(self::EMPTY_RETRY);

                return null;
            }

            $this->chat->showError(self::EMPTY);

            return new Result(self::EMPTY, iterations: $iteration);
        }

        // What a backend attached to its reply stays with it — Anthropic's
        // thinking blocks, which must go back unchanged next turn.
        unset($message['tool_calls']);
        $bag->add(['role' => 'assistant', 'content' => $content] + $message);

        // "I'll start by listing the project with list_dir." — and the turn
        // ends there, the call never made. Asked to go ahead, once.
        if (!$state->nudged && $this->announcesToolCall($content)) {
            $state->nudged = true;
            $this->chat->showInfo(self::NUDGE_SHOWN);
            $bag->user(self::NUDGE);

            return null;
        }

        return new Result($content, iterations: $iteration);
    }

    /**
     * The same calls again, REPEATED_CALL_LIMIT times running: the model is
     * circling, and the turn stops. The calls are already in history.
     *
     * @param array<int, array<string, mixed>> $calls
     */
    private function checkRepetition(MessageBag $bag, array $calls, string $content, TurnState $state, int $iteration): ?Result
    {
        $fingerprint = $this->fingerprint($calls);
        $state->repeats = $fingerprint === $state->lastFingerprint ? $state->repeats + 1 : 1;
        $state->lastFingerprint = $fingerprint;

        // Some models write the answer and a tool call in the same message,
        // then keep making that call. The answer is the part worth keeping.
        if ($state->repeats === 1) {
            $state->saidWhileRepeating = '';
        }
        if (trim($content) !== '') {
            $state->saidWhileRepeating = $content;
        }

        if ($state->repeats < self::REPEATED_CALL_LIMIT) {
            return null;
        }

        // The stubs matter as much as the stop: every call in history needs a
        // matching tool message, or the next request — the one the user types
        // after reading the warning — is malformed.
        foreach ($calls as $rawCall) {
            $bag->tool((string) ($rawCall['function']['name'] ?? 'tool'), self::STUCK, $rawCall['id']);
        }

        // Already on screen; written again as a plain reply so the history
        // ends on it, as it would after any answer, and the next turn does
        // not read the question as still open.
        if ($state->saidWhileRepeating !== '') {
            $bag->assistant($state->saidWhileRepeating);

            return new Result($state->saidWhileRepeating, iterations: $iteration, repetitionDetected: true, repliedBeforeStop: true);
        }

        return new Result(self::STUCK, iterations: $iteration, repetitionDetected: true);
    }

    /** @param array<int, array<string, mixed>> $calls */
    private function runCalls(MessageBag $bag, array $calls): void
    {
        foreach ($calls as $rawCall) {
            $call = new ToolCall(
                id: $rawCall['id'],
                name: $rawCall['function']['name'],
                arguments: $rawCall['function']['arguments'],
            );

            // Every call in history needs a matching tool message or the next
            // request is malformed. Cancelled calls get a stub, not a gap.
            if ($this->interrupt->requested()) {
                $bag->tool($call->name, self::INTERRUPTED, $call->id);
                continue;
            }

            $this->runCall($bag, $call);
        }
    }

    /** One call: shown, asked for when it must be, run, its result into history. */
    private function runCall(MessageBag $bag, ToolCall $call): void
    {
        $def = $this->toolbox->find($call->name);
        $this->chat->showToolCall($call->name, $call->arguments);

        $result = $this->permissions->check($call, $def)
            ? $this->toolbox->execute($call)
            : new ToolResult($call->id, 'Refused by the user.', isError: true);

        $this->chat->showToolResult($call->name, $result->content, $result->isError);
        $this->trace?->observe($call->name, $result->isError);

        $bag->tool($call->name, $result->content, $call->id);
    }

    /**
     * A reply that says it is about to use a tool, by name, and stops there:
     * "I'll use list_dir to look at the layout." Kept narrow, since a false
     * alarm costs a turn and reads as Sherpa not listening:
     *  - short — a real answer runs longer than any announcement seen;
     *  - the intent and the tool's name in one sentence — an answer that
     *    discusses tools and ends on "let me know" is not an announcement.
     */
    private function announcesToolCall(string $content): bool
    {
        if (mb_strlen(trim($content)) > self::ANNOUNCEMENT_MAX_CHARS) {
            return false;
        }

        $names = array_map(fn($def) => preg_quote($def->name, '/'), $this->toolbox->getDefinitions());
        if ($names === []) {
            return false;
        }

        $tool = '/(?<![\\w-])(' . implode('|', $names) . ')(?![\\w-])/';
        $intent = "/\\b(je vais|je commence|commençons|let me(?! know| explain)|i'll|i will|i am going to|i'm going to|next,? i|now i)\\b/iu";

        foreach (preg_split('/(?<=[.!?])\\s+|\\n+/u', $content) ?: [] as $sentence) {
            if (preg_match($tool, $sentence) === 1 && preg_match($intent, $sentence) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Each tool's parameters, for calls written as bare arguments.
     *
     * @return array<string, array{params: list<string>, required: list<string>}>
     */
    private function signatures(): array
    {
        $signatures = [];
        foreach ($this->toolbox->getDefinitions() as $def) {
            $params = array_map('strval', array_keys($def->parameters));
            $required = array_values(array_filter($params, fn(string $p) => (bool) ($def->parameters[$p]['required'] ?? false)));
            $signatures[$def->name] = ['params' => $params, 'required' => $required];
        }

        return $signatures;
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

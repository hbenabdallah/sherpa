<?php

namespace App\Agent;

use App\Agent\Tool\TextToolCallParser;
use App\Agent\Tool\Toolbox;
use App\Agent\Tool\ToolCall;
use App\Agent\Tool\ToolResult;
use App\Permission\PermissionBroker;
use App\Platform\PlatformInterface;
use App\Runtime\Interrupt;
use App\Usage\UsageMeter;
use Fiber;
use Throwable;

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

    /**
     * The same error this many results running, whatever the calls: the model
     * is trying things against a wall — a stopped container answered every
     * command, docker compose up and echo alike, with the same line.
     */
    private const REPEATED_ERROR_LIMIT = 3;

    private const INTERRUPTED = 'Interrupted by the user.';

    private const REFUSED = 'Refused by the user.';

    private const STUCK = 'Stopped: the model repeated the same tool call without getting anywhere.';

    private const WALLED = 'Stopped: every call came back with the same error, %d times running: %s';

    private const ABANDONED = 'Not run: the turn stopped on an error before this call.';

    private const EMPTY_RETRY = 'Empty reply from the model: trying again.';

    /** Longer than this, a reply is an answer, whatever it says about tools. */
    private const ANNOUNCEMENT_MAX_CHARS = 800;

    private const NUDGE_SHOWN = 'The model described a tool call without making it: asking it to go ahead.';

    /** Said for a call whose arguments did not arrive whole. */
    private const CUT_OFF = 'Not run: the arguments of this call were not valid JSON (%s characters arrived), '
        . 'most likely cut off by the output limit. Send it again in smaller pieces: a file in parts, '
        . 'file_write for the first and file_patch to add the rest.';

    /** @var array<string, int> calls of this reply whose arguments were cut off, by id: how much arrived */
    private array $cutOff = [];

    private const NUDGE = 'You described what you are going to do but did not call the tool. Call it now, through the tool interface, rather than describing it.';

    private const EMPTY = 'The model returned two empty replies in a row. Rephrase the request, or change model with /model.';

    /**
     * A turn that runs out of tokens or steps used to end on the stop notice,
     * however much the model had found by then. It is asked to answer first,
     * once, while there is still room for the answer.
     */
    private const CLOSING_SHOWN = 'Asking the model to answer now: %s.';

    private const CLOSING = 'Stop investigating: %s. Answer the request now with what you have found, without calling any tool, and say plainly what you could not check.';

    private const NOT_RUN_CLOSING = 'Not run: there is no room left for tools in this turn. Answer with what you have found.';

    private const CLOSED_WITHOUT_ANSWER = 'Stopped: asked to answer with what it had found, the model kept calling tools instead.';

    private const OUT_OF_TOKENS = 'the token budget of this session is nearly spent';

    private const OUT_OF_STEPS = 'this turn has reached its limit of steps';

    /** Tools that show their own work, or wait on the user: no "running" line over them. */
    private const SHOW_THEMSELVES = [self::SUB_AGENT, 'plan_ready'];

    private const SUB_AGENT = 'delegate';

    /** How long a tool runs before it is said to be running: a read never shows it. */
    private const QUIET_SECONDS = 1.0;

    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly Toolbox $toolbox,
        private readonly PermissionBroker $permissions,
        // The terminal, or an editor over ACP.
        private readonly AgentOutput $chat,
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
        // Blocks what would change anything while a plan is being made.
        private readonly ?PlanMode $planMode = null,
        // Says when the session's token cap is about to stop the turn, so the
        // model answers before it does. Absent, only the step limit does that.
        private readonly ?UsageMeter $usage = null,
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

            $this->closeIfOutOfRoom($bag, $state, $iteration);

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

            if ($state->closing !== null) {
                $result = $this->refuseWhileClosing($bag, $message['tool_calls'], $content, $state, $iteration);
                if ($result === null) {
                    continue;
                }

                return $result;
            }

            $stuck = $this->checkRepetition($bag, $message['tool_calls'], $content, $state, $iteration);
            if ($stuck !== null) {
                return $stuck;
            }

            $this->runCalls($bag, $message['tool_calls'], $state);

            if ($this->interrupt->requested()) {
                return new Result(self::INTERRUPTED, iterations: $iteration, interrupted: true);
            }

            if ($state->sameErrors >= self::REPEATED_ERROR_LIMIT) {
                $error = (string) $state->lastError;

                return new Result(sprintf(self::WALLED, $state->sameErrors, $error), iterations: $iteration, repeatedError: $error);
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
            onWait: fn(bool $reasoning, ?string $why = null) => $this->chat->showWaiting($reasoning, $why),
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
        if (!$state->nudged && $state->closing === null && $this->announcesToolCall($content)) {
            $state->nudged = true;
            $this->chat->showInfo(self::NUDGE_SHOWN);
            $bag->user(self::NUDGE);

            return null;
        }

        return new Result($content, iterations: $iteration);
    }

    /**
     * Too little room left for another round of tools: the model is told to
     * answer with what it has, once per turn. The tools stay declared — an
     * API refuses a history of tool calls when the request declares none —
     * but a call made from here on is not run.
     */
    private function closeIfOutOfRoom(MessageBag $bag, TurnState $state, int $iteration): void
    {
        if ($state->closing !== null) {
            return;
        }

        $reason = match (true) {
            $this->usage?->nearlySpent() === true => self::OUT_OF_TOKENS,
            // The last two steps: one to answer, one to ask again if the
            // model calls a tool anyway.
            $iteration >= self::MAX_ITERATIONS - 1 => self::OUT_OF_STEPS,
            default => null,
        };
        if ($reason === null) {
            return;
        }

        $state->closing = $reason;
        $this->chat->showInfo(sprintf(self::CLOSING_SHOWN, $reason));
        $bag->user(sprintf(self::CLOSING, $reason));
    }

    /**
     * Tool calls after the model was asked to answer: answered "not run",
     * which leaves the history well formed. What it wrote alongside them is
     * its answer. Written nothing, it is asked once more when steps ran out —
     * not when tokens did: that request was the one the budget had room for.
     *
     * @param array<int, array<string, mixed>> $calls
     */
    private function refuseWhileClosing(MessageBag $bag, array $calls, string $content, TurnState $state, int $iteration): ?Result
    {
        foreach ($calls as $rawCall) {
            $bag->tool((string) ($rawCall['function']['name'] ?? 'tool'), self::NOT_RUN_CLOSING, $rawCall['id']);
        }

        if (trim($content) !== '') {
            $bag->assistant($content);

            return new Result($content, iterations: $iteration, repliedBeforeStop: true);
        }

        if ($state->closing === self::OUT_OF_STEPS && ++$state->refusedWhileClosing < 2) {
            return null;
        }

        return new Result(self::CLOSED_WITHOUT_ANSWER, iterations: $iteration);
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
    private function runCalls(MessageBag $bag, array $calls, TurnState $state): void
    {
        $calls = array_map(fn(array $raw) => new ToolCall(
            id: $raw['id'],
            name: $raw['function']['name'],
            arguments: $raw['function']['arguments'],
        ), array_values($calls));

        for ($i = 0; $i < count($calls); $i++) {
            $call = $calls[$i];

            // Every call in history needs a matching tool message or the next
            // request is malformed. Cancelled calls get a stub, not a gap.
            if ($this->interrupt->requested()) {
                $bag->tool($call->name, self::INTERRUPTED, $call->id);
                continue;
            }

            $together = $this->togetherFrom($calls, $i);
            if (count($together) > 1 && $this->platform->concurrency() > 1) {
                foreach ($this->runTogether($bag, $together) as $result) {
                    $this->noteError($state, $result);
                }
                $i += count($together) - 1;

                continue;
            }

            $this->noteError($state, $this->runCall($bag, $call));
        }
    }

    /**
     * The calls from $from on that can run at the same time: sub-agents asked
     * for one after another, each only reading, and each a conversation with
     * the model of its own — minutes, waited for one by one.
     *
     * @param list<ToolCall> $calls
     *
     * @return list<ToolCall>
     */
    private function togetherFrom(array $calls, int $from): array
    {
        $together = [];
        for ($i = $from; $i < count($calls) && $calls[$i]->name === self::SUB_AGENT && !isset($this->cutOff[$calls[$i]->id]); $i++) {
            $together[] = $calls[$i];
        }

        return $together;
    }

    /**
     * Each call in a Fiber of its own, as many going on at once as the
     * provider takes — asked again as each one ends, since a rate limit met
     * meanwhile lowers it — the rest waiting their turn. A request gives way
     * at every chunk, in Interrupt::requested(). Each shown as it starts,
     * numbered in the order asked, and recorded in that order.
     *
     * @param list<ToolCall> $calls
     *
     * @return list<ToolResult>
     */
    private function runTogether(MessageBag $bag, array $calls): array
    {
        $waiting = $calls;
        $running = [];
        $results = [];
        while ($waiting !== [] || $running !== []) {
            while ($waiting !== [] && count($running) < max(1, $this->platform->concurrency())) {
                $i = array_key_first($waiting);
                $call = $waiting[$i];
                unset($waiting[$i]);
                // Shown as it starts, with the number its lines will carry.
                $running[$i] = new Fiber(function () use ($call): ToolResult {
                    $this->chat->showToolCall($call->name, $call->arguments, $call->id);

                    return $this->decide($call);
                });
                Lanes::assign($running[$i], $i + 1);
            }

            foreach ($running as $i => $fiber) {
                try {
                    $fiber->isStarted() ? $fiber->resume() : $fiber->start();
                    if ($fiber->isTerminated()) {
                        $results[$i] = $fiber->getReturn();
                    }
                } catch (Throwable $e) {
                    $results[$i] = new ToolResult($calls[$i]->id, 'Error: ' . $e->getMessage(), isError: true);
                }

                if (isset($results[$i])) {
                    unset($running[$i]);
                }
            }
        }

        ksort($results);
        foreach ($results as $i => $result) {
            $this->record($bag, $calls[$i], $result);
        }

        return array_values($results);
    }

    /**
     * Count the same error coming back. Any other result — a success, or a
     * different failure — means the model is still learning something.
     */
    private function noteError(TurnState $state, ToolResult $result): void
    {
        $error = self::errorOf($result);

        if ($error === null) {
            $state->lastError = null;
            $state->sameErrors = 0;

            return;
        }

        $state->sameErrors = $error === $state->lastError ? $state->sameErrors + 1 : 1;
        $state->lastError = $error;
    }

    /** The error a result reports, on one line, or null for one that is not an error. */
    private static function errorOf(ToolResult $result): ?string
    {
        $content = trim($result->content);
        $failed = preg_match('/^\((?:exit code [1-9]\d*|command failed[^)]*)\)\s*/', $content, $status) === 1;

        // The user's answer, or plan mode's: neither is a wall to report.
        if (!$result->isError && !$failed || in_array($content, [self::REFUSED, PlanMode::BLOCKED], true)) {
            return null;
        }

        // The status line says it failed; the line after it says why.
        $why = trim(substr($content, $failed ? strlen($status[0]) : 0));
        $line = trim(explode("\n", $why === '' ? $content : $why)[0]);

        return mb_substr(str_starts_with($line, 'Error: ') ? substr($line, 7) : $line, 0, 300);
    }

    /** One call: shown, asked for when it must be, run, its result into history. */
    private function runCall(MessageBag $bag, ToolCall $call): ToolResult
    {
        if (isset($this->cutOff[$call->id])) {
            $result = new ToolResult($call->id, sprintf(self::CUT_OFF, number_format($this->cutOff[$call->id])), isError: true);
            $bag->tool($call->name, $result->content, $call->id);
            $this->chat->showToolResult($call->name, 'arguments cut off: not run', true, $call->id);

            return $result;
        }

        $this->chat->showToolCall($call->name, $call->arguments, $call->id);

        return $this->record($bag, $call, $this->decide($call));
    }

    /** Run, unless plan mode or the user says not to. */
    private function decide(ToolCall $call): ToolResult
    {
        $def = $this->toolbox->find($call->name);

        return match (true) {
            $this->planMode?->blocks($def) === true => new ToolResult($call->id, PlanMode::BLOCKED, isError: true),
            $this->permissions->check($call, $def)  => $this->execute($call),
            default                                 => new ToolResult($call->id, self::REFUSED, isError: true),
        };
    }

    /** A result: shown, observed, into history. */
    private function record(MessageBag $bag, ToolCall $call, ToolResult $result): ToolResult
    {
        $this->chat->showToolResult($call->name, $result->content, $result->isError, $call->id);
        $this->trace?->observe($call->name, $result->isError);

        $bag->tool($call->name, $result->content, $call->id);

        return $result;
    }

    /**
     * Run, said to be running once it takes long enough to wonder. Not for a
     * sub-agent, which shows its own work, nor a plan waiting on the user.
     */
    private function execute(ToolCall $call): ToolResult
    {
        // In a Fiber, beside others: the line would be theirs as much as its own.
        if (in_array($call->name, self::SHOW_THEMSELVES, true) || Lanes::number() !== null) {
            return $this->toolbox->execute($call);
        }

        $started = microtime(true);
        $replaced = $this->interrupt->meanwhile(function () use ($call, $started): void {
            $seconds = microtime(true) - $started;
            if ($seconds >= self::QUIET_SECONDS) {
                $this->chat->showRunning($call->name, $seconds);
            }
        });

        try {
            return $this->toolbox->execute($call);
        } finally {
            $this->interrupt->meanwhile($replaced);
        }
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
        $this->cutOff = [];

        foreach (array_values($toolCalls) as $call) {
            $arguments = $call['function']['arguments'] ?? [];
            $raw = null;
            if (is_string($arguments)) {
                $raw = $arguments;
                $arguments = trim($raw) === '' ? [] : json_decode($raw, true);
            }

            $id = $call['id'] ?? null;

            $call['id'] = is_string($id) && $id !== '' ? $id : self::newCallId();

            // Not an object: half of one, as a reply cut by the output limit
            // leaves it. Run with [] instead, a tool went ahead with nothing —
            // or with its defaults — and the model learnt nothing of why.
            if ($raw !== null && !is_array($arguments)) {
                $this->cutOff[$call['id']] = strlen($raw);
            }
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

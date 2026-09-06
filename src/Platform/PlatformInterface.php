<?php

namespace App\Platform;

/**
 * The model behind the agent, whatever is serving it.
 *
 * Sherpa was written against Ollama and the concrete class was injected in four
 * places, so moving to another backend meant editing the loop, the compactor,
 * the fact extractor and the command — and discovering along the way which of
 * Ollama's habits had quietly become assumptions. This interface is where that
 * line is drawn.
 *
 * # The message shape
 *
 * Messages in and out use the OpenAI-style chat shape that Ollama also speaks,
 * because it is the one every backend either uses or knows how to translate:
 *
 *     ['role' => 'system'|'user'|'assistant'|'tool', 'content' => string]
 *
 * with an assistant message optionally carrying:
 *
 *     'tool_calls' => [['function' => ['name' => string, 'arguments' => array]]]
 *
 * An implementation translates to and from its own wire format. That shape is
 * the contract; anything narrower would push the translation back into the loop.
 */
interface PlatformInterface
{
    /**
     * One request, delivered as it is produced.
     *
     * $onToken receives each text fragment. The return value is the complete
     * assistant message, which is what tool calls are read from — a caller must
     * never have to reassemble it from the fragments.
     *
     * Passing no $onToken makes this a blocking call, which is how the
     * background passes (summarisation, fact extraction) use it. There is
     * deliberately no separate non-streaming method: the streaming path is the
     * only one that polls for cancellation, and a background pass that cannot
     * be cancelled is the one that traps someone who has already asked to
     * leave.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @param (callable(string): void)|null    $onToken
     *
     * @return array<string, mixed>
     */
    public function stream(array $messages, array $tools = [], ?callable $onToken = null): array;

    /**
     * Token counts for the most recent request, as the server counted them.
     *
     * This is the only accurate figure a PHP client can have — there is no
     * tokenizer here — and it is what ContextBudget calibrates its estimate
     * against. An implementation whose backend reports nothing returns null,
     * and the estimate stays uncalibrated rather than wrong.
     *
     * @return array{prompt: int, completion: int}|null
     */
    public function lastUsage(): ?array;

    /**
     * Throughput of the most recent request, in tokens per second.
     *
     * Prefill is the prompt-evaluation pass — the silent part of the wait, and
     * what a rewritten history costs on the next turn. Decode is generation,
     * the part that streams. Together they are what InferenceSpeed turns into
     * "can this machine afford that", so the rest of Sherpa can measure the
     * machine instead of assuming one.
     *
     * Null where the backend reports no durations; policy then falls back to
     * the frugal branch rather than to a guess.
     *
     * @return array{prefill: float, decode: float}|null
     */
    public function lastTimings(): ?array;

    /**
     * Where the loaded model currently sits — GPU, host memory, or split.
     *
     * Null means "cannot answer", which covers both a backend with no such
     * notion and a model the server has not loaded yet. A partial residency is
     * the signal worth surfacing: it is the difference between a graphics card
     * that is being used and one that is merely present.
     */
    public function residency(): ?ModelResidency;

    public function modelName(): string;

    /** The window the platform is currently asking the backend for. */
    public function contextWindow(): int;

    /**
     * Every model this backend already holds, for a user to choose among.
     *
     * An empty array means "could not ask" as much as it means "none" — the
     * caller offers a name to be typed either way, so a backend that cannot
     * enumerate its models is inconvenient rather than disqualifying.
     *
     * @return array<int, ModelInfo>
     */
    public function catalogue(): array;

    /**
     * One model by name, including one that was never in catalogue().
     *
     * This is what keeps the choice open: a model the backend does not have is
     * a null to report, not an answer to refuse.
     */
    public function describeModel(string $model): ?ModelInfo;

    /**
     * Switch models mid-flight. The window travels with the model, because a
     * window belonging to a different model is not a setting — it is a bug
     * that the backend resolves quietly and in its own favour.
     */
    public function useModel(string $model, int $contextWindow): void;

    /** What to call this backend when telling the user something about it. */
    public function name(): string;

    public function isAvailable(): bool;
}

// contextWindow(), catalogue(), describeModel() and useModel() were once
// deliberately absent, on the grounds that nothing in src/ called them. Choosing
// a model at first run is what asked for them — the note that used to sit here
// said they would move up the day something did, and this was that day.
// listModels() did not survive the trip: catalogue() answers the same question
// with the two facts that actually decide the choice, the native window and
// whether the model can call tools at all.
//
// chat() left by the same rule it arrived under. Its one caller was the
// compactor's summariser, and that pass moved to stream() so Ctrl+C could reach
// it; a blocking method with no callers is a second way to do the same thing,
// and the only one of the two that cannot be cancelled.

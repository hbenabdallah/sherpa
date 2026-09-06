<?php

namespace App\Platform;

/**
 * The model behind the agent, whatever is serving it: the line that keeps
 * Ollama's habits from becoming assumptions in the loop, the compactor, the
 * fact extractor and the command.
 *
 * # The message shape
 *
 * Messages in and out use the OpenAI-style chat shape Ollama also speaks,
 * because every backend either uses it or knows how to translate it:
 *
 *     ['role' => 'system'|'user'|'assistant'|'tool', 'content' => string]
 *
 * with an assistant message optionally carrying:
 *
 *     'tool_calls' => [['id' => string, 'function' => ['name' => string, 'arguments' => array]]]
 *
 * and a tool message carrying the id of the call it answers:
 *
 *     ['role' => 'tool', 'content' => string, 'name' => string, 'tool_call_id' => string]
 *
 * What comes back from stream() may lack ids — Ollama sends none — and
 * AgentLoop assigns them. What goes in has them, pairs every call with its
 * result, and never opens on an orphan result: hosted APIs reject the rest.
 */
interface PlatformInterface
{
    /**
     * One request, delivered as it is produced. $onToken takes each text
     * fragment, the return value is the whole assistant message, and $onWait
     * fires while no text arrives — a silence of a second on a hosted API and
     * of minutes on a laptop CPU. No $onToken makes it blocking, as the
     * background passes want; this path is the only one that polls for Ctrl+C.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @param (callable(string): void)|null    $onToken
     * @param (callable(bool): void)|null      $onWait
     *
     * @return array<string, mixed>
     */
    public function stream(array $messages, array $tools = [], ?callable $onToken = null, ?callable $onWait = null): array;

    /**
     * Token counts as the server counted them: what ContextBudget calibrates
     * against, and the only accurate figure a PHP client can have. Null leaves
     * the estimate uncalibrated rather than wrong.
     *
     * @return array{prompt: int, completion: int, cached?: int}|null
     */
    public function lastUsage(): ?array;

    /**
     * Throughput of the last request: prefill is the silent prompt pass — what
     * a rewritten history costs next turn — and decode is generation. Null
     * where the backend reports none, and policy stays frugal.
     *
     * @return array{prefill: float, decode: float}|null
     */
    public function lastTimings(): ?array;

    /**
     * Where the loaded model sits — GPU, host memory, or split. Null means
     * "cannot answer". A partial residency is the signal worth surfacing: the
     * difference between a graphics card being used and one merely present.
     */
    public function residency(): ?ModelResidency;

    public function modelName(): string;

    /** The window the platform is currently asking the backend for. */
    public function contextWindow(): int;

    /**
     * Every model this backend holds, to choose among. An empty array means
     * "could not ask" as much as "none" — the caller offers a name to type
     * either way.
     *
     * @return array<int, ModelInfo>
     */
    public function catalogue(): array;

    /**
     * One model by name, including one that was never in catalogue(): a model
     * the backend does not have is a null to report, not an answer to refuse.
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

// catalogue(), describeModel() and useModel() arrived when choosing a model at
// first run needed them; listModels() did not survive, since catalogue()
// answers with the two facts that decide the choice. chat() left when the
// summariser moved to stream(), so that Ctrl+C could reach it.

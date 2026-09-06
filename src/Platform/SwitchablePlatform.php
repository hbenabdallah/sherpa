<?php

declare(strict_types=1);

namespace App\Platform;

use App\Config\Backend;
use App\Usage\UsageMeter;

/**
 * The platform everything else is handed: whichever backend is in force.
 *
 * The loop, the compactor, the fact extractor and the model screen all take a
 * PlatformInterface and never learn which one it is. Switching backend is then
 * one call here, made by the command at boot or on /backend, rather than a
 * rewiring of four services — and none of them can be left talking to the
 * backend the others have moved away from.
 *
 * It is also the one door every request goes through — the agent's turns, the
 * compactor's summaries, the fact extraction — which makes it the place to
 * count them, and to refuse the one that would exceed the session's cap.
 */
final class SwitchablePlatform implements PlatformInterface, EmbeddingBackend
{
    private Backend $backend = Backend::DEFAULT;

    public function __construct(
        private readonly OllamaPlatform $ollama,
        private readonly OpenAiCompatiblePlatform $api,
        private readonly ?UsageMeter $meter = null,
    ) {}

    public function switchTo(Backend $backend): void
    {
        $this->backend = $backend;
    }

    public function backend(): Backend
    {
        return $this->backend;
    }

    /**
     * Where API requests go, empty when nobody configured it — which is a
     * different fix from an address that does not answer, and deserves a
     * different sentence.
     */
    public function apiEndpoint(): string
    {
        return $this->api->endpoint();
    }

    public function ollamaEndpoint(): string
    {
        return $this->ollama->endpoint();
    }

    public function useApiEndpoint(string $baseUrl): void
    {
        $this->api->useEndpoint($baseUrl);
    }

    public function apiHasKey(): bool
    {
        return $this->api->hasKey();
    }

    private function active(): PlatformInterface
    {
        return match ($this->backend) {
            Backend::Api    => $this->api,
            Backend::Ollama => $this->ollama,
        };
    }

    public function stream(array $messages, array $tools = [], ?callable $onToken = null, ?callable $onWait = null): array
    {
        $this->meter?->assertRoom();

        try {
            return $this->active()->stream($messages, $tools, $onToken, $onWait);
        } finally {
            // Whatever the server counted, even for a reply cut short by
            // Ctrl+C: those tokens were read and billed all the same.
            $usage = $this->active()->lastUsage();
            if ($usage !== null) {
                $this->meter?->record($usage);
            }
        }
    }

    /**
     * Embeddings from the backend in force. Not metered: providers mostly do
     * not report token counts for them, and they cost a small fraction of a
     * chat turn — counting a guess would make the meter lie in the other
     * direction.
     */
    public function embed(array $texts, string $model): array
    {
        return match ($this->backend) {
            Backend::Api    => $this->api->embed($texts, $model),
            Backend::Ollama => $this->ollama->embed($texts, $model),
        };
    }

    public function lastUsage(): ?array
    {
        return $this->active()->lastUsage();
    }

    public function lastTimings(): ?array
    {
        return $this->active()->lastTimings();
    }

    public function residency(): ?ModelResidency
    {
        return $this->active()->residency();
    }

    public function modelName(): string
    {
        return $this->active()->modelName();
    }

    public function contextWindow(): int
    {
        return $this->active()->contextWindow();
    }

    public function catalogue(): array
    {
        return $this->active()->catalogue();
    }

    public function describeModel(string $model): ?ModelInfo
    {
        return $this->active()->describeModel($model);
    }

    public function useModel(string $model, int $contextWindow): void
    {
        $this->active()->useModel($model, $contextWindow);
    }

    public function name(): string
    {
        return $this->active()->name();
    }

    public function isAvailable(): bool
    {
        return $this->active()->isAvailable();
    }
}

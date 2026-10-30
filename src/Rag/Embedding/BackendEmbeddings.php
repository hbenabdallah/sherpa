<?php

declare(strict_types=1);

namespace App\Rag\Embedding;

use App\Config\Backend;
use App\Config\ModelResolver;
use App\Platform\EmbeddingBackend;
use App\Platform\OllamaPlatform;
use App\Platform\OpenAiCompatiblePlatform;
use App\Platform\SwitchablePlatform;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Embeddings from the backend in force, with the model this machine chose for
 * it — resolved at each call, so /backend moves them along with the chat.
 *
 * Or from somewhere else, when config.yaml says so under `embeddings:`: a
 * chat provider with no embedding model (Anthropic, Groq) then still searches
 * the documentation by meaning, with OpenAI's or a local Ollama's.
 */
final class BackendEmbeddings implements EmbeddingProvider
{
    /** The platform for embeddings elsewhere, kept while where it points stays the same. */
    private ?EmbeddingBackend $elsewhere = null;

    private string $elsewhereKey = '';

    public function __construct(
        private readonly SwitchablePlatform $platform,
        private readonly ModelResolver $models,
        private readonly ?HttpClientInterface $client = null,
    ) {}

    public function model(): string
    {
        return $this->models->embeddingModel($this->platform->backend());
    }

    public function embed(array $texts): array
    {
        $model = $this->model();
        if ($model === '' || $texts === []) {
            return [];
        }

        $platform = $this->models->embeddingsElsewhere() !== null ? $this->elsewhere() : $this->platform;

        return array_map(Vectors::normalize(...), $platform->embed($texts, $model));
    }

    private function elsewhere(): EmbeddingBackend
    {
        $where = $this->models->embeddingsElsewhere();
        if ($where === null) {
            throw new LogicException('embeddings are not configured elsewhere: the chat backend serves them');
        }
        $key = json_encode($where, JSON_THROW_ON_ERROR);

        if ($this->elsewhere instanceof EmbeddingBackend && $key === $this->elsewhereKey) {
            return $this->elsewhere;
        }

        $client = $this->client ?? HttpClient::create();
        $provider = $where['provider'];

        if (strtolower($provider) === Backend::Ollama->value) {
            $platform = new OllamaPlatform($client, $this->platform->ollamaEndpoint(), $where['model']);
        } elseif (is_string($where['url'] ?? null)) {
            $platform = new OpenAiCompatiblePlatform($client, $where['url'], $where['model'], $where['key']);
        } else {
            throw new RuntimeException("embeddings.provider in config.yaml is \"{$provider}\", which is no recorded provider: /provider lists them, or say ollama.");
        }

        $this->elsewhereKey = $key;

        return $this->elsewhere = $platform;
    }
}

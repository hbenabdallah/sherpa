<?php

declare(strict_types=1);

namespace App\Rag\Embedding;

use App\Config\Backend;
use App\Platform\EmbeddingBackend;
use App\Platform\ModelInfo;
use App\Platform\PlatformInterface;
use App\Platform\UnknownEmbeddingModel;

/**
 * Which embedding model the provider offers, found without asking anyone: the
 * user gives an address, a key and a chat model, and a chat model cannot make
 * vectors. The chat model is never involved — the provider's own catalogue is
 * read first, and where there is none (Cloudflare answers 405) the known names
 * are tried on one word each.
 */
final class EmbeddingDetector
{
    /** Known embedding models, preferred first: multilingual ones lead, since documentation often is. */
    public const KNOWN = [
        'text-embedding-3-small', 'text-embedding-3-large', 'mistral-embed', '@cf/baai/bge-m3',
        'text-embedding-004', 'bge-m3', 'nomic-embed-text', 'mxbai-embed-large',
        'snowflake-arctic-embed', 'text-embedding-ada-002',
    ];

    /** Tried one by one where the provider publishes no catalogue: OpenAI, Mistral, Cloudflare, Gemini. */
    public const PROBES = ['text-embedding-3-small', 'mistral-embed', '@cf/baai/bge-m3', 'text-embedding-004'];

    public function __construct(private readonly PlatformInterface&EmbeddingBackend $platform) {}

    public function detect(Backend $backend): EmbeddingDetection
    {
        try {
            $offered = array_map(static fn(ModelInfo $m) => $m->name, $this->platform->catalogue());
        } catch (\Throwable) {
            $offered = [];
        }

        $picked = self::pick($offered);
        if ($picked !== null) {
            return EmbeddingDetection::found($picked);
        }

        // A local server holds what was pulled and nothing else. An empty list
        // there is more likely a server not answering than a verdict.
        if ($backend->isLocal()) {
            return $offered === []
                ? EmbeddingDetection::undetermined('Ollama listed no models')
                : EmbeddingDetection::none('no embedding model pulled (ollama pull bge-m3)');
        }

        foreach (self::PROBES as $model) {
            try {
                $vectors = $this->platform->embed(['test'], $model);
            } catch (UnknownEmbeddingModel) {
                continue;
            } catch (\Throwable $e) {
                return EmbeddingDetection::undetermined($e->getMessage());
            }

            if (($vectors[0] ?? []) !== []) {
                return EmbeddingDetection::found($model);
            }
        }

        return EmbeddingDetection::none('this provider offers none of the embedding models Sherpa knows');
    }

    /**
     * The best embedding model in a catalogue: a known one, else anything
     * whose name says it embeds. Ollama tags carry ":latest" and the like.
     *
     * @param list<string> $offered
     */
    public static function pick(array $offered): ?string
    {
        foreach (self::KNOWN as $known) {
            foreach ($offered as $name) {
                if ($name === $known || explode(':', $name, 2)[0] === $known) {
                    return $name;
                }
            }
        }

        foreach ($offered as $name) {
            if (preg_match('/embed/i', $name) === 1) {
                return $name;
            }
        }

        return null;
    }
}

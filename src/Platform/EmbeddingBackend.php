<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * A backend that also turns text into vectors.
 *
 * Kept apart from PlatformInterface: embedding is not something every caller
 * of a chat backend needs, and every fake platform in the tests would have to
 * grow a method nothing asks of it. The backends that serve chat serve this
 * too — same address, same key, same retries — so documentation search needs
 * no second provider to configure.
 */
interface EmbeddingBackend
{
    /**
     * One vector per text, in the order given.
     *
     * @param list<string> $texts
     *
     * @return list<list<float>>
     */
    public function embed(array $texts, string $model): array;
}

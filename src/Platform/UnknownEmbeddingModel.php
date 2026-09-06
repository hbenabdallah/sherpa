<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * The server answered, and this model is not one it embeds with. Apart from
 * every other failure because detection tries names until one works: a model
 * that does not exist means "try the next", a quota or an outage means "cannot
 * tell today" — and recording that as "no embedding model" would stick.
 */
final class UnknownEmbeddingModel extends \RuntimeException
{
}

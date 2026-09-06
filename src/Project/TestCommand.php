<?php

declare(strict_types=1);

namespace App\Project;

/** A project's test command, and the file that declared it. */
final class TestCommand
{
    public function __construct(
        public readonly string $command,
        public readonly string $source,
    ) {}
}

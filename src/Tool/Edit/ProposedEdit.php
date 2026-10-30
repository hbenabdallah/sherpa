<?php

declare(strict_types=1);

namespace App\Tool\Edit;

/**
 * What an edit would do to a file, whole: for an editor that shows the diff
 * itself rather than Sherpa's text rendering of it.
 */
final readonly class ProposedEdit
{
    public function __construct(
        /** Absolute, as resolved inside the project. */
        public string $path,
        /** null for a file the edit creates. */
        public ?string $old,
        public string $new,
    ) {}
}

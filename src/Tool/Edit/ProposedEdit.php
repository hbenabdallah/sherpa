<?php

namespace App\Tool\Edit;

/**
 * What an edit would do to a file, whole: for an editor that shows the diff
 * itself rather than Sherpa's text rendering of it.
 */
final class ProposedEdit
{
    public function __construct(
        /** Absolute, as resolved inside the project. */
        public readonly string $path,
        /** null for a file the edit creates. */
        public readonly ?string $old,
        public readonly string $new,
    ) {}
}

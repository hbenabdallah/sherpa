<?php

declare(strict_types=1);

namespace App\Permission;

/** What holds the commands a session runs: the line between running one unasked and asking. */
interface CommandConfinement
{
    /** What keeps commands in — the sandbox, the project's container — said for the user; null when nothing does. */
    public function confinedBy(): ?string;
}

<?php

declare(strict_types=1);

namespace App\Agent;

/** How much the session asks before it acts. The value is the mode's id in an editor's menu. */
enum Mode: string
{
    /** Reads freely; asks before anything that writes or runs. */
    case Default = 'default';

    /** Reads and proposes; nothing is changed until the plan is approved. */
    case Plan = 'plan';

    /** Edits in the project, and commands a sandbox or the project's container holds, run without asking. */
    case Auto = 'auto';
}

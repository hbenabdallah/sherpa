<?php

declare(strict_types=1);

namespace App\Permission;

enum ConfirmChoice
{
    case Once;
    case Session;
    case Project;
    case Deny;
}

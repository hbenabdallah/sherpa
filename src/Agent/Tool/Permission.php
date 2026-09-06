<?php

declare(strict_types=1);

namespace App\Agent\Tool;

enum Permission {
    case AUTO;
    case CONFIRM;
    case DENY;
}
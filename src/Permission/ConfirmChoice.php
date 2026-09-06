<?php

namespace App\Permission;

enum ConfirmChoice {
    case Once;
    case Session;
    case Project;
    case Deny;
}

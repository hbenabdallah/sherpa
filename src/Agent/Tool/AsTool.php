<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsTool
{
    public function __construct(
        public string     $name,
        public string     $description,
        public Permission $permission = Permission::AUTO,
        // Brings text from outside the project and the user; see Provenance.
        public bool       $untrusted = false,
    ) {}
}

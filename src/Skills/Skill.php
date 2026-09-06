<?php

namespace App\Skills;

final class Skill
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $content,
        public readonly string $path,
    ) {}
}

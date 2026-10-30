<?php

namespace Boutique\User;

final readonly class User
{
    public function __construct(
        public int $id,
        public string $email,
        public string $country,
    ) {}
}

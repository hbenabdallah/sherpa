<?php

namespace Boutique\User;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $country,
    ) {}
}

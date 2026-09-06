<?php

namespace Boutique\User;

final class UserRepo
{
    /** @var array<int, User> */
    private array $users = [];

    public function __construct()
    {
        $this->users[1] = new User(1, 'alice@example.test', 'FR');
        $this->users[2] = new User(2, 'bruno@example.test', 'DE');
    }

    public function find(int $id): ?User
    {
        return $this->users[$id] ?? null;
    }
}

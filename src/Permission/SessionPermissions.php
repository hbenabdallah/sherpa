<?php

namespace App\Permission;

/**
 * Permissions that die with the process.
 *
 * Project-scoped grants do not live here: they must outlive the process, so
 * they are persisted. See ProjectPermissions.
 */
class SessionPermissions
{
    /** @var array<string, true> */
    private array $sessionAllowed = [];

    public function allowForSession(string $toolName): void
    {
        $this->sessionAllowed[$toolName] = true;
    }

    public function isSessionAllowed(string $toolName): bool
    {
        return isset($this->sessionAllowed[$toolName]);
    }

    public function revoke(string $toolName): bool
    {
        $held = isset($this->sessionAllowed[$toolName]);
        unset($this->sessionAllowed[$toolName]);

        return $held;
    }

    /** @return int how many went */
    public function clear(): int
    {
        $count = count($this->sessionAllowed);
        $this->sessionAllowed = [];

        return $count;
    }

    /** @return array<int, string> */
    public function all(): array
    {
        return array_keys($this->sessionAllowed);
    }
}

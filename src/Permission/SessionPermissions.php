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

    /** @return array<int, string> */
    public function all(): array
    {
        return array_keys($this->sessionAllowed);
    }
}

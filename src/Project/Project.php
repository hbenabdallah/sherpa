<?php

declare(strict_types=1);

namespace App\Project;

use App\Permission\CommandPolicy;
use DateTimeImmutable;
use DateTimeInterface;

final class Project
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $path,
        public readonly string $memoryDb,
        public readonly DockerConfig $docker,
        /** Refreshed each time the project is opened; see ProjectStore::touch(). */
        public string $stack,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $lastUsedAt,
        /**
         * Tools the user chose to allow for this project without being asked
         * again, and commands, as "shell_exec: make test *" (CommandPolicy).
         * Survives restarts, so it is a standing grant.
         *
         * @var array<int, string>
         */
        public array $allowedTools = [],
    ) {}

    public static function fromArray(string $slug, array $data): self
    {
        return new self(
            slug: $slug,
            name: $data['name'],
            path: $data['path'],
            memoryDb: $data['memory_db'] ?? self::defaultMemoryDb($slug),
            docker: DockerConfig::fromArray($data['docker'] ?? []),
            stack: $data['stack'] ?? '',
            createdAt: new DateTimeImmutable($data['created_at'] ?? 'now'),
            lastUsedAt: new DateTimeImmutable($data['last_used_at'] ?? 'now'),
            // A grant of shell_exec itself, from before grants named commands,
            // let any command run unasked: it is not carried over, and the
            // next command is asked about, with a grant for that command.
            allowedTools: array_values(array_filter(
                $data['allowed_tools'] ?? [],
                static fn(mixed $grant): bool => is_string($grant) && $grant !== CommandPolicy::TOOL,
            )),
        );
    }

    public function toArray(): array
    {
        return [
            'name'         => $this->name,
            'path'         => $this->path,
            'memory_db'    => $this->memoryDb,
            'docker'       => $this->docker->toArray(),
            'stack'        => $this->stack,
            'created_at'   => $this->createdAt->format(DateTimeInterface::ATOM),
            'last_used_at' => $this->lastUsedAt->format(DateTimeInterface::ATOM),
            'allowed_tools' => array_values($this->allowedTools),
        ];
    }

    public static function defaultMemoryDb(string $slug): string
    {
        return self::configDir() . "/projects/{$slug}/memory.db";
    }

    public static function configDir(): string
    {
        return ($_SERVER['HOME'] ?? getenv('HOME')) . '/.config/sherpa';
    }

    public function shortPath(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');

        return is_string($home) && $home !== '' ? str_replace($home, '~', $this->path) : $this->path;
    }
}

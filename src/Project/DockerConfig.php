<?php

namespace App\Project;

final class DockerConfig
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $container = '',
        public readonly string $dbContainer = '',
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            container: $data['container'] ?? '',
            dbContainer: $data['db_container'] ?? '',
        );
    }

    public function toArray(): array
    {
        return [
            'enabled'      => $this->enabled,
            'container'    => $this->container,
            'db_container' => $this->dbContainer,
        ];
    }
}

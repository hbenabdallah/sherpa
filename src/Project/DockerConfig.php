<?php

namespace App\Project;

final class DockerConfig
{
    /**
     * Commands run as the user who runs Sherpa. The project is mounted from
     * the host, and a container's default user is usually root: whatever a
     * command writes there — a tool rewriting a file, a cache, a generated
     * class — would come back owned by root, and Sherpa could no longer edit it.
     */
    public const USER_HOST = 'host';

    /** Commands run as the container's own user, whatever the image says it is. */
    public const USER_CONTAINER = 'container';

    /**
     * @param string $user USER_HOST, USER_CONTAINER, or what `docker exec --user`
     *                     takes as it is: a name, a uid, uid:gid
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $container = '',
        public readonly string $dbContainer = '',
        public readonly string $user = self::USER_HOST,
    ) {}

    public static function fromArray(array $data): self
    {
        $user = trim((string) ($data['user'] ?? ''));

        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            container: $data['container'] ?? '',
            dbContainer: $data['db_container'] ?? '',
            // Compared to '', not by truthiness: "0" is root's uid, not a blank.
            user: $user === '' ? self::USER_HOST : $user,
        );
    }

    public function toArray(): array
    {
        return [
            'enabled'      => $this->enabled,
            'container'    => $this->container,
            'db_container' => $this->dbContainer,
            'user'         => $this->user,
        ];
    }
}

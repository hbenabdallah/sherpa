<?php

namespace App\Tool;

use Symfony\Component\Process\Process;

/**
 * What a container looks like from outside: where it mounts the host, and where
 * it starts.
 *
 * shell_exec runs commands inside a project's container, and the directory it
 * hands to `docker exec -w` was the host path. Nothing on the host is at that
 * path inside the container, so every command failed before it started. The
 * mount table is the translation.
 */
class ContainerInspector
{
    /** Inspecting is a fork and a JSON parse; once per container is plenty. */
    private array $cache = [];

    /**
     * @return array{workdir: ?string, mounts: array<int, array{source: string, destination: string}>}|null
     *         null when the container cannot be inspected at all
     */
    public function inspect(string $container): ?array
    {
        if (array_key_exists($container, $this->cache)) {
            return $this->cache[$container];
        }

        return $this->cache[$container] = $this->run($container);
    }

    private function run(string $container): ?array
    {
        $process = new Process(['docker', 'inspect', '--format', '{{json .}}', $container], timeout: 10);

        try {
            $process->run();
        } catch (\Throwable) {
            // No docker binary here, most likely. The caller says so properly.
            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        $data = json_decode(trim($process->getOutput()), true);
        if (!is_array($data)) {
            return null;
        }

        $mounts = [];
        foreach ($data['Mounts'] ?? [] as $mount) {
            if (is_string($mount['Source'] ?? null) && is_string($mount['Destination'] ?? null)) {
                $mounts[] = ['source' => $mount['Source'], 'destination' => $mount['Destination']];
            }
        }

        return [
            'workdir' => is_string($data['Config']['WorkingDir'] ?? null) && $data['Config']['WorkingDir'] !== ''
                ? $data['Config']['WorkingDir']
                : null,
            'mounts' => $mounts,
        ];
    }

    /**
     * Where a host path shows up inside the container, if it does at all.
     *
     * The longest matching mount wins: a project mounted at /srv/app inside a
     * home directory mounted at /home would otherwise resolve through the
     * wrong one.
     *
     * @param array{workdir: ?string, mounts: array<int, array{source: string, destination: string}>} $container
     */
    public function translate(array $container, string $hostPath): ?string
    {
        $hostPath = rtrim($hostPath, '/');
        $best = null;
        $depth = -1;

        foreach ($container['mounts'] as $mount) {
            $source = rtrim($mount['source'], '/');

            if ($source === '' || ($hostPath !== $source && !str_starts_with($hostPath, $source . '/'))) {
                continue;
            }

            if (strlen($source) > $depth) {
                $depth = strlen($source);
                $best = rtrim($mount['destination'], '/') . substr($hostPath, strlen($source));
            }
        }

        return $best === null ? null : ($best === '' ? '/' : $best);
    }
}

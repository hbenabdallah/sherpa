<?php

declare(strict_types=1);

namespace App\Acp;

use ErrorException;
use JsonException;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

/**
 * The JetBrains IDEs' list of custom ACP agents, `~/.jetbrains/acp.json`: what
 * their AI Chat's *Add Custom Agent* opens. Sherpa adds itself there, so that
 * nobody has to type the path of the executable.
 */
final readonly class JetBrainsAgents
{
    public const string NAME = 'Sherpa';

    public function __construct(
        private string $path,
    ) {}

    public static function ofUser(): self
    {
        return new self(($_SERVER['HOME'] ?? getenv('HOME')) . '/.jetbrains/acp.json');
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Sherpa's entry, written or replaced; the other agents and settings stay
     * as they are, and so does an `env` given to Sherpa's earlier entry.
     *
     * @param list<string> $args
     *
     * @throws UnexpectedValueException when the file is there but not a JSON object
     * @throws RuntimeException         when it cannot be written
     */
    public function add(string $command, array $args): void
    {
        $config = $this->read();
        $config->agent_servers ??= new stdClass();

        if (!$config->agent_servers instanceof stdClass) {
            throw new UnexpectedValueException("{$this->path}: agent_servers is not a JSON object; fix it, then run this again.");
        }

        $earlier = $config->agent_servers->{self::NAME} ?? null;
        $env = $earlier instanceof stdClass && ($earlier->env ?? null) instanceof stdClass ? $earlier->env : new stdClass();

        $config->agent_servers->{self::NAME} = (object) ['command' => $command, 'args' => $args, 'env' => $env];

        $this->write($config);
    }

    private function read(): stdClass
    {
        if (!is_file($this->path)) {
            return new stdClass();
        }

        $text = file_get_contents($this->path);
        if ($text === false) {
            throw new RuntimeException("Cannot read {$this->path}; it is left as it was.");
        }

        if (trim($text) === '') {
            return new stdClass();
        }

        try {
            $config = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnexpectedValueException("{$this->path} is not valid JSON ({$e->getMessage()}); fix it, then run this again.", $e->getCode(), previous: $e);
        }

        if (!$config instanceof stdClass) {
            throw new UnexpectedValueException("{$this->path} is not a JSON object; fix it, then run this again.");
        }

        return $config;
    }

    /**
     * The file a symlinked acp.json points to (a dotfiles repository's, say):
     * renaming over the link would replace it with a copy, cut from its source.
     */
    private function target(): string
    {
        $path = $this->path;
        for ($hops = 0; $hops < 40 && is_link($path); $hops++) {
            $link = readlink($path);
            if ($link === false) {
                throw new RuntimeException("Cannot follow the link {$path}.");
            }

            $path = str_starts_with($link, '/') ? $link : dirname($path) . '/' . $link;
        }

        if (is_link($path)) {
            throw new RuntimeException("{$this->path} is a loop of links; fix it, then run this again.");
        }

        return $path;
    }

    /**
     * Through a file beside it, renamed: the IDE never reads half of it. Yours
     * only, from its creation: an agent's env may hold its API key. Created new and written through its handle, never reopened by name: a
     * link put in its place meanwhile is not followed.
     */
    private function write(stdClass $config): void
    {
        $target = $this->target();
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create {$directory}.");
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $partial = $target . '.' . bin2hex(random_bytes(4));
        $mask = umask(0o077);

        try {
            $handle = fopen($partial, 'x');
        } catch (ErrorException $e) {
            throw new RuntimeException("Cannot write {$this->path}.", $e->getCode(), previous: $e);
        } finally {
            umask($mask);
        }

        if ($handle === false) {
            throw new RuntimeException("Cannot write {$this->path}.");
        }

        // A directory's default ACL wins over the umask.
        $written = chmod($partial, 0o600) && fwrite($handle, $json) === strlen($json);
        $closed = fclose($handle);
        if (!$written || !$closed || !rename($partial, $target)) {
            if (is_file($partial)) {
                unlink($partial);
            }

            throw new RuntimeException("Cannot write {$this->path}.");
        }
    }
}

<?php

namespace App\Tool\Edit;

use Closure;
use RuntimeException;

/**
 * Where file_read, file_write and file_patch get a file's text and put it
 * back: the disk, unless an editor serves them. In an editor the file open in
 * a tab may hold changes not yet saved; read from disk, the model would patch
 * what the user no longer sees, and the editor would then hold two versions.
 */
class Files
{
    /** @var (Closure(string): ?string)|null */
    private ?Closure $reader = null;

    /** @var (Closure(string, string): void)|null */
    private ?Closure $writer = null;

    /**
     * Read and write through an editor from now on. $reader answers null for
     * a file it cannot give, which is then read from disk; $writer throws
     * \RuntimeException saying why it could not write.
     *
     * @param (Closure(string): ?string)|null    $reader
     * @param (Closure(string, string): void)|null $writer
     */
    public function through(?Closure $reader, ?Closure $writer): void
    {
        $this->reader = $reader;
        $this->writer = $writer;
    }

    /** The text of the file at $resolved (absolute), or null when there is none to read. */
    public function read(string $resolved): ?string
    {
        if ($this->reader !== null && ($text = ($this->reader)($resolved)) !== null) {
            return $text;
        }

        if (!is_file($resolved) || !is_readable($resolved)) {
            return null;
        }

        $text = @file_get_contents($resolved);

        return $text === false ? null : $text;
    }

    /**
     * @param string $path as the model named it, for what it is told on failure
     *
     * @throws RuntimeException
     */
    public function write(string $resolved, string $path, string $content): void
    {
        if ($this->writer !== null) {
            ($this->writer)($resolved, $content);

            return;
        }

        FileSaver::save($resolved, $path, $content);
    }

    /**
     * A file's lines as file(…, FILE_IGNORE_NEW_LINES) gives them: without
     * their line endings, and no empty line after a final newline.
     *
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $lines = explode("\n", $text);
        if (end($lines) === '') {
            array_pop($lines);
        }

        return array_map(static fn(string $line) => str_ends_with($line, "\r") ? substr($line, 0, -1) : $line, $lines);
    }
}

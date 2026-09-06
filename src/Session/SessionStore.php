<?php

declare(strict_types=1);

namespace App\Session;

use App\Project\Project;

/**
 * Conversations kept on disk, so closing the terminal is not the end of one:
 * extraction keeps conclusions, and a conversation is the work in progress.
 *
 * One JSON file per conversation in the project's own directory, so deleting
 * the project deletes them too and a project re-added under the same name
 * inherits nobody's history. Written after every turn rather than at exit — a
 * crash costs one turn — atomically, and readable by its owner alone.
 */
final class SessionStore
{
    /** How many conversations a project keeps. Older ones are removed. */
    public const KEEP = 20;

    private const FORMAT = 1;

    /**
     * To the microsecond. "Most recently active" is what orders the list and
     * decides what pruning removes, and two saves inside one second are
     * ordinary — two terminals on one project, or a resume followed at once by
     * a turn. At second precision those tie, and a tie is an order nobody chose.
     */
    private const TIME = 'Y-m-d\\TH:i:s.uP';

    private ?string $dir = null;
    private ?string $id = null;
    private ?\DateTimeImmutable $startedAt = null;

    /**
     * Named once, after the first thing asked. Compacting the history folds
     * old messages away, and the first user message left is a later one: the
     * list would show a conversation under a name that keeps changing.
     */
    private ?string $title = null;

    public function bind(Project $project): void
    {
        $this->dir = dirname(Project::defaultMemoryDb($project->slug)) . '/sessions';
        $this->startNew();
    }

    /**
     * Stop writing anywhere: the project these conversations belonged to is
     * gone. save() answers false from here on instead of recreating its
     * directory.
     */
    public function unbind(): void
    {
        $this->dir = null;
        $this->startNew();
    }

    /**
     * Whatever is saved next goes into a new file. Nothing is written until
     * then: a session nobody said anything in is not worth a file, nor a line
     * in the list.
     */
    public function startNew(): void
    {
        $this->id = null;
        $this->startedAt = null;
        $this->title = null;
    }

    /** Carry on writing into a resumed conversation's own file. */
    public function adopt(SavedSession $session): void
    {
        $this->id = $session->id;
        $this->startedAt = $session->startedAt;
        $this->title = $session->title;
    }

    public function currentId(): ?string
    {
        return $this->id;
    }

    /**
     * @param list<array<string, mixed>>                                              $messages all of them, system included
     * @param list<array{id: int, tool: string, content: string, created_at: string}> $excerpts
     *
     * @return bool false when there was nothing to keep, or it could not be
     *              written — a session that cannot be saved still runs
     */
    public function save(array $messages, array $excerpts, string $backend, string $model): bool
    {
        if ($this->dir === null) {
            return false;
        }

        $conversation = array_values(array_filter(
            $messages,
            static fn(array $m) => ($m['role'] ?? '') !== 'system',
        ));

        $title = $this->title ?? $this->title($conversation);
        if ($title === '') {
            return false;
        }
        $this->title = $title;

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            return false;
        }

        $now = new \DateTimeImmutable();
        $this->startedAt ??= $now;
        $this->id ??= $now->format('Ymd-His') . '-' . bin2hex(random_bytes(2));

        $json = json_encode([
            'format'     => self::FORMAT,
            'id'         => $this->id,
            'started_at' => $this->startedAt->format(self::TIME),
            'updated_at' => $now->format(self::TIME),
            'backend'    => $backend,
            'model'      => $model,
            'title'      => $title,
            'messages'   => $conversation,
            'excerpts'   => $excerpts,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            return false;
        }

        $file = $this->file($this->id);
        $tmp = $file . '.' . getmypid() . '.tmp';

        if (@file_put_contents($tmp, $json) === false) {
            return false;
        }

        @chmod($tmp, 0600);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return false;
        }

        $this->prune();

        return true;
    }

    /**
     * Every conversation kept for this project, the most recently active first.
     *
     * @return list<SavedSession>
     */
    public function all(): array
    {
        if ($this->dir === null || !is_dir($this->dir)) {
            return [];
        }

        $sessions = [];

        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $session = $this->read($file);
            if ($session !== null) {
                $sessions[] = $session;
            }
        }

        usort($sessions, static fn(SavedSession $a, SavedSession $b) => $b->updatedAt <=> $a->updatedAt);

        return $sessions;
    }

    /**
     * The conversation to pick up when nobody said which: the most recent one
     * that is not the one already open.
     */
    public function latest(): ?SavedSession
    {
        foreach ($this->all() as $session) {
            if ($session->id !== $this->id) {
                return $session;
            }
        }

        return null;
    }

    public function load(string $id): ?SavedSession
    {
        // The id comes from what the user typed. It names a file, so anything
        // that is not the shape this class writes is refused before it gets
        // near a path: "../../projects.yaml" is not a session.
        if ($this->dir === null || preg_match('/^\d{8}-\d{6}-[0-9a-f]{4}$/', $id) !== 1) {
            return null;
        }

        $file = $this->file($id);

        return is_file($file) ? $this->read($file) : null;
    }

    public function directory(): ?string
    {
        return $this->dir;
    }

    private function file(string $id): string
    {
        return $this->dir . '/' . $id . '.json';
    }

    private function read(string $file): ?SavedSession
    {
        $data = json_decode((string) @file_get_contents($file), true);

        // A file this class did not write, or one cut short: skipped rather
        // than fatal. One unreadable session must not cost the list of others.
        if (!is_array($data) || !is_string($data['id'] ?? null) || !is_array($data['messages'] ?? null)) {
            return null;
        }

        try {
            return new SavedSession(
                id: $data['id'],
                startedAt: new \DateTimeImmutable((string) ($data['started_at'] ?? 'now')),
                updatedAt: new \DateTimeImmutable((string) ($data['updated_at'] ?? 'now')),
                backend: (string) ($data['backend'] ?? ''),
                model: (string) ($data['model'] ?? ''),
                title: (string) ($data['title'] ?? ''),
                messages: array_values(array_filter($data['messages'], is_array(...))),
                excerpts: array_values(array_filter($data['excerpts'] ?? [], is_array(...))),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The first thing asked, on one line: how a person recognises a
     * conversation in a list, far better than by its date.
     *
     * @param list<array<string, mixed>> $conversation
     */
    private function title(array $conversation): string
    {
        foreach ($conversation as $message) {
            if (($message['role'] ?? '') === 'user') {
                $text = trim((string) preg_replace('/\s+/', ' ', (string) ($message['content'] ?? '')));

                return mb_strlen($text) > 80 ? mb_substr($text, 0, 79) . '…' : $text;
            }
        }

        return '';
    }

    /** Keep the most recent KEEP; never the one being written. */
    private function prune(): void
    {
        foreach (array_slice($this->all(), self::KEEP) as $old) {
            if ($old->id !== $this->id) {
                @unlink($this->file($old->id));
            }
        }
    }
}

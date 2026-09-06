<?php

namespace App\Memory;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;

/**
 * Per-project long-term memory.
 *
 * Facts are labelled with a key, but the key is not an identity. They are
 * written by a model asked to name what it just learned, and any model asked to
 * label two unrelated things about a project reaches for the same obvious word
 * — "database", "config", "tests". A larger one collides less often, not never,
 * and "less often" is not a schema. A UNIQUE key with an
 * upsert turned that into silent data loss: the second fact deleted the first,
 * with no trace and nothing on screen. Nothing here overwrites; a correction is
 * an explicit supersede, and the replaced value stays on record.
 */
class MemoryStore
{
    /** Bumped when the shape below changes; open() migrates anything older. */
    private const SCHEMA_VERSION = 2;

    /** How deep digest() looks before it stops naming what it skipped. */
    private const DIGEST_CANDIDATES = 200;

    /** A fact quoted in full in the prompt; longer ones are elided. */
    private const DIGEST_MAX_VALUE = 200;

    /** Keys named in the overflow line before it says "and N others". */
    private const DIGEST_MAX_KEYS = 40;

    /** Share of the budget spent quoting facts; the rest indexes the leftovers. */
    private const DIGEST_DETAIL_SHARE = 0.75;

    /** Room kept for the ", et N autres." that closes the index line. */
    private const DIGEST_TAIL_RESERVE = 20;

    private ?Connection $conn = null;
    private ?string $currentDb = null;

    /** FTS5 is compiled into every SQLite worth using, but not into all of them. */
    private bool $fullText = false;

    public function open(string $dbPath): void
    {
        if ($this->currentDb === $dbPath && $this->conn !== null) {
            return;
        }

        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path'   => $dbPath,
        ]);

        $this->currentDb = $dbPath;
        $this->migrate();
    }

    /**
     * Record a fact. An identical one is left alone; a new one is added beside
     * whatever else shares its key.
     */
    public function remember(string $key, string $value): Recorded
    {
        $this->assertOpen();

        $existing = $this->conn->fetchOne(
            'SELECT id FROM facts WHERE key = :key AND value = :value',
            ['key' => $key, 'value' => $value],
        );

        $now = $this->now();

        if ($existing !== false) {
            // Seeing the same thing again is evidence it is still true, so the
            // fact is touched rather than left to age out of the summary.
            $this->conn->executeStatement(
                'UPDATE facts SET updated_at = :now, superseded_at = NULL WHERE id = :id',
                ['now' => $now, 'id' => $existing],
            );

            return Recorded::Unchanged;
        }

        $this->insert($key, $value, $now);

        return Recorded::Created;
    }

    /**
     * Replace what is currently known under a key. The previous values are kept
     * and stay searchable through history(); they simply stop being current.
     */
    public function supersede(string $key, string $value): Recorded
    {
        $this->assertOpen();
        $now = $this->now();

        $replaced = $this->conn->executeStatement(
            'UPDATE facts SET superseded_at = :now WHERE key = :key AND superseded_at IS NULL AND value <> :value',
            ['now' => $now, 'key' => $key, 'value' => $value],
        );

        $result = $this->remember($key, $value);

        return $replaced > 0 ? Recorded::Superseded : $result;
    }

    /**
     * @return array<int, Fact> current facts, best match first when searching
     */
    public function recall(?string $search = null, int $limit = 20): array
    {
        $this->assertOpen();

        $term = trim((string) $search);

        if ($term === '') {
            return $this->hydrate($this->conn->fetchAllAssociative(
                'SELECT * FROM facts WHERE superseded_at IS NULL ORDER BY updated_at DESC LIMIT :limit',
                ['limit' => $limit],
                ['limit' => ParameterType::INTEGER],
            ));
        }

        $rows = $this->fullText ? $this->searchFullText($term, $limit) : null;

        // No full-text index, or a query none of whose words are indexable.
        $rows ??= $this->conn->fetchAllAssociative(
            'SELECT * FROM facts
             WHERE superseded_at IS NULL AND (key LIKE :q OR value LIKE :q)
             ORDER BY updated_at DESC LIMIT :limit',
            ['q' => '%' . $term . '%', 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return $this->hydrate($rows);
    }

    /**
     * Everything ever recorded under a key, current and superseded, newest
     * first — the audit trail that the old upsert did not keep.
     *
     * @return array<int, Fact>
     */
    public function history(string $key): array
    {
        $this->assertOpen();

        return $this->hydrate($this->conn->fetchAllAssociative(
            'SELECT * FROM facts WHERE key = :key ORDER BY updated_at DESC, id DESC',
            ['key' => $key],
        ));
    }

    /** @return int how many rows were removed */
    public function forget(string $key): int
    {
        $this->assertOpen();

        return $this->conn->executeStatement('DELETE FROM facts WHERE key = :key', ['key' => $key]);
    }

    /**
     * What goes in the system prompt: as many facts as the budget affords,
     * then the keys of everything that did not fit.
     *
     * The old version took the 15 most recent and dropped the rest without
     * saying so. The model could not search for what it did not know existed,
     * and the count was arbitrary — fifteen one-line facts and fifteen
     * paragraphs cost very different amounts of a context window. Budgeting in
     * characters makes the cost predictable; listing the leftover keys makes
     * memory_recall a targeted lookup instead of a guess.
     */
    public function digest(int $maxChars = 1600): string
    {
        $this->assertOpen();

        // Recency is the only ranking available here: the system prompt is
        // built once, before anyone has asked anything. remember() touches a
        // fact each time it is seen again, so this is "recently confirmed"
        // rather than merely "recently written".
        $facts = $this->recall(limit: self::DIGEST_CANDIDATES);

        if ($facts === []) {
            return '(aucun fait mémorisé pour ce projet)';
        }

        // The index of what did not fit is part of the budget, not an extra on
        // top of it: a caller that asks for 1600 characters gets 1600.
        $detailBudget = (int) ($maxChars * self::DIGEST_DETAIL_SHARE);

        $lines = [];
        $spent = 0;
        $overflow = [];

        foreach ($facts as $fact) {
            $line = '- ' . $fact->key . ': ' . $this->elide($fact->value, self::DIGEST_MAX_VALUE);

            // Once one fact has overflowed, later ones follow it into the index
            // rather than jumping the queue because they happen to be shorter.
            if ($overflow !== [] || $spent + mb_strlen($line) > $detailBudget) {
                $overflow[$fact->key] = true;
                continue;
            }

            $lines[] = $line;
            $spent += mb_strlen($line) + 1;
        }

        if ($overflow !== []) {
            $lines[] = $this->index(array_keys($overflow), $maxChars - $spent);
        }

        return implode("\n", $lines);
    }

    /**
     * Name as many of the leftover keys as the remaining budget allows, and
     * count the rest. Naming none of them is still worth a line: knowing that
     * more exists is what sends the model to memory_recall.
     *
     * @param array<int, string> $keys
     */
    private function index(array $keys, int $room): string
    {
        $prefix = 'Autres faits connus, non détaillés ici — utilise memory_recall pour les lire : ';
        $used = mb_strlen($prefix) + self::DIGEST_TAIL_RESERVE;
        $named = [];

        foreach ($keys as $key) {
            if (count($named) >= self::DIGEST_MAX_KEYS || $used + mb_strlen($key) + 2 > $room) {
                break;
            }

            $named[] = $key;
            $used += mb_strlen($key) + 2;
        }

        $rest = count($keys) - count($named);
        $tail = $rest > 0 ? ($named === [] ? '' : ', ') . "et {$rest} autre" . ($rest > 1 ? 's' : '') : '';

        return $prefix . implode(', ', $named) . $tail . '.';
    }

    private function elide(string $value, int $max): string
    {
        return mb_strlen($value) <= $max ? $value : mb_substr($value, 0, $max - 1) . '…';
    }

    /**
     * Rank by relevance rather than by whether one string sits inside another.
     *
     * @return array<int, array<string, mixed>>|null null when the query has no
     *                                               indexable words left
     */
    private function searchFullText(string $term, int $limit): ?array
    {
        // Whatever the model puts in here is prose, and FTS5 reads punctuation
        // as query syntax: an unbalanced quote or a bare "AND" is a syntax
        // error, not zero results. Keep the words, quote them, drop the rest.
        preg_match_all('/[\p{L}\p{N}_]+/u', $term, $matches);
        $words = array_filter($matches[0], fn(string $w) => mb_strlen($w) > 1);

        if ($words === []) {
            return null;
        }

        $query = implode(' OR ', array_map(fn(string $w) => '"' . $w . '"', $words));

        try {
            return $this->conn->fetchAllAssociative(
                'SELECT f.* FROM facts_fts
                 JOIN facts f ON f.id = facts_fts.rowid
                 WHERE facts_fts MATCH :q AND f.superseded_at IS NULL
                 ORDER BY bm25(facts_fts, 2.0, 1.0) LIMIT :limit',
                ['q' => $query, 'limit' => $limit],
                ['limit' => ParameterType::INTEGER],
            );
        } catch (\Throwable) {
            // A malformed expression must degrade to a worse search, never to a
            // broken tool call in the middle of a turn.
            return null;
        }
    }

    private function insert(string $key, string $value, string $now): void
    {
        $this->conn->executeStatement(
            'INSERT INTO facts (key, value, created_at, updated_at) VALUES (:key, :value, :now, :now)',
            ['key' => $key, 'value' => $value, 'now' => $now],
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, Fact>
     */
    private function hydrate(array $rows): array
    {
        return array_map(fn(array $row) => new Fact(
            id: (int) $row['id'],
            key: (string) $row['key'],
            value: (string) $row['value'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            updatedAt: new \DateTimeImmutable((string) $row['updated_at']),
            supersededAt: $row['superseded_at'] === null ? null : new \DateTimeImmutable((string) $row['superseded_at']),
        ), $rows);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
    }

    private function migrate(): void
    {
        $version = (int) $this->conn->fetchOne('PRAGMA user_version');

        if ($version < self::SCHEMA_VERSION) {
            $this->conn->executeStatement(<<<SQL
                CREATE TABLE IF NOT EXISTS facts_v2 (
                    id            INTEGER PRIMARY KEY AUTOINCREMENT,
                    key           TEXT    NOT NULL,
                    value         TEXT    NOT NULL,
                    created_at    TEXT    NOT NULL,
                    updated_at    TEXT    NOT NULL,
                    superseded_at TEXT    NULL,
                    UNIQUE(key, value)
                )
            SQL);

            // Version 1 kept one row per key. Those facts are the survivors of
            // the collisions this schema exists to stop; carry them over.
            $legacy = $this->conn->fetchOne(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'facts'",
            );

            if ($legacy !== false) {
                $this->conn->executeStatement(
                    'INSERT OR IGNORE INTO facts_v2 (key, value, created_at, updated_at)
                     SELECT key, value, updated_at, updated_at FROM facts',
                );
                $this->conn->executeStatement('DROP TABLE facts');
            }

            $this->conn->executeStatement('ALTER TABLE facts_v2 RENAME TO facts');
            $this->conn->executeStatement('PRAGMA user_version = ' . self::SCHEMA_VERSION);
        }

        $this->conn->executeStatement('CREATE INDEX IF NOT EXISTS facts_current ON facts (superseded_at, updated_at)');
        $this->fullText = $this->buildFullTextIndex();
    }

    /**
     * An external-content FTS5 index over the facts table, kept in step by
     * triggers. Returns false where FTS5 is missing, which only costs recall
     * its ranking — LIKE still answers.
     */
    private function buildFullTextIndex(): bool
    {
        try {
            $this->conn->executeStatement(
                "CREATE VIRTUAL TABLE IF NOT EXISTS facts_fts
                 USING fts5(key, value, content='facts', content_rowid='id', tokenize='unicode61 remove_diacritics 2')",
            );

            $this->conn->executeStatement(<<<SQL
                CREATE TRIGGER IF NOT EXISTS facts_fts_insert AFTER INSERT ON facts BEGIN
                    INSERT INTO facts_fts (rowid, key, value) VALUES (new.id, new.key, new.value);
                END
            SQL);
            $this->conn->executeStatement(<<<SQL
                CREATE TRIGGER IF NOT EXISTS facts_fts_delete AFTER DELETE ON facts BEGIN
                    INSERT INTO facts_fts (facts_fts, rowid, key, value) VALUES ('delete', old.id, old.key, old.value);
                END
            SQL);
            $this->conn->executeStatement(<<<SQL
                CREATE TRIGGER IF NOT EXISTS facts_fts_update AFTER UPDATE OF key, value ON facts BEGIN
                    INSERT INTO facts_fts (facts_fts, rowid, key, value) VALUES ('delete', old.id, old.key, old.value);
                    INSERT INTO facts_fts (rowid, key, value) VALUES (new.id, new.key, new.value);
                END
            SQL);

            // Rows carried over from version 1 predate the triggers.
            if ((int) $this->conn->fetchOne('SELECT count(*) FROM facts_fts') === 0) {
                $this->conn->executeStatement("INSERT INTO facts_fts (facts_fts) VALUES ('rebuild')");
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertOpen(): void
    {
        if ($this->conn === null) {
            throw new \LogicException('MemoryStore: no database opened. Call open($dbPath) first.');
        }
    }
}

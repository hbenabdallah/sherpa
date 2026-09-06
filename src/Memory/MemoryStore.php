<?php

namespace App\Memory;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;

/**
 * Per-project long-term memory. A fact's key is a label, never an identity:
 * models asked to name two unrelated things reach for the same obvious word,
 * and a UNIQUE key with an upsert made that silent data loss. Nothing here
 * overwrites — a correction supersedes, and the old value stays on record.
 */
class MemoryStore
{
    /** Bumped when the shape below changes; open() migrates anything older. */
    private const SCHEMA_VERSION = 4;

    /**
     * How the digest ranks. Situation leads: it is the only signal that knows
     * what today is about. Usage is the long-run one — a fact the model keeps
     * returning to belongs in the prompt. Recency is what is left.
     */
    private const WEIGHT_SITUATION = 0.50;
    private const WEIGHT_USAGE     = 0.25;
    private const WEIGHT_RECENCY   = 0.25;

    /** Recalls past this stop counting; the signal is "used", not "popular". */
    private const USAGE_SATURATION = 5;

    /** Situation terms matched for full marks. One strong hit already scores. */
    private const SITUATION_SATURATION = 3;

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
     * What goes in the system prompt: as many facts as the budget affords, then
     * the keys of everything that did not fit. Budgeting in characters keeps
     * the cost predictable — fifteen one-line facts and fifteen paragraphs are
     * not the same — and the leftover keys make memory_recall a lookup.
     */
    /**
     * @param string[] $terms what this session appears to be about — see
     *                        RecentActivity. Empty is fine and simply removes
     *                        the strongest of the three ranking signals.
     */
    public function digest(int $maxChars = 1600, array $terms = []): string
    {
        $this->assertOpen();

        // Recency was the only ranking, since the prompt is built before
        // anyone has asked anything — but no query is not no information.
        // What git touched and where the user launched cost nothing to ask.
        $facts = $this->rank($this->recall(limit: self::DIGEST_CANDIDATES), $terms);

        if ($facts === []) {
            return '(nothing remembered for this project)';
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
     * Order the candidates by what this session is likely to need.
     *
     * @param  array<int, Fact> $facts recency-ordered, as recall() returns them
     * @param  string[]         $terms
     * @return array<int, Fact>
     */
    private function rank(array $facts, array $terms): array
    {
        $total = count($facts);
        if ($total <= 1) {
            return $facts;
        }

        $scored = [];

        foreach ($facts as $position => $fact) {
            $recency = 1.0 - ($position / ($total - 1));
            $usage = min($fact->recalledCount, self::USAGE_SATURATION) / self::USAGE_SATURATION;

            $scored[] = [
                'fact'  => $fact,
                'score' => ($this->situationScore($fact, $terms) * self::WEIGHT_SITUATION)
                    + ($usage * self::WEIGHT_USAGE)
                    + ($recency * self::WEIGHT_RECENCY),
            ];
        }

        // PHP's sort is stable, so facts that score alike keep their recency
        // order rather than being shuffled by the comparison.
        usort($scored, static fn(array $a, array $b) => $b['score'] <=> $a['score']);

        return array_column($scored, 'fact');
    }

    /**
     * How much this fact has to do with what is being worked on right now.
     *
     * @param string[] $terms
     */
    private function situationScore(Fact $fact, array $terms): float
    {
        if ($terms === []) {
            return 0.0;
        }

        $haystack = mb_strtolower($fact->key . ' ' . $fact->value);
        $hits = 0;

        foreach ($terms as $term) {
            if ($term !== '' && str_contains($haystack, $term)) {
                $hits++;
            }
        }

        return $hits === 0 ? 0.0 : min(1.0, $hits / self::SITUATION_SATURATION);
    }

    /**
     * Record that these facts were looked up on purpose. Not called by recall()
     * itself: digest() goes through it, and counting that would mark every fact
     * used every session. Only an explicit memory_recall counts.
     *
     * @param array<int, int> $ids
     */
    public function markRecalled(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->assertOpen();

        $this->conn->executeStatement(
            'UPDATE facts SET recalled_count = recalled_count + 1, last_recalled_at = :now WHERE id IN (:ids)',
            ['now' => $this->now(), 'ids' => array_map(intval(...), $ids)],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }

    /**
     * Add one to a per-project counter — the instruments that settle the open
     * questions about retrieval with a number rather than an impression:
     * recall_hits/misses (a search coming back empty on a project that has
     * facts is a vocabulary mismatch), grep_hits/misses for the code, and
     * grep_direct/groping — found in one search, or guessed at three patterns
     * first. Silent when no database is open: not worth an exception mid-call.
     */
    public function bump(string $counter): void
    {
        if ($this->conn === null) {
            return;
        }

        $this->conn->executeStatement(
            'INSERT INTO project_stats (key, value) VALUES (:key, 1)
             ON CONFLICT(key) DO UPDATE SET value = value + 1',
            ['key' => $counter],
        );
    }

    /** @return array<string, int> every counter this project has accumulated */
    public function counters(): array
    {
        if ($this->conn === null) {
            return [];
        }

        return array_map(intval(...), $this->conn->fetchAllKeyValue('SELECT key, value FROM project_stats'));
    }

    public function noteRecall(bool $found): void
    {
        $this->bump($found ? 'recall_hits' : 'recall_misses');
    }

    /** @return array{hits: int, misses: int} */
    public function recallStats(): array
    {
        $counters = $this->counters();

        return [
            'hits'   => $counters['recall_hits'] ?? 0,
            'misses' => $counters['recall_misses'] ?? 0,
        ];
    }

    /**
     * How the model's code searches went.
     *
     * @return array{hits: int, misses: int, direct: int, groping: int}
     */
    public function searchStats(): array
    {
        $counters = $this->counters();

        return [
            'hits'    => $counters['grep_hits'] ?? 0,
            'misses'  => $counters['grep_misses'] ?? 0,
            'direct'  => $counters['grep_direct'] ?? 0,
            'groping' => $counters['grep_groping'] ?? 0,
        ];
    }

    public function isOpen(): bool
    {
        return $this->conn !== null;
    }

    /** Facts in force — what forgetting the project would throw away. */
    public function count(): int
    {
        if ($this->conn === null) {
            return 0;
        }

        return (int) $this->conn->fetchOne('SELECT count(*) FROM facts WHERE superseded_at IS NULL');
    }

    /**
     * Name as many leftover keys as the budget allows, and count the rest:
     * knowing more exists is what sends the model to memory_recall.
     *
     * @param array<int, string> $keys
     */
    private function index(array $keys, int $room): string
    {
        $prefix = 'Other facts on record, not quoted here — read them with memory_recall: ';
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
            recalledCount: (int) ($row['recalled_count'] ?? 0),
            lastRecalledAt: ($row['last_recalled_at'] ?? null) === null
                ? null
                : new \DateTimeImmutable((string) $row['last_recalled_at']),
        ), $rows);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
    }

    private function migrate(): void
    {
        $version = (int) $this->conn->fetchOne('PRAGMA user_version');

        if ($version < 2) {
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
            $this->conn->executeStatement('PRAGMA user_version = 2');
            $version = 2;
        }

        // Version 3 records whether a fact ever earned its place. Added rather
        // than rebuilt: the columns default, so every existing fact starts at
        // zero recalls and simply ranks on the two signals it always had.
        if ($version < 3) {
            $this->addColumn('facts', 'recalled_count', 'INTEGER NOT NULL DEFAULT 0');
            $this->addColumn('facts', 'last_recalled_at', 'TEXT NULL');
            $this->conn->executeStatement('PRAGMA user_version = 3');
        }

        $this->conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS project_stats (key TEXT PRIMARY KEY, value INTEGER NOT NULL DEFAULT 0)',
        );

        // Version 4 renames memory_stats. The table had stopped being about
        // memory the moment code searches started being counted too, and a
        // table whose name lies is the kind of thing that misleads whoever
        // reads it in six months.
        if ($version < 4) {
            $legacyStats = $this->conn->fetchOne(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'memory_stats'",
            );

            if ($legacyStats !== false) {
                $this->conn->executeStatement(
                    'INSERT OR IGNORE INTO project_stats (key, value) SELECT key, value FROM memory_stats',
                );
                $this->conn->executeStatement('DROP TABLE memory_stats');
            }

            $this->conn->executeStatement('PRAGMA user_version = 4');
        }

        $this->conn->executeStatement('CREATE INDEX IF NOT EXISTS facts_current ON facts (superseded_at, updated_at)');
        $this->fullText = $this->buildFullTextIndex();
    }

    /**
     * ALTER TABLE ADD COLUMN, skipped when the column is there: SQLite has no
     * IF NOT EXISTS for columns, and open() runs on every session.
     */
    private function addColumn(string $table, string $column, string $definition): void
    {
        foreach ($this->conn->fetchAllAssociative("PRAGMA table_info({$table})") as $existing) {
            if (($existing['name'] ?? null) === $column) {
                return;
            }
        }

        $this->conn->executeStatement("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
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

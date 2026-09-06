<?php

declare(strict_types=1);

namespace App\Memory;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;

/**
 * What compaction took out of the window, still reachable: elision is a swap
 * rather than a delete. The body goes here on its way out, indexed, and
 * context_recall pages it back in — where running the tool again would cost
 * another turn to recover text Sherpa had already read.
 *
 * In memory, because it is the content of one conversation and lives exactly as
 * long: /reset empties it, and a saved session carries it (export(), import())
 * under the same ids, since the stubs in that history quote them.
 */
final class ContextStore
{
    /** Lines of context kept either side of a hit. */
    private const CONTEXT_LINES = 2;

    /** Separate excerpts returned for one search, at most. */
    private const MAX_EXCERPTS = 6;

    /**
     * Lines one excerpt may contribute before it stops being an excerpt: a grep
     * where every line matches is the whole file. The overflow is counted, not
     * dropped — that is what tells the model to narrow its question.
     */
    private const MAX_EXCERPT_LINES = 40;

    private ?Connection $conn = null;
    private bool $fullText = false;

    public function open(): void
    {
        if ($this->conn !== null) {
            return;
        }

        $this->conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->conn->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS excerpts (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                tool       TEXT    NOT NULL,
                content    TEXT    NOT NULL,
                lines      INTEGER NOT NULL,
                chars      INTEGER NOT NULL,
                created_at TEXT    NOT NULL
            )
        SQL);

        $this->fullText = $this->buildFullTextIndex();
    }

    /**
     * Take custody of a tool result on its way out of the window.
     *
     * @return int the id the stub will quote, so the model can ask for this one
     *             specifically rather than describing it
     */
    public function keep(string $tool, string $content): int
    {
        $this->open();

        $this->conn->executeStatement(
            'INSERT INTO excerpts (tool, content, lines, chars, created_at) VALUES (:t, :c, :l, :n, :now)',
            [
                't'   => $tool,
                'c'   => $content,
                'l'   => substr_count($content, "\n") + 1,
                'n'   => mb_strlen($content),
                'now' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
        );

        return (int) $this->conn->lastInsertId();
    }

    public function count(): int
    {
        $this->open();

        return (int) $this->conn->fetchOne('SELECT count(*) FROM excerpts');
    }

    /**
     * One excerpt in full, capped.
     *
     * The cap is not ceremony: handing back the whole 18 000 characters that
     * were just elided would undo the compaction that put them here.
     */
    public function get(int $id, int $maxChars): ?string
    {
        $this->open();

        $row = $this->conn->fetchAssociative(
            'SELECT tool, content, lines FROM excerpts WHERE id = :id',
            ['id' => $id],
            ['id' => ParameterType::INTEGER],
        );

        if ($row === false) {
            return null;
        }

        $content = (string) $row['content'];
        $header = "[#{$id} · {$row['tool']} · {$row['lines']} lines]";

        if (mb_strlen($content) > $maxChars) {
            $content = mb_substr($content, 0, $maxChars)
                . "\n… (truncated; narrow it down with context_recall(search) to reach one passage)";
        }

        return $header . "\n" . $content;
    }

    /**
     * Passages matching a query, with their surrounding lines — what a person
     * would copy out of a long file. Whole excerpts would defeat the point.
     *
     * @return string '' when nothing matched
     */
    public function search(string $query, int $maxChars): string
    {
        $this->open();

        $words = $this->words($query);
        if ($words === []) {
            return '';
        }

        $rows = $this->matchingRows($words);
        if ($rows === []) {
            return '';
        }

        $out = [];
        $spent = 0;

        foreach ($rows as $row) {
            $excerpt = $this->excerptFrom((string) $row['content'], $words);
            if ($excerpt === '') {
                continue;
            }

            $block = "[#{$row['id']} · {$row['tool']}]\n" . $excerpt;

            if ($spent + mb_strlen($block) > $maxChars) {
                // Dropping it outright would report "nothing found" on a search
                // that matched — which is exactly the case this store is for,
                // a single result too big to have been left in the window.
                if ($out !== []) {
                    break;
                }

                $block = mb_substr($block, 0, max(0, $maxChars - 60))
                    . "\n… (cut at the budget; narrow the search)";
            }

            $out[] = $block;
            $spent += mb_strlen($block) + 2;
        }

        return implode("\n\n", $out);
    }

    /**
     * @param string[] $words
     *
     * @return array<int, array<string, mixed>>
     */
    private function matchingRows(array $words): array
    {
        if ($this->fullText) {
            $expression = implode(' OR ', array_map(fn(string $w) => '"' . $w . '"', $words));

            try {
                return $this->conn->fetchAllAssociative(
                    'SELECT e.id, e.tool, e.content FROM excerpts_fts
                     JOIN excerpts e ON e.id = excerpts_fts.rowid
                     WHERE excerpts_fts MATCH :q
                     ORDER BY bm25(excerpts_fts) LIMIT :limit',
                    ['q' => $expression, 'limit' => self::MAX_EXCERPTS],
                    ['limit' => ParameterType::INTEGER],
                );
            } catch (\Throwable) {
                // A malformed expression degrades to a worse search, never to a
                // broken tool call in the middle of a turn.
            }
        }

        $where = implode(' OR ', array_map(
            static fn(int $i) => 'content LIKE :w' . $i,
            array_keys($words),
        ));

        $params = ['limit' => self::MAX_EXCERPTS];
        foreach ($words as $i => $word) {
            $params['w' . $i] = '%' . $word . '%';
        }

        return $this->conn->fetchAllAssociative(
            "SELECT id, tool, content FROM excerpts WHERE {$where} ORDER BY id DESC LIMIT :limit",
            $params,
            ['limit' => ParameterType::INTEGER],
        );
    }

    /**
     * The matching lines of one excerpt, with context, runs merged.
     *
     * @param string[] $words
     */
    private function excerptFrom(string $content, array $words): string
    {
        $lines = explode("\n", $content);
        $keep = [];

        foreach ($lines as $i => $line) {
            $haystack = mb_strtolower($line);

            foreach ($words as $word) {
                if (str_contains($haystack, $word)) {
                    for ($j = max(0, $i - self::CONTEXT_LINES); $j <= min(count($lines) - 1, $i + self::CONTEXT_LINES); $j++) {
                        $keep[$j] = true;
                    }
                    break;
                }
            }
        }

        if ($keep === []) {
            return '';
        }

        ksort($keep);

        $wanted = array_keys($keep);
        $dropped = count($wanted) - self::MAX_EXCERPT_LINES;
        if ($dropped > 0) {
            $wanted = array_slice($wanted, 0, self::MAX_EXCERPT_LINES);
        }

        $out = [];
        $previous = null;

        foreach ($wanted as $i) {
            // A gap between two runs is marked rather than closed silently, so
            // the model can tell adjacent lines from distant ones.
            if ($previous !== null && $i > $previous + 1) {
                $out[] = '   …';
            }

            $out[] = sprintf('%5d | %s', $i + 1, $lines[$i]);
            $previous = $i;
        }

        if ($dropped > 0) {
            $out[] = "   … {$dropped} more matching line(s); narrow the search.";
        }

        return implode("\n", $out);
    }

    /**
     * Indexable words of a query. Same treatment as MemoryStore: whatever the
     * model puts in here is prose, and FTS5 reads punctuation as query syntax.
     *
     * @return string[]
     */
    private function words(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}_]+/u', mb_strtolower(trim($query)), $matches);

        return array_values(array_filter($matches[0], static fn(string $w) => mb_strlen($w) > 1));
    }

    private function buildFullTextIndex(): bool
    {
        try {
            $this->conn->executeStatement(
                "CREATE VIRTUAL TABLE IF NOT EXISTS excerpts_fts
                 USING fts5(content, content='excerpts', content_rowid='id', tokenize='unicode61 remove_diacritics 2')",
            );

            $this->conn->executeStatement(<<<SQL
                CREATE TRIGGER IF NOT EXISTS excerpts_fts_insert AFTER INSERT ON excerpts BEGIN
                    INSERT INTO excerpts_fts (rowid, content) VALUES (new.id, new.content);
                END
            SQL);
            $this->conn->executeStatement(<<<SQL
                CREATE TRIGGER IF NOT EXISTS excerpts_fts_delete AFTER DELETE ON excerpts BEGIN
                    INSERT INTO excerpts_fts (excerpts_fts, rowid, content) VALUES ('delete', old.id, old.content);
                END
            SQL);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** The conversation these belonged to is gone; so are they. */
    public function clear(): void
    {
        if ($this->conn === null) {
            return;
        }

        $this->conn->executeStatement('DELETE FROM excerpts');
    }

    /**
     * Everything held, to be saved with the conversation it belongs to.
     *
     * @return list<array{id: int, tool: string, content: string, created_at: string}>
     */
    public function export(): array
    {
        if ($this->conn === null) {
            return [];
        }

        return array_map(
            static fn(array $row) => [
                'id'         => (int) $row['id'],
                'tool'       => (string) $row['tool'],
                'content'    => (string) $row['content'],
                'created_at' => (string) $row['created_at'],
            ],
            $this->conn->fetchAllAssociative('SELECT id, tool, content, created_at FROM excerpts ORDER BY id'),
        );
    }

    /**
     * Put back what a resumed conversation set aside, under the same ids: the
     * stubs in that history say `context_recall(id: 3)`, and renumbering would
     * answer with somebody else's excerpt. What the store held before goes.
     *
     * @param list<array<string, mixed>> $excerpts
     */
    public function import(array $excerpts): void
    {
        $this->open();
        $this->clear();

        foreach ($excerpts as $excerpt) {
            $content = (string) ($excerpt['content'] ?? '');
            $id = (int) ($excerpt['id'] ?? 0);

            if ($id <= 0 || $content === '') {
                continue;
            }

            $this->conn->executeStatement(
                'INSERT INTO excerpts (id, tool, content, lines, chars, created_at) VALUES (:id, :t, :c, :l, :n, :at)',
                [
                    'id' => $id,
                    't'  => (string) ($excerpt['tool'] ?? 'tool'),
                    'c'  => $content,
                    'l'  => substr_count($content, "\n") + 1,
                    'n'  => mb_strlen($content),
                    'at' => (string) ($excerpt['created_at'] ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)),
                ],
                ['id' => ParameterType::INTEGER],
            );
        }
    }
}

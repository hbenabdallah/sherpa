<?php

declare(strict_types=1);

namespace App\Rag;

use App\Rag\Embedding\Vectors;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;

/**
 * Where a project's documentation is kept, searchable: SQLite, one database per
 * project beside its memory, rather than a vector database to install and run.
 * The corpus is a few thousand chunks at most, FTS5 gives BM25, and embeddings
 * add a column rather than a server. Each document keeps the size, date and
 * hash it was indexed at, which is all a re-index needs.
 */
final class DocIndex
{
    private const SCHEMA = 2;

    private ?Connection $conn = null;
    private bool $fullText = false;

    /**
     * The vectors, read once and kept: a search compares the question with
     * every one of them, and unpacking a few thousand blobs per question would
     * cost more than the comparison. Dropped at the first write.
     *
     * @var array<int, list<float>>|null
     */
    private ?array $vectorCache = null;

    public function open(string $dbPath): void
    {
        if ($dbPath !== ':memory:' && !is_dir(dirname($dbPath))) {
            mkdir(dirname($dbPath), 0755, true);
        }

        $this->conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $this->vectorCache = null;
        $this->migrate();
    }

    public function isOpen(): bool
    {
        return $this->conn !== null;
    }

    /** @return array{hash: string, size: int, mtime: int}|null */
    public function document(string $path): ?array
    {
        $row = $this->db()->fetchAssociative('SELECT hash, size, mtime FROM documents WHERE path = :p', ['p' => $path]);

        return $row === false ? null : ['hash' => (string) $row['hash'], 'size' => (int) $row['size'], 'mtime' => (int) $row['mtime']];
    }

    /** @param list<Chunk> $chunks */
    public function replace(string $path, string $hash, int $size, int $mtime, ParsedDocument $document, array $chunks): void
    {
        $this->vectorCache = null;
        $db = $this->db();
        $db->beginTransaction();

        try {
            $db->executeStatement('DELETE FROM chunks WHERE path = :p', ['p' => $path]);
            $db->executeStatement(
                'INSERT OR REPLACE INTO documents (path, hash, size, mtime, title, type, chunks, indexed_at)
                 VALUES (:p, :h, :s, :m, :t, :ty, :n, :at)',
                ['p' => $path, 'h' => $hash, 's' => $size, 'm' => $mtime, 't' => $document->title,
                 'ty' => $document->type, 'n' => count($chunks), 'at' => date(DATE_ATOM)],
            );

            foreach ($chunks as $chunk) {
                $db->executeStatement(
                    'INSERT INTO chunks (path, position, title, headings, context, text, parent, start_line, end_line)
                     VALUES (:p, :pos, :t, :h, :c, :x, :pa, :s, :e)',
                    ['p' => $path, 'pos' => $chunk->position, 't' => $chunk->title,
                     'h' => json_encode($chunk->headings, JSON_UNESCAPED_UNICODE), 'c' => $chunk->context(),
                     'x' => $chunk->text, 'pa' => $chunk->parent, 's' => $chunk->startLine, 'e' => $chunk->endLine],
                );
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** Same content, new date: a file touched but not changed. */
    public function touch(string $path, int $size, int $mtime): void
    {
        $this->db()->executeStatement('UPDATE documents SET size = :s, mtime = :m WHERE path = :p', ['s' => $size, 'm' => $mtime, 'p' => $path]);
    }

    public function remove(string $path): void
    {
        $this->vectorCache = null;
        $this->db()->executeStatement('DELETE FROM chunks WHERE path = :p', ['p' => $path]);
        $this->db()->executeStatement('DELETE FROM documents WHERE path = :p', ['p' => $path]);
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_map('strval', $this->db()->fetchFirstColumn('SELECT path FROM documents ORDER BY path'));
    }

    /** @return array{documents: int, chunks: int} */
    public function counts(): array
    {
        return [
            'documents' => (int) $this->db()->fetchOne('SELECT count(*) FROM documents'),
            'chunks'    => (int) $this->db()->fetchOne('SELECT count(*) FROM chunks'),
        ];
    }

    /** The model the stored vectors were made with, '' before any. */
    public function embeddingModel(): string
    {
        return (string) ($this->db()->fetchOne("SELECT value FROM meta WHERE key = 'embedding_model'") ?: '');
    }

    /**
     * Forget every vector and start over with $model: vectors from two models
     * live in two unrelated spaces, and one index never mixes them.
     */
    public function resetVectors(string $model): void
    {
        $this->vectorCache = null;
        $this->db()->executeStatement('DELETE FROM vectors');
        $this->db()->executeStatement("INSERT OR REPLACE INTO meta (key, value) VALUES ('embedding_model', :m)", ['m' => $model]);
    }

    /**
     * Chunks that have no vector yet, with what to embed for each: the path
     * they sit under, then their text.
     *
     * @return list<array{id: int, text: string}>
     */
    public function unembedded(int $limit): array
    {
        return array_map(
            static fn(array $row) => ['id' => (int) $row['id'], 'text' => $row['context'] . "\n\n" . $row['text']],
            $this->db()->fetchAllAssociative(
                'SELECT c.id, c.context, c.text FROM chunks c LEFT JOIN vectors v ON v.chunk_id = c.id
                 WHERE v.chunk_id IS NULL ORDER BY c.id LIMIT :limit',
                ['limit' => $limit],
                ['limit' => ParameterType::INTEGER],
            ),
        );
    }

    /** @param array<int, list<float>> $vectors chunk id => vector */
    public function storeVectors(array $vectors): void
    {
        $this->vectorCache = null;
        $db = $this->db();
        $db->beginTransaction();

        foreach ($vectors as $id => $vector) {
            $db->executeStatement(
                'INSERT OR REPLACE INTO vectors (chunk_id, vector) VALUES (:id, :v)',
                ['id' => $id, 'v' => Vectors::pack($vector)],
                ['id' => ParameterType::INTEGER, 'v' => ParameterType::LARGE_OBJECT],
            );
        }

        $db->commit();
    }

    public function vectorCount(): int
    {
        return (int) $this->db()->fetchOne('SELECT count(*) FROM vectors');
    }

    /** @return array<int, list<float>> chunk id => vector */
    public function vectors(): array
    {
        if ($this->vectorCache === null) {
            $this->vectorCache = [];
            foreach ($this->db()->fetchAllAssociative('SELECT chunk_id, vector FROM vectors') as $row) {
                $this->vectorCache[(int) $row['chunk_id']] = Vectors::unpack((string) $row['vector']);
            }
        }

        return $this->vectorCache;
    }

    public function chunk(int $id): ?Chunk
    {
        $row = $this->db()->fetchAssociative('SELECT * FROM chunks WHERE id = :id', ['id' => $id], ['id' => ParameterType::INTEGER]);

        return $row === false ? null : self::toChunk($row);
    }

    /**
     * BM25 over the chunks, the path they sit under weighing three times their
     * text: a heading is what a passage is about, in the author's own words.
     *
     * @param list<string> $terms
     *
     * @return list<Hit>
     */
    public function bm25(array $terms, int $limit, bool $prefix = false): array
    {
        if ($terms === []) {
            return [];
        }

        $rows = [];

        if ($this->fullText) {
            $expression = implode(' OR ', array_map(
                static fn(string $t) => '"' . str_replace('"', '', $t) . '"' . ($prefix ? '*' : ''),
                $terms,
            ));

            try {
                $rows = $this->db()->fetchAllAssociative(
                    'SELECT c.*, bm25(chunks_fts, 3.0, 1.0) AS score FROM chunks_fts
                     JOIN chunks c ON c.id = chunks_fts.rowid
                     WHERE chunks_fts MATCH :q ORDER BY score LIMIT :limit',
                    ['q' => $expression, 'limit' => $limit],
                    ['limit' => ParameterType::INTEGER],
                );
            } catch (\Throwable) {
                // A malformed expression degrades to the fallback below.
                $rows = [];
            }
        }

        if ($rows === [] && !$this->fullText) {
            $rows = $this->like($terms, $limit);
        }

        $hits = [];
        foreach ($rows as $rank => $row) {
            // FTS5's bm25() is negative, lower meaning better: turned around,
            // so a higher score is a better match everywhere in this package.
            $hits[] = new Hit((int) $row['id'], self::toChunk($row), -(float) ($row['score'] ?? 0), $rank + 1);
        }

        return $hits;
    }

    /**
     * Without FTS5, a search that still finds something. Ranked by how many
     * terms a chunk contains — crude, and never used where FTS5 exists.
     *
     * @param list<string> $terms
     *
     * @return list<array<string, mixed>>
     */
    private function like(array $terms, int $limit): array
    {
        $score = implode(' + ', array_map(static fn(int $i) => "(CASE WHEN lower(context || ' ' || text) LIKE :t{$i} THEN 1 ELSE 0 END)", array_keys($terms)));
        $params = ['limit' => $limit];
        foreach ($terms as $i => $term) {
            $params['t' . $i] = '%' . $term . '%';
        }

        return $this->db()->fetchAllAssociative(
            "SELECT *, ({$score}) AS score FROM chunks WHERE ({$score}) > 0 ORDER BY score DESC LIMIT :limit",
            $params,
            ['limit' => ParameterType::INTEGER],
        );
    }

    /** @param array<string, mixed> $row */
    private static function toChunk(array $row): Chunk
    {
        $headings = json_decode((string) ($row['headings'] ?? '[]'), true);

        return new Chunk(
            path: (string) $row['path'],
            title: (string) $row['title'],
            headings: is_array($headings) ? array_values(array_map('strval', $headings)) : [],
            text: (string) $row['text'],
            parent: (string) $row['parent'],
            startLine: (int) $row['start_line'],
            endLine: (int) $row['end_line'],
            position: (int) $row['position'],
        );
    }

    private function db(): Connection
    {
        return $this->conn ?? throw new \LogicException('Index de documentation non ouvert.');
    }

    private function migrate(): void
    {
        $db = $this->db();

        $db->executeStatement('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $version = (int) ($db->fetchOne("SELECT value FROM meta WHERE key = 'schema'") ?: 0);

        if ($version < self::SCHEMA) {
            // An index is derived data: rebuilt from the documents, never
            // migrated. Whatever an older version kept is dropped and the next
            // sync reads everything again.
            $db->executeStatement('DROP TABLE IF EXISTS vectors');
            $db->executeStatement('DROP TABLE IF EXISTS chunks_fts');
            $db->executeStatement('DROP TABLE IF EXISTS chunks');
            $db->executeStatement('DROP TABLE IF EXISTS documents');
        }

        $db->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS documents (
                path TEXT PRIMARY KEY, hash TEXT NOT NULL, size INTEGER NOT NULL, mtime INTEGER NOT NULL,
                title TEXT NOT NULL, type TEXT NOT NULL, chunks INTEGER NOT NULL, indexed_at TEXT NOT NULL
            )
        SQL);
        $db->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS chunks (
                id INTEGER PRIMARY KEY AUTOINCREMENT, path TEXT NOT NULL, position INTEGER NOT NULL,
                title TEXT NOT NULL, headings TEXT NOT NULL, context TEXT NOT NULL, text TEXT NOT NULL,
                parent TEXT NOT NULL, start_line INTEGER NOT NULL, end_line INTEGER NOT NULL
            )
        SQL);
        $db->executeStatement('CREATE INDEX IF NOT EXISTS chunks_path ON chunks (path)');

        // A chunk's vector goes with it: a document re-indexed gets new chunks
        // and new ids, and its old vectors would otherwise point at nothing.
        $db->executeStatement('CREATE TABLE IF NOT EXISTS vectors (chunk_id INTEGER PRIMARY KEY, vector BLOB NOT NULL)');
        $db->executeStatement(<<<SQL
            CREATE TRIGGER IF NOT EXISTS chunks_vectors_delete AFTER DELETE ON chunks BEGIN
                DELETE FROM vectors WHERE chunk_id = old.id;
            END
        SQL);

        try {
            $db->executeStatement(
                "CREATE VIRTUAL TABLE IF NOT EXISTS chunks_fts USING fts5(context, text,
                 content='chunks', content_rowid='id', tokenize='unicode61 remove_diacritics 2')",
            );
            $db->executeStatement(<<<SQL
                CREATE TRIGGER IF NOT EXISTS chunks_fts_insert AFTER INSERT ON chunks BEGIN
                    INSERT INTO chunks_fts (rowid, context, text) VALUES (new.id, new.context, new.text);
                END
            SQL);
            $db->executeStatement(<<<SQL
                CREATE TRIGGER IF NOT EXISTS chunks_fts_delete AFTER DELETE ON chunks BEGIN
                    INSERT INTO chunks_fts (chunks_fts, rowid, context, text) VALUES ('delete', old.id, old.context, old.text);
                END
            SQL);
            $this->fullText = true;
        } catch (\Throwable) {
            $this->fullText = false;
        }

        $db->executeStatement("INSERT OR REPLACE INTO meta (key, value) VALUES ('schema', :v)", ['v' => (string) self::SCHEMA]);
    }
}

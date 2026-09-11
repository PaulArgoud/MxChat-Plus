<?php
/**
 * Vector store on top of a DuckDB connection. Orchestrator that delegates:
 *
 *   - schema lifecycle  → MxChat_Plus_DuckDB_Vector_Store_Schema
 *   - read / top-K path → MxChat_Plus_DuckDB_Vector_Store_Query
 *
 * Owns the write path (upsert, delete), the lifecycle helpers (count,
 * list_ids, fetch_by_ids), and the Parquet I/O. Keeps the public API stable
 * — existing call-sites (sync, REST proxy, admin, CLI, tests) all keep
 * compiling without change.
 *
 * Schema (managed by Vector_Store_Schema, migration target v3):
 *   CREATE TABLE mxchat_vectors (
 *       vector_id        VARCHAR PRIMARY KEY,
 *       bot_id           VARCHAR DEFAULT 'default',
 *       embedding        FLOAT[<dim>] or TINYINT[<dim>] when embedding_storage='int8',
 *       content          TEXT,
 *       source_url       VARCHAR,
 *       role_restriction VARCHAR DEFAULT 'public',
 *       content_type     VARCHAR DEFAULT 'content',
 *       chunk_index      INTEGER,
 *       total_chunks     INTEGER,
 *       is_chunked       BOOLEAN DEFAULT FALSE,
 *       created_at       TIMESTAMP DEFAULT current_timestamp,
 *       updated_at       TIMESTAMP DEFAULT current_timestamp
 *   );
 * Plus an HNSW index on the embedding column (VSS extension) when enabled,
 * and an FTS index on content for hybrid search.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Vector_Store {

    use MxChat_Plus_DuckDB_SQL_Helpers_Trait;

    /**
     * MotherDuck enforces a HTTP body size limit per query; keep upsert
     * batches well under it. ~50 rows × 1536 dims ≈ 1 MB of SQL.
     */
    const UPSERT_CHUNK_ROWS_HTTP = 50;
    const UPSERT_CHUNK_ROWS_LOCAL = 250;

    protected MxChat_Plus_DuckDB_Connection $conn;
    protected string $table;
    protected int $dim;
    protected string $metric;
    protected bool $hnsw;
    protected string $storage; // 'float32' | 'int8'

    /**
     * Primary-side schema. When the connection is a Mirrored wrapper,
     * this is bound to the primary (MotherDuck) connection — it owns
     * the schema_meta on the canonical side. When the connection is a
     * plain Embedded / MotherDuck connection, this is the only schema.
     */
    private MxChat_Plus_DuckDB_Vector_Store_Schema $schema;

    /**
     * Local-side schema, only constructed when the connection is a
     * Mirrored wrapper. The mirror needs schema migrations applied to
     * BOTH sides — per-side, because each side answers
     * supports_capability() differently (primary refuses VSS, local
     * supports it), so going through the wrapper's write-through would
     * try to CREATE INDEX … USING HNSW on MotherDuck and throw before
     * ever reaching local. Running the migration twice with separate
     * Schema instances is the clean fix.
     */
    private ?MxChat_Plus_DuckDB_Vector_Store_Schema $local_schema = null;

    private MxChat_Plus_DuckDB_Vector_Store_Query $query;

    /** Per-request cached default instance, returned by current(). */
    private static ?self $current = null;

    /**
     * Per-request cached store keyed off the current plugin options. Mutating
     * options invalidates the cache via reset_current(), which is fired
     * alongside Connection_Factory::reset_cache() from the options sanitiser.
     *
     * When to use which constructor:
     *   - `Vector_Store::current()`  →  hot path (every request fires it).
     *     REST proxy handlers, search adapter, anywhere a chat conversation
     *     can hit the code per user message.
     *   - `new MxChat_Plus_DuckDB_Vector_Store()`  →  occasional / admin path.
     *     Bulk sync, CLI commands, Pinecone migrator, settings introspection,
     *     compactor cron — the option re-read cost is irrelevant compared to
     *     the work the call is about to do, and a fresh instance avoids
     *     coupling unit tests to the singleton.
     *   - `new MxChat_Plus_DuckDB_Vector_Store($conn)`  →  health probe + admin
     *     diagnostics that want to test a specific backend instance instead
     *     of the cached one.
     */
    public static function current(): self {
        return self::$current ??= new self();
    }

    public static function reset_current(): void {
        self::$current = null;
    }

    public function __construct(?MxChat_Plus_DuckDB_Connection $conn = null) {
        $opts = MxChat_Plus_DuckDB_Options::get();
        $this->conn    = $conn ?? MxChat_Plus_DuckDB_Connection_Factory::from_options($opts);
        $this->table   = $opts['table_name'];
        $this->dim     = (int) $opts['embedding_dim'];
        $this->metric  = $opts['distance_metric'];
        $this->hnsw    = !empty($opts['hnsw_enabled']);
        $this->storage = in_array($opts['embedding_storage'] ?? 'float32', ['float32', 'int8'], true)
            ? $opts['embedding_storage']
            : 'float32';

        // Under mirror, the schema migration MUST run per-side so each
        // side answers its own supports_capability() honestly (primary
        // refuses VSS / HNSW, local supports it). Going through the
        // wrapper's write-through would propagate the CREATE INDEX to
        // primary and throw before ever reaching local.
        if ($this->conn instanceof MxChat_Plus_DuckDB_Mirrored_Connection) {
            $this->schema = new MxChat_Plus_DuckDB_Vector_Store_Schema(
                $this->conn->primary_connection(),
                $this->table, $this->dim, $this->metric, $this->hnsw, $this->storage
            );
            $this->local_schema = new MxChat_Plus_DuckDB_Vector_Store_Schema(
                $this->conn->local_connection(),
                $this->table, $this->dim, $this->metric, $this->hnsw, $this->storage
            );
        } else {
            $this->schema = new MxChat_Plus_DuckDB_Vector_Store_Schema(
                $this->conn, $this->table, $this->dim, $this->metric, $this->hnsw, $this->storage
            );
        }
        $this->query = new MxChat_Plus_DuckDB_Vector_Store_Query(
            $this->conn, $this->table, $this->dim, $this->metric, $this->storage
        );
    }

    // ─── Schema delegation ──────────────────────────────────────────────

    public function ensure_schema(): void {
        $this->schema->ensure_schema();
        // Apply the same migration set on the local side independently
        // so each side's capability check is honoured (notably, HNSW
        // gets created on local but skipped on MotherDuck primary).
        $this->local_schema?->ensure_schema();
    }
    public function table_info(): ?array { return $this->schema->table_info(); }

    /**
     * HNSW availability for the read path. Under mirror, reads come
     * from local — so the relevant verdict is local's. Without mirror,
     * the single primary schema is authoritative.
     */
    public function hnsw_available(): ?bool {
        return $this->local_schema?->hnsw_available() ?? $this->schema->hnsw_available();
    }

    /**
     * FTS availability for the hybrid (BM25 + vector) read path. Same
     * rationale as hnsw_available(): read-side is local under mirror.
     */
    public function fts_available(): ?bool {
        return $this->local_schema?->fts_available() ?? $this->schema->fts_available();
    }

    // ─── Query delegation ───────────────────────────────────────────────

    /**
     * Top-K similarity search returning Pinecone-shaped matches.
     * See MxChat_Plus_DuckDB_Vector_Store_Query::run() for the full pipeline.
     */
    public function query_pinecone_shape(array $embedding, int $top_k, string $bot_id = 'default', array $filter = []): array {
        $this->ensure_schema();
        return $this->query->run($embedding, $top_k, $bot_id, $filter);
    }

    // ─── Write path ─────────────────────────────────────────────────────

    /**
     * Upsert a batch of vector rows. Each row is an associative array; the
     * required keys (`vector_id`, `embedding`) and the embedding dimension are
     * validated per row in upsert_chunk() at runtime (it throws / skips), so
     * the param is typed loosely — the rows are assembled from DB/REST sources
     * PHPStan can't pin to an exact shape at the call sites.
     *
     * Recognised keys: vector_id (string, required), embedding (numeric[],
     * required), bot_id, content, source_url, role_restriction, content_type
     * (strings), chunk_index, total_chunks (int|null), is_chunked (bool).
     *
     * @param array<int, array<string, mixed>> $vectors
     */
    public function upsert(array $vectors): int {
        if (empty($vectors)) return 0;

        // max(1, …): a filter override could return ≤0; array_chunk requires ≥1.
        $chunk_size = max(1, $this->upsert_chunk_size());
        $total = 0;
        foreach (array_chunk($vectors, $chunk_size) as $chunk) {
            $total += $this->upsert_chunk($chunk);
        }
        if ($total > 0) {
            // Flush only the bot(s) this batch actually wrote — a sync on one
            // bot must not invalidate another bot's cached top-Ks.
            $bots = [];
            foreach ($vectors as $v) {
                $bots[(string) ($v['bot_id'] ?? 'default')] = true;
            }
            foreach (array_keys($bots) as $bot) {
                MxChat_Plus_DuckDB_Cache::flush_query_cache($bot);
            }
        }
        return $total;
    }

    private function upsert_chunk(array $vectors): int {
        $rows = [];
        foreach ($vectors as $v) {
            if (!isset($v['vector_id']) || $v['vector_id'] === '') continue;
            if (!isset($v['embedding']) || !is_array($v['embedding'])) continue;
            if (count($v['embedding']) !== $this->dim) {
                throw new RuntimeException(sprintf(
                    /* translators: 1: vector id, 2: expected dim, 3: actual dim */
                    __('Embedding dimension mismatch on upsert: vector_id=%1$s, expected %2$d, got %3$d.', 'mxchat-plus'),
                    $v['vector_id'],
                    $this->dim,
                    count($v['embedding'])
                ));
            }

            $embedding_for_storage = $this->storage === 'int8'
                ? MxChat_Plus_DuckDB_Quantization::quantize_int8($v['embedding'])
                : $v['embedding'];

            $rows[] = sprintf(
                '(%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
                $this->literal_string((string) $v['vector_id']),
                $this->literal_string((string) ($v['bot_id'] ?? 'default')),
                $this->literal_int_or_float_array($embedding_for_storage),
                $this->literal_string((string) ($v['content'] ?? '')),
                $this->literal_string((string) ($v['source_url'] ?? '')),
                $this->literal_string((string) ($v['role_restriction'] ?? 'public')),
                $this->literal_string((string) ($v['content_type'] ?? 'content')),
                isset($v['chunk_index']) ? (int) $v['chunk_index'] : 'NULL',
                isset($v['total_chunks']) ? (int) $v['total_chunks'] : 'NULL',
                !empty($v['is_chunked']) ? 'TRUE' : 'FALSE'
            );
        }

        if (empty($rows)) return 0;

        $sql = sprintf(
            'INSERT OR REPLACE INTO %s (vector_id, bot_id, embedding, content, source_url, role_restriction, content_type, chunk_index, total_chunks, is_chunked) VALUES %s',
            $this->quote_ident($this->table),
            implode(",\n", $rows)
        );
        $this->conn->execute($sql);
        return count($rows);
    }

    private function upsert_chunk_size(): int {
        $opts = MxChat_Plus_DuckDB_Options::get();
        $is_remote = ($opts['mode'] ?? '') === 'motherduck';
        $default = $is_remote ? self::UPSERT_CHUNK_ROWS_HTTP : self::UPSERT_CHUNK_ROWS_LOCAL;
        return (int) apply_filters('mxchat_plus_duckdb_upsert_chunk_size', $default, $is_remote);
    }

    /**
     * Max vector_ids per DELETE statement. The proxy passes mxchat's bulk-delete
     * ID arrays straight through (chunk cleanup, session purge), which can be
     * large; one giant `IN (...)` inflates the SQL string and overruns
     * MotherDuck's HTTP body limit (and the CLI's stdin). The compactor already
     * chunks its orphan deletes at 100 for the same reason — match it here.
     */
    const DELETE_ID_CHUNK = 100;

    public function delete_by_ids(array $vector_ids, string $bot_id = 'default'): int {
        if (empty($vector_ids)) return 0;

        $ids = array_values(array_unique(array_map('strval', $vector_ids)));
        $table_q = $this->quote_ident($this->table);
        $bot_q   = $this->literal_string($bot_id);

        foreach (array_chunk($ids, self::DELETE_ID_CHUNK) as $batch) {
            $list = implode(',', array_map([$this, 'literal_string'], $batch));
            $this->conn->execute(sprintf(
                'DELETE FROM %s WHERE bot_id = %s AND vector_id IN (%s)',
                $table_q, $bot_q, $list
            ));
        }
        MxChat_Plus_DuckDB_Cache::flush_query_cache($bot_id);
        return count($ids);
    }

    public function delete_by_source_url(string $source_url, string $bot_id = 'default'): int {
        $sql = sprintf(
            'DELETE FROM %s WHERE bot_id = %s AND source_url = %s',
            $this->quote_ident($this->table),
            $this->literal_string($bot_id),
            $this->literal_string($source_url)
        );
        $this->conn->execute($sql);
        MxChat_Plus_DuckDB_Cache::flush_query_cache($bot_id);
        return 1;
    }

    // ─── Lookup / inspection ────────────────────────────────────────────

    /**
     * Distinct bot_ids present in the store, ascending, capped at $limit.
     * Used by the admin "Per-bot overrides" UI to list the bots an operator
     * can tune. Returns [] on any backend error (the UI degrades gracefully).
     *
     * @return string[]
     */
    public function list_bot_ids(int $limit = 200): array {
        $limit = max(1, min(1000, $limit));
        $rows = $this->conn->execute(sprintf(
            'SELECT DISTINCT bot_id FROM %s ORDER BY bot_id LIMIT %d',
            $this->quote_ident($this->table),
            $limit
        ));
        $out = [];
        foreach ($rows as $r) {
            $bid = (string) ($r['bot_id'] ?? '');
            if ($bid !== '') $out[] = $bid;
        }
        return $out;
    }

    public function count(string $bot_id = 'default'): int {
        $rows = $this->conn->execute(sprintf(
            'SELECT COUNT(*) AS c FROM %s WHERE bot_id = %s',
            $this->quote_ident($this->table),
            $this->literal_string($bot_id)
        ));
        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * Paginated vector_id listing, optionally narrowed to IDs starting with a
     * given prefix. The Pinecone-emulation proxy calls this with a prefix
     * like `{md5(source_url)}_chunk_` so mxchat's chunk-reassembly + bulk-
     * delete paths see the right subset instead of every ID in the
     * namespace.
     *
     * The prefix is wrapped in `LIKE '<escaped>%' ESCAPE '\'` so the SQL
     * wildcards `%` and `_` inside a caller-supplied prefix are treated
     * literally — md5 hashes contain no wildcards in practice, but the
     * underscore in `_chunk_` is also a SQL LIKE wildcard, so this is
     * required for correctness, not just defence in depth.
     */
    public function list_ids(string $bot_id = 'default', int $limit = 100, int $offset = 0, ?string $prefix = null): array {
        $where = sprintf('bot_id = %s', $this->literal_string($bot_id));
        if ($prefix !== null && $prefix !== '') {
            $escaped = strtr($prefix, [
                '\\' => '\\\\',
                '%'  => '\\%',
                '_'  => '\\_',
            ]);
            $where .= sprintf(" AND vector_id LIKE %s ESCAPE '\\'", $this->literal_string($escaped . '%'));
        }
        $rows = $this->conn->execute(sprintf(
            'SELECT vector_id FROM %s WHERE %s ORDER BY created_at DESC LIMIT %d OFFSET %d',
            $this->quote_ident($this->table),
            $where,
            $limit,
            $offset
        ));
        return array_map(fn($r) => (string) ($r['vector_id'] ?? ''), $rows);
    }

    public function fetch_by_ids(array $vector_ids, string $bot_id = 'default'): array {
        if (empty($vector_ids)) return [];
        $list = implode(',', array_map([$this, 'literal_string'], array_map('strval', $vector_ids)));
        $rows = $this->conn->execute(sprintf(
            'SELECT vector_id AS id, content AS text, source_url, role_restriction, content_type AS type,
                    chunk_index, total_chunks, is_chunked
             FROM %s WHERE bot_id = %s AND vector_id IN (%s)',
            $this->quote_ident($this->table),
            $this->literal_string($bot_id),
            $list
        ));
        $out = [];
        foreach ($rows as $r) {
            $id = (string) ($r['id'] ?? '');
            $out[$id] = [
                'id'       => $id,
                'metadata' => [
                    'text'             => (string) ($r['text'] ?? ''),
                    'source_url'       => (string) ($r['source_url'] ?? ''),
                    'role_restriction' => (string) ($r['role_restriction'] ?? 'public'),
                    'type'             => (string) ($r['type'] ?? 'content'),
                    'chunk_index'      => isset($r['chunk_index']) ? (int) $r['chunk_index'] : null,
                    'total_chunks'     => isset($r['total_chunks']) ? (int) $r['total_chunks'] : null,
                    'is_chunked'       => !empty($r['is_chunked']),
                ],
            ];
        }
        return $out;
    }

    // ─── Parquet I/O ────────────────────────────────────────────────────

    /**
     * Dump the entire table to a Parquet file on the DuckDB-side filesystem.
     * @throws RuntimeException on SQL error.
     */
    public function export_parquet(string $path): int {
        $this->ensure_schema();
        $safe_path = str_replace("'", "''", $path);
        $this->conn->execute(sprintf(
            "COPY %s TO '%s' (FORMAT PARQUET, COMPRESSION ZSTD)",
            $this->quote_ident($this->table),
            $safe_path
        ));
        $rows = $this->conn->execute(sprintf(
            'SELECT COUNT(*) AS c FROM %s',
            $this->quote_ident($this->table)
        ));
        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * Restore rows from a Parquet file produced by export_parquet().
     * Existing vectors with the same vector_id are replaced.
     */
    public function import_parquet(string $path): int {
        $this->ensure_schema();
        $safe_path = str_replace("'", "''", $path);

        $tmp_view = '__mxd_import_' . wp_generate_password(8, false, false);
        $this->conn->execute(sprintf(
            "CREATE OR REPLACE TEMP VIEW %s AS SELECT * FROM read_parquet('%s')",
            $this->quote_ident($tmp_view),
            $safe_path
        ));
        $count_rows = $this->conn->execute(sprintf('SELECT COUNT(*) AS c FROM %s', $this->quote_ident($tmp_view)));
        $expected = (int) ($count_rows[0]['c'] ?? 0);
        if ($expected === 0) return 0;

        $this->conn->execute(sprintf(
            'INSERT OR REPLACE INTO %s SELECT * FROM %s',
            $this->quote_ident($this->table),
            $this->quote_ident($tmp_view)
        ));
        $this->conn->execute(sprintf('DROP VIEW IF EXISTS %s', $this->quote_ident($tmp_view)));

        MxChat_Plus_DuckDB_Cache::flush_query_cache();
        return $expected;
    }

    /**
     * Approximate footprint of the vectors table for the admin diagnostics
     * panel. Returns bytes_estimate + row_count + dim.
     */
    public function storage_estimate(): array {
        try {
            $bytes_per_value = $this->storage === 'int8' ? 1 : 4;
            $row_bytes = $bytes_per_value * $this->dim + 200; // metadata fudge factor
            $rows = $this->conn->execute(sprintf(
                'SELECT COUNT(*) AS c FROM %s',
                $this->quote_ident($this->table)
            ));
            $count = (int) ($rows[0]['c'] ?? 0);
            return [
                'rows'           => $count,
                'bytes_estimate' => $count * $row_bytes,
                'dim'            => $this->dim,
                'storage'        => $this->storage,
            ];
        } catch (\Throwable $e) {
            return ['rows' => 0, 'bytes_estimate' => 0, 'dim' => $this->dim, 'storage' => $this->storage];
        }
    }

    // ─── Accessors ──────────────────────────────────────────────────────

    public function connection(): MxChat_Plus_DuckDB_Connection { return $this->conn; }
    public function table_name(): string { return $this->table; }
    public function table_name_quoted(): string { return $this->quote_ident($this->table); }
}

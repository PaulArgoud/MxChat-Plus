<?php
/**
 * Schema lifecycle for the vector store: migration runner + meta table.
 *
 * Migrations are sequential integers; each one transitions from v(N-1) to vN.
 * Every migration MUST be idempotent — it can run against an install that
 * already has parts of the target state in place.
 *
 * Per-request memoisation lives here (static cache keyed by backend + table +
 * dim), so `ensure_schema()` is cheap on the hot path.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Vector_Store_Schema {

    use MxChat_Plus_DuckDB_SQL_Helpers_Trait;

    /**
     * Bump when a migration is added below. The runner is idempotent because
     * each migration uses IF NOT EXISTS / ADD COLUMN IF NOT EXISTS short-circuits.
     */
    const TARGET_SCHEMA_VERSION = 3;
    const META_TABLE = 'mxchat_plus_duckdb_schema_meta';
    const META_KEY_SCHEMA_VERSION = 'schema_version';
    const META_KEY_FTS_AVAILABLE  = 'fts_available';
    const META_KEY_HNSW_AVAILABLE = 'hnsw_available';

    /** @var array<string,bool> cache: which (backend|table|dim) combos are already migrated this request */
    private static array $ensured = [];

    public function __construct(
        protected MxChat_Plus_DuckDB_Connection $conn,
        protected string $table,
        protected int $dim,
        protected string $metric,
        protected bool $hnsw,
        protected string $storage
    ) {}

    /**
     * Run any pending migrations. Memoised per-request to avoid the version
     * check on every search.
     */
    public function ensure_schema(): void {
        $cache_key = $this->conn->identifier() . '|' . $this->table . '|' . $this->dim;
        if (isset(self::$ensured[$cache_key])) return;

        $this->ensure_meta_table();
        $current = $this->get_schema_version();
        for ($v = $current + 1; $v <= self::TARGET_SCHEMA_VERSION; $v++) {
            $this->apply_migration($v);
            $this->set_schema_version($v);
        }

        self::$ensured[$cache_key] = true;
    }

    /**
     * Row count + dim of the existing table, or null when the table doesn't
     * exist yet. Drives the dimension-change guard in the options sanitiser.
     */
    public function table_info(): ?array {
        try {
            $rows = $this->conn->execute(sprintf(
                'SELECT COUNT(*) AS c FROM %s',
                $this->quote_ident($this->table)
            ));
            return ['count' => (int) ($rows[0]['c'] ?? 0)];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function ensure_meta_table(): void {
        $this->conn->execute(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (key VARCHAR PRIMARY KEY, value VARCHAR)',
            $this->quote_ident(self::META_TABLE)
        ));
    }

    private function get_schema_version(): int {
        $val = $this->get_meta(self::META_KEY_SCHEMA_VERSION);
        return $val === null ? 0 : (int) $val;
    }

    private function set_schema_version(int $version): void {
        $this->set_meta(self::META_KEY_SCHEMA_VERSION, (string) $version);
    }

    /**
     * Generic meta read/write. Used for schema_version and for the
     * persistent fts_available flag. Returns null when the key is absent
     * or the meta table doesn't exist yet (pre-migration call).
     */
    private function get_meta(string $key): ?string {
        try {
            $rows = $this->conn->execute(sprintf(
                "SELECT value FROM %s WHERE key = %s",
                $this->quote_ident(self::META_TABLE),
                $this->literal_string($key)
            ));
            if (!isset($rows[0]) || !array_key_exists('value', $rows[0])) return null;
            return (string) $rows[0]['value'];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function set_meta(string $key, string $value): void {
        $this->conn->execute(sprintf(
            'INSERT OR REPLACE INTO %s (key, value) VALUES (%s, %s)',
            $this->quote_ident(self::META_TABLE),
            $this->literal_string($key),
            $this->literal_string($value)
        ));
    }

    private function apply_migration(int $version): void {
        switch ($version) {
            case 1: $this->migration_v1_base_schema();      return;
            case 2: $this->migration_v2_updated_at_column(); return;
            case 3: $this->migration_v3_fts_index();         return;
        }
    }

    private function migration_v1_base_schema(): void {
        // VSS extension is needed for the HNSW index DDL. We INSTALL+LOAD it
        // even when we're not creating the index, because future migrations
        // or read-time pushdown hints may rely on the operators being
        // resolvable — except on MotherDuck where the extension is a no-op
        // cloud-side anyway, so we skip the network round-trip entirely.
        // Only install/load VSS when the backend can actually persist an
        // HNSW index — MotherDuck cloud is the notable exception
        // (motherduck.com/docs/concepts/duckdb-extensions). Saves three
        // pointless network round-trips on cloud-only installs.
        $vss_persistent = $this->conn->supports_capability(MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX);
        if ($vss_persistent) {
            $this->conn->execute('INSTALL vss');
            $this->conn->execute('LOAD vss');
        }

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                vector_id        VARCHAR PRIMARY KEY,
                bot_id           VARCHAR DEFAULT \'default\',
                embedding        %s,
                content          TEXT,
                source_url       VARCHAR,
                role_restriction VARCHAR DEFAULT \'public\',
                content_type     VARCHAR DEFAULT \'content\',
                chunk_index      INTEGER,
                total_chunks     INTEGER,
                is_chunked       BOOLEAN DEFAULT FALSE,
                created_at       TIMESTAMP DEFAULT current_timestamp
            )',
            $this->quote_ident($this->table),
            $this->embedding_column_type()
        );
        $this->conn->execute($sql);

        // HNSW outcome is persisted to meta so the admin UI + the rest of
        // the code know whether brute-force is the only option.
        //   '1' → index exists, planner can pushdown
        //   '0' → index missing (MotherDuck cloud, FFI failure, etc.)
        if (!$this->hnsw) {
            $this->mark_hnsw_unavailable_silent();
            return;
        }
        if (!$vss_persistent) {
            // Backend doesn't support a persistent VSS index — typically
            // MotherDuck cloud (https://motherduck.com/docs/concepts/duckdb-extensions/).
            // Skipping cleanly avoids one CREATE INDEX round-trip + a
            // swallowed exception on every fresh install.
            $this->mark_hnsw_unavailable_silent();
            error_log('[mxchat-plus] HNSW skipped: backend reports no support for persistent VSS index (typically MotherDuck cloud). Queries will run as brute-force scans. Switch to "Embedded" mode for HNSW acceleration.');
            return;
        }

        $metric = $this->vss_metric();
        try {
            $this->conn->execute(sprintf(
                'CREATE INDEX IF NOT EXISTS idx_%1$s_hnsw ON %2$s USING HNSW (embedding) WITH (metric = \'%3$s\')',
                $this->table,
                $this->quote_ident($this->table),
                $metric
            ));
            $this->set_meta_silent(self::META_KEY_HNSW_AVAILABLE, '1');
        } catch (\Throwable $e) {
            $this->mark_hnsw_unavailable_silent();
            error_log('[mxchat-plus] HNSW index creation failed, queries will run as brute-force scans: ' . $e->getMessage());
        }
    }

    private function mark_hnsw_unavailable_silent(): void {
        $this->set_meta_silent(self::META_KEY_HNSW_AVAILABLE, '0');
    }

    private function set_meta_silent(string $key, string $value): void {
        try { $this->set_meta($key, $value); } catch (\Throwable $ignore) {}
    }

    private function migration_v2_updated_at_column(): void {
        try {
            $this->conn->execute(sprintf(
                'ALTER TABLE %s ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT current_timestamp',
                $this->quote_ident($this->table)
            ));
        } catch (\Throwable $e) {
            // Older DuckDB versions don't support ADD COLUMN IF NOT EXISTS — ignore.
        }
    }

    private function migration_v3_fts_index(): void {
        // FTS is best-effort: the extension may not be available on every
        // DuckDB build. Hybrid search degrades to pure vector gracefully.
        // The outcome is persisted to meta (fts_available='1'|'0') so the
        // read path can skip the BM25 attempt entirely instead of catching
        // an exception on every query — see Vector_Store_Query.
        try {
            $this->conn->execute('INSTALL fts');
            $this->conn->execute('LOAD fts');
            $this->conn->execute(sprintf(
                "PRAGMA create_fts_index('%s', 'vector_id', 'content', overwrite = 0)",
                str_replace("'", "''", $this->table)
            ));
            $this->set_meta(self::META_KEY_FTS_AVAILABLE, '1');
        } catch (\Throwable $e) {
            try { $this->set_meta(self::META_KEY_FTS_AVAILABLE, '0'); } catch (\Throwable $ignore) {}
            error_log('[mxchat-plus] FTS extension unavailable, hybrid search disabled: ' . $e->getMessage());
        }
    }

    /**
     * Persistent FTS availability flag. Read by Vector_Store_Query to
     * short-circuit BM25 attempts when the FTS extension isn't installed
     * (instead of throwing + logging on every hybrid query).
     *
     * Returns null when no decision has been recorded yet — typically an
     * install upgraded from a pre-v0.8.1 build that ran v3 before this
     * flag existed. Callers should treat null as "unknown, try once".
     */
    public function fts_available(): ?bool {
        $val = $this->get_meta(self::META_KEY_FTS_AVAILABLE);
        if ($val === null) return null;
        return $val === '1';
    }

    /**
     * Persistent HNSW availability flag. Written by migration_v1_base_schema
     * after the CREATE INDEX attempt (or after deciding to skip it on
     * MotherDuck cloud). Reads back null when no migration has yet
     * recorded a verdict — read-time callers treat that as "unknown".
     *
     * Used by the admin UI to render an accurate status, and by anyone
     * who wants to know up-front whether queries will be brute-force.
     */
    public function hnsw_available(): ?bool {
        $val = $this->get_meta(self::META_KEY_HNSW_AVAILABLE);
        if ($val === null) return null;
        return $val === '1';
    }

    private function vss_metric(): string {
        return match ($this->metric) {
            'l2sq' => 'l2sq',
            'ip'   => 'ip',
            default => 'cosine',
        };
    }
}

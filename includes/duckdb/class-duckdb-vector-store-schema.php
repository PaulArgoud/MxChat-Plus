<?php
/**
 * Schema lifecycle for the vector store: migration runner + meta table.
 *
 * Migrations are sequential integers; each one transitions from v(N-1) to vN.
 * Every migration MUST be idempotent — it can run against an install that
 * already has parts of the target state in place, or that crashed half-way
 * through it.
 *
 * Per-request memoisation lives here (static cache keyed by backend + table +
 * dim), so `ensure_schema()` is cheap on the hot path.
 *
 * Current layout (v4) — see create_table_sql():
 *   - PRIMARY KEY (bot_id, vector_id). MxChat reuses md5(url) ids in every
 *     bot namespace (the id never depends on the bot; only the Pinecone
 *     namespace separates bots), so a key on vector_id alone let one bot's
 *     upsert overwrite another bot's row. Real Pinecone keeps one copy per
 *     namespace; so do we now.
 *   - row_key = bot_id || chr(31) || vector_id, the FTS document id. BM25
 *     looks rows up by that name, and vector_id is no longer unique.
 *   - title / parent_url_hash / created_unix / last_updated_unix /
 *     extra_metadata carry the metadata MxChat 3.2.23+ sends with every
 *     upsert (page title, chunk parent hash, unix timestamps, add-on keys).
 *
 * Every SQL statement here is built from quote_ident()/literal_string() or
 * from the fixed column whitelist below, so phpstan/UnsafeSqlConstructionRule
 * keeps covering this file (do not rename it).
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Vector_Store_Schema {

    use MxChat_Plus_DuckDB_SQL_Helpers_Trait;

    /**
     * Bump when a migration is added below. The runner is idempotent because
     * each migration uses IF NOT EXISTS short-circuits or inspects the table
     * before acting.
     */
    const TARGET_SCHEMA_VERSION = 4;
    const META_TABLE = 'mxchat_plus_duckdb_schema_meta';
    const META_KEY_SCHEMA_VERSION = 'schema_version';
    const META_KEY_FTS_AVAILABLE  = 'fts_available';
    const META_KEY_HNSW_AVAILABLE = 'hnsw_available';

    /** FTS document id / unique per-row key (see row_key_sql()). */
    const ROW_KEY_COLUMN = 'row_key';

    /**
     * Name suffix of the table the v4 migration parks the rows in while it
     * rebuilds the main table. A leftover one means a migration was
     * interrupted — the next ensure_schema() resumes from it.
     */
    const STAGING_SUFFIX = '_v4_staging';

    /** Option-name prefix of the v4 rebuild lock (one row per connection + table). */
    const V4_LOCK_PREFIX = 'mxchat_plus_duckdb_v4_lock_';
    /** Age after which a lock left by a dead request is taken over. */
    const V4_LOCK_STALE_SECONDS = 1800;

    /**
     * Every column of the v4 layout, in DDL order. Copy statements
     * (Parquet restore, mirror bootstrap, the v4 rebuild) name these
     * explicitly instead of `SELECT *`, so a source with fewer or more
     * columns (a pre-v4 backup, a primary not yet migrated) still lines up.
     */
    const COLUMNS = [
        'vector_id', 'bot_id', 'embedding', 'content', 'source_url',
        'role_restriction', 'content_type', 'chunk_index', 'total_chunks',
        'is_chunked', 'created_at', 'updated_at', 'title', 'parent_url_hash',
        'created_unix', 'last_updated_unix', 'extra_metadata', 'row_key',
    ];

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

    // ─── Row key ────────────────────────────────────────────────────────

    /**
     * SQL expression of the unique row key, from two SQL expressions (column
     * names or literals). Every writer that names its columns must fill
     * row_key with this — Vector_Store::upsert(), the copy statements below,
     * and any native INSERT … SELECT. chr(31) (unit separator) cannot occur
     * in a bot id or an MxChat vector id, so the pair → key mapping is
     * injective. Kept in SQL (not a PHP "\x1F" byte) so no raw control
     * character ever travels in a statement.
     */
    public static function row_key_sql(string $bot_sql, string $vector_id_sql): string {
        return '(' . $bot_sql . ') || chr(31) || (' . $vector_id_sql . ')';
    }

    /** PHP twin of row_key_sql(), byte-identical to what DuckDB computes. */
    public static function row_key(string $bot_id, string $vector_id): string {
        return $bot_id . "\x1F" . $vector_id;
    }

    // ─── Introspection + copy helpers ───────────────────────────────────

    /**
     * Columns of a table as name => [pk => bool, type => string], read with
     * pragma_table_info (which resolves the name exactly like an unqualified
     * query does, and accepts `catalog.table` for an attached database).
     *
     * Returns null when the table does not exist (DuckDB raises a Catalog
     * error) and [] when the backend answered with no rows at all — which a
     * real DuckDB never does for an existing table, so callers treat [] as
     * "cannot introspect" and leave the table alone.
     *
     * @return array<string, array{pk: bool, type: string}>|null
     */
    public function table_columns(string $table_ref): ?array {
        try {
            $rows = $this->conn->execute(sprintf(
                'SELECT name, pk, type FROM pragma_table_info(%s)',
                $this->literal_string($table_ref)
            ));
        } catch (\Throwable $e) {
            // Only "no such table" means absent. Any other failure (a dropped
            // MotherDuck connection, a file lock) must not pass for a missing
            // table, or the v4 migration would stamp v4 over a v3 table it
            // never rebuilt. Rethrown, it leaves the version unstamped and the
            // runner retries on a later request.
            if (self::is_missing_table_error($e)) {
                return null;
            }
            throw $e;
        }
        $out = [];
        foreach ($rows as $r) {
            $name = strtolower((string) ($r['name'] ?? ''));
            if ($name === '') continue;
            $pk = $r['pk'] ?? false;
            $out[$name] = [
                'pk'   => $pk === true || $pk === 1 || $pk === '1' || $pk === 'true',
                'type' => strtoupper((string) ($r['type'] ?? '')),
            ];
        }
        return $out;
    }

    /** True when DuckDB (embedded or MotherDuck) reports that the relation does not exist. */
    private static function is_missing_table_error(\Throwable $e): bool {
        $msg = $e->getMessage();
        return stripos($msg, 'Catalog Error') !== false || stripos($msg, 'does not exist') !== false;
    }

    /**
     * add_option() refuses an existing name, which makes it an atomic claim.
     *
     * @phpstan-impure
     */
    private static function claim_option_lock(string $name): bool {
        return add_option($name, (string) time(), '', 'no') === true;
    }

    /**
     * Takes the v4 rebuild lock for this connection + table, or throws.
     *
     * @return string Option name to delete once the rebuild is over.
     */
    private function acquire_v4_lock(): string {
        $name = self::V4_LOCK_PREFIX . md5($this->conn->identifier() . '|' . $this->table);
        if (self::claim_option_lock($name)) {
            return $name;
        }
        // A request that died mid-rebuild leaves its row behind. Every step of
        // the rebuild re-reads the catalog, so taking over a stale lock is safe.
        $since = (int) get_option($name, 0);
        if ($since > 0 && time() - $since > self::V4_LOCK_STALE_SECONDS) {
            delete_option($name);
            if (self::claim_option_lock($name)) {
                return $name;
            }
        }
        throw new RuntimeException(sprintf(
            'Schema v4 migration of %s is already running in another request; it will be retried.',
            $this->table
        ));
    }

    /**
     * Column names of an arbitrary relation expression (e.g.
     * `read_parquet('…')`), via DESCRIBE. Lower-cased.
     *
     * @return list<string>
     */
    public function describe_columns(string $relation_sql): array {
        $rows = $this->conn->execute(sprintf('DESCRIBE SELECT * FROM %s', $relation_sql));
        $out = [];
        foreach ($rows as $r) {
            $name = strtolower((string) ($r['column_name'] ?? ($r['name'] ?? '')));
            if ($name !== '') $out[] = $name;
        }
        return $out;
    }

    /**
     * Whether a column map (table_columns()) describes the v4 layout.
     *
     * @param array<string, array{pk: bool, type: string}> $cols
     */
    public static function is_v4_layout(array $cols): bool {
        return isset($cols[self::ROW_KEY_COLUMN])
            && !empty($cols['bot_id']['pk'])
            && !empty($cols['vector_id']['pk']);
    }

    /**
     * Normalised bot_id expression over a source that may predate the
     * composite key (bot_id NULL / '' / absent → 'default').
     *
     * @param list<string> $source_columns lower-cased source column names
     */
    public static function source_bot_sql(array $source_columns): string {
        return in_array('bot_id', $source_columns, true)
            ? "COALESCE(NULLIF(bot_id, ''), 'default')"
            : "'default'";
    }

    /**
     * Build `<verb> INTO <this table> (<v4 columns>) SELECT <exprs> FROM <source> <tail>`.
     *
     * Columns the source lacks get their v4 default; row_key is always
     * recomputed from the normalised bot_id + vector_id (never copied, so a
     * NULL or stale key on the source cannot survive). $verb is one of
     * 'INSERT OR REPLACE' / 'INSERT OR IGNORE' / 'INSERT'. $from_sql and
     * $tail_sql are SQL fragments the CALLER guarantees are safe (quoted
     * identifiers, literal_string() values, `?` placeholders) — never raw
     * user input.
     *
     * @param list<string> $source_columns lower-cased source column names
     * @throws RuntimeException when the source has no vector_id or embedding column.
     */
    public function copy_rows_sql(string $verb, string $from_sql, array $source_columns, string $tail_sql = ''): string {
        if (!in_array($verb, ['INSERT OR REPLACE', 'INSERT OR IGNORE', 'INSERT'], true)) {
            throw new InvalidArgumentException('copy_rows_sql: unsupported verb ' . $verb);
        }
        foreach (['vector_id', 'embedding'] as $required) {
            if (!in_array($required, $source_columns, true)) {
                throw new RuntimeException(sprintf('Cannot copy vectors: the source has no %s column.', $required));
            }
        }
        $has = fn(string $c): bool => in_array($c, $source_columns, true);
        $bot = self::source_bot_sql($source_columns);

        $exprs = [];
        foreach (self::COLUMNS as $col) {
            switch ($col) {
                case 'vector_id':
                    $exprs[] = 'CAST(vector_id AS VARCHAR)';
                    break;
                case 'bot_id':
                    $exprs[] = $bot;
                    break;
                case 'embedding':
                    // Explicit cast: Parquet hands arrays back as lists.
                    $exprs[] = sprintf('CAST(embedding AS %s)', $this->embedding_column_type());
                    break;
                case 'role_restriction':
                    $exprs[] = $has($col) ? "COALESCE(role_restriction, 'public')" : "'public'";
                    break;
                case 'content_type':
                    $exprs[] = $has($col) ? "COALESCE(content_type, 'content')" : "'content'";
                    break;
                case 'is_chunked':
                    $exprs[] = $has($col) ? 'COALESCE(is_chunked, FALSE)' : 'FALSE';
                    break;
                case 'created_at':
                    $exprs[] = $has($col) ? 'COALESCE(created_at, current_timestamp)' : 'current_timestamp';
                    break;
                case 'updated_at':
                    // v2 added updated_at with an ALTER that DuckDB < 1.0
                    // refuses on a table with an HNSW index — so many v3
                    // tables never got the column. Fall back to created_at.
                    $exprs[] = $has($col)
                        ? ($has('created_at') ? 'COALESCE(updated_at, created_at, current_timestamp)' : 'COALESCE(updated_at, current_timestamp)')
                        : ($has('created_at') ? 'COALESCE(created_at, current_timestamp)' : 'current_timestamp');
                    break;
                case self::ROW_KEY_COLUMN:
                    $exprs[] = self::row_key_sql($bot, 'CAST(vector_id AS VARCHAR)');
                    break;
                default:
                    $exprs[] = $has($col) ? $col : 'NULL';
            }
        }

        return sprintf(
            '%s INTO %s (%s) SELECT %s FROM %s%s',
            $verb,
            $this->quote_ident($this->table),
            implode(', ', self::COLUMNS),
            implode(', ', $exprs),
            $from_sql,
            $tail_sql !== '' ? ' ' . $tail_sql : ''
        );
    }

    /**
     * The v4 table DDL. Shared by v1 (fresh installs get the current layout
     * directly) and v4 (rebuild of a pre-v4 table).
     */
    public function create_table_sql(): string {
        return sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                vector_id        VARCHAR NOT NULL,
                bot_id           VARCHAR NOT NULL DEFAULT \'default\',
                embedding        %s,
                content          TEXT,
                source_url       VARCHAR,
                role_restriction VARCHAR DEFAULT \'public\',
                content_type     VARCHAR DEFAULT \'content\',
                chunk_index      INTEGER,
                total_chunks     INTEGER,
                is_chunked       BOOLEAN DEFAULT FALSE,
                created_at       TIMESTAMP DEFAULT current_timestamp,
                updated_at       TIMESTAMP DEFAULT current_timestamp,
                title            VARCHAR,
                parent_url_hash  VARCHAR,
                created_unix     BIGINT,
                last_updated_unix BIGINT,
                extra_metadata   VARCHAR,
                row_key          VARCHAR,
                PRIMARY KEY (bot_id, vector_id)
            )',
            $this->quote_ident($this->table),
            $this->embedding_column_type()
        );
    }

    // ─── Meta table ─────────────────────────────────────────────────────

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
            case 4: $this->migration_v4_composite_key();     return;
        }
    }

    // ─── Migrations ─────────────────────────────────────────────────────

    private function migration_v1_base_schema(): void {
        // VSS extension is needed for the HNSW index DDL. Only install/load
        // VSS when the backend can actually persist an HNSW index — MotherDuck
        // cloud is the notable exception
        // (motherduck.com/docs/concepts/duckdb-extensions). Saves three
        // pointless network round-trips on cloud-only installs.
        $this->load_vss_if_supported();

        // A fresh install gets the current (v4) layout straight away; v4
        // then finds nothing to rebuild. An install whose table predates the
        // meta table keeps its old table here (IF NOT EXISTS) and is rebuilt
        // by v4.
        $this->conn->execute($this->create_table_sql());

        $this->create_hnsw_index();
    }

    private function migration_v2_updated_at_column(): void {
        try {
            $this->conn->execute(sprintf(
                'ALTER TABLE %s ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT current_timestamp',
                $this->quote_ident($this->table)
            ));
        } catch (\Throwable $e) {
            // DuckDB < 1.0 refuses ALTER on a table with an HNSW index, and
            // older builds lack ADD COLUMN IF NOT EXISTS — ignore. v4 rebuilds
            // the table with the column whatever happened here.
        }
    }

    private function migration_v3_fts_index(): void {
        // FTS is best-effort: the extension may not be available on every
        // DuckDB build. Hybrid search degrades to pure vector gracefully.
        // The outcome is persisted to meta (fts_available='1'|'0') so the
        // read path can skip the BM25 attempt entirely instead of catching
        // an exception on every query — see Vector_Store_Query.
        //
        // The document id is row_key on a v4-layout table (fresh installs
        // since v1 creates it), vector_id on a legacy one (v4 then rebuilds
        // the index on row_key).
        $cols = $this->table_columns($this->table);
        $doc_key = is_array($cols) && isset($cols[self::ROW_KEY_COLUMN]) ? self::ROW_KEY_COLUMN : 'vector_id';
        $this->create_fts_index($doc_key, false);
    }

    /**
     * v4: rebuild a pre-v4 table with PRIMARY KEY (bot_id, vector_id), the
     * row_key FTS document id and the metadata columns.
     *
     * DuckDB can neither alter a primary key nor (before 1.0) ALTER/RENAME a
     * table that an HNSW index depends on, so the table is rebuilt instead
     * of altered, through a staging copy. Every step is restartable — the
     * state is read back from the catalog on each run:
     *
     *   main = old layout  → (drop a stale staging) copy main → staging,
     *                        drop main, then continue below;
     *   main = missing     → create main (v4) and continue below;
     *   staging present    → INSERT OR IGNORE staging → main (a re-run after
     *                        a crash never overwrites a row already copied),
     *                        rebuild HNSW + FTS, drop staging;
     *   main = v4, no staging → nothing to do.
     *
     * Runs once per side under a mirrored connection (Vector_Store builds
     * one Schema per side). Rows one bot already lost to another bot's
     * upsert under the old key cannot be recovered here: that bot must
     * re-import those URLs.
     */
    private function migration_v4_composite_key(): void {
        $main    = $this->table;
        $staging = $this->table . self::STAGING_SUFFIX;

        $main_cols = $this->table_columns($main);
        if ($main_cols === []) {
            // Nothing a real DuckDB would answer for an existing table —
            // a backend we cannot introspect. Leave it untouched, but never
            // stamp v4 over a table that lacks the v4 columns: every upsert
            // names them. This probe throws on such a table, so the runner
            // does not record v4 and retries on the next request.
            $this->conn->execute(sprintf(
                'SELECT row_key, title, parent_url_hash, created_unix, last_updated_unix, extra_metadata FROM %s LIMIT 0',
                $this->quote_ident($main)
            ));
            return;
        }
        $staging_cols = $this->table_columns($staging);
        $has_staging  = is_array($staging_cols) && $staging_cols !== [];

        $main_is_v4 = is_array($main_cols) && self::is_v4_layout($main_cols);
        if ($main_is_v4 && !$has_staging) {
            return;
        }

        // Two first requests after an upgrade would both reach the destructive
        // steps below, and the one that drops the table second copies back an
        // empty staging table: every vector is lost. Serialise on an option
        // row — add_option() is a single INSERT on a unique key, so only one
        // request wins. The loser throws, the runner does not stamp v4, and a
        // later request retries once the winner is done.
        $lock = $this->acquire_v4_lock();
        try {
            $this->rebuild_for_v4($main, $staging);
        } finally {
            delete_option($lock);
        }
    }

    /**
     * Destructive half of migration_v4_composite_key(), run under its lock.
     * The catalog is read again here: another request may have finished the
     * rebuild between the first read and the lock.
     */
    private function rebuild_for_v4(string $main, string $staging): void {
        $main_cols    = $this->table_columns($main);
        $staging_cols = $this->table_columns($staging);
        $has_staging  = is_array($staging_cols) && $staging_cols !== [];
        $main_is_v4   = is_array($main_cols) && self::is_v4_layout($main_cols);
        if ($main_is_v4 && !$has_staging) {
            return;
        }

        $rebuilt = false;
        if (is_array($main_cols) && !$main_is_v4) {
            // Refuse before anything destructive when the stored vectors
            // cannot fit the configured column (dimension/storage changed
            // without a table switch): the copy back would fail with the
            // rows parked in staging.
            $old_type = (string) ($main_cols['embedding']['type'] ?? '');
            if ($old_type !== '' && $old_type !== strtoupper($this->embedding_column_type())) {
                throw new RuntimeException(sprintf(
                    'Schema v4 migration refused: table %s stores embeddings as %s but the settings expect %s. Restore the previous embedding settings, or switch to a new table name.',
                    $main,
                    $old_type,
                    $this->embedding_column_type()
                ));
            }
            if ($has_staging) {
                // A crash before the old table was dropped: the main table
                // still holds every row, so the stale copy is discarded.
                $this->conn->execute(sprintf('DROP TABLE IF EXISTS %s', $this->quote_ident($staging)));
            }
            $this->conn->execute(sprintf(
                'CREATE TABLE %s AS SELECT * FROM %s',
                $this->quote_ident($staging),
                $this->quote_ident($main)
            ));
            $staging_cols = $main_cols;
            $has_staging  = true;
            $this->drop_indexes();
            $this->conn->execute(sprintf('DROP TABLE %s', $this->quote_ident($main)));
            $main_cols = null;
            $rebuilt = true;
        }

        $created = false;
        if ($main_cols === null) {
            $this->conn->execute($this->create_table_sql());
            $created = true;
        }

        if ($has_staging && is_array($staging_cols)) {
            $this->conn->execute($this->copy_rows_sql(
                'INSERT OR IGNORE',
                $this->quote_ident($staging),
                array_keys($staging_cols)
            ));
            $rebuilt = true;
        }

        if ($rebuilt || $created) {
            $this->load_vss_if_supported();
            $this->create_hnsw_index();
            $this->create_fts_index(self::ROW_KEY_COLUMN, true);
        }

        if ($has_staging) {
            $this->conn->execute(sprintf('DROP TABLE IF EXISTS %s', $this->quote_ident($staging)));
        }

        if ($rebuilt) {
            $this->bump_query_caches();
        }
    }

    // ─── Index helpers ──────────────────────────────────────────────────

    private function load_vss_if_supported(): void {
        if ($this->conn->supports_capability(MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX)) {
            $this->conn->execute('INSTALL vss');
            $this->conn->execute('LOAD vss');
        }
    }

    /**
     * HNSW outcome is persisted to meta so the admin UI + the rest of the
     * code know whether brute-force is the only option:
     *   '1' → index exists, planner can pushdown
     *   '0' → index missing (MotherDuck cloud, FFI failure, etc.)
     */
    private function create_hnsw_index(): void {
        if (!$this->hnsw) {
            $this->mark_hnsw_unavailable_silent();
            return;
        }
        if (!$this->conn->supports_capability(MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX)) {
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

    /**
     * Build (or rebuild, with $overwrite) the FTS index on `content`, keyed
     * by $doc_key, and persist the outcome in meta.
     */
    private function create_fts_index(string $doc_key, bool $overwrite): void {
        try {
            $this->conn->execute('INSTALL fts');
            $this->conn->execute('LOAD fts');
            $this->conn->execute(sprintf(
                "PRAGMA create_fts_index(%s, %s, 'content', overwrite = %d)",
                $this->literal_string($this->table),
                $this->literal_string($doc_key === self::ROW_KEY_COLUMN ? self::ROW_KEY_COLUMN : 'vector_id'),
                $overwrite ? 1 : 0
            ));
            $this->set_meta(self::META_KEY_FTS_AVAILABLE, '1');
        } catch (\Throwable $e) {
            try { $this->set_meta(self::META_KEY_FTS_AVAILABLE, '0'); } catch (\Throwable $ignore) {}
            error_log('[mxchat-plus] FTS extension unavailable, hybrid search disabled: ' . $e->getMessage());
        }
    }

    /**
     * Drop the indexes hanging off the main table before it is dropped. Both
     * are best-effort: DROP TABLE takes an HNSW index with it on the DuckDB
     * versions checked (0.10.3, 1.0.0, 1.5.6), and a leftover FTS schema is
     * replaced by the overwrite=1 rebuild.
     */
    private function drop_indexes(): void {
        try {
            $this->conn->execute(sprintf(
                'PRAGMA drop_fts_index(%s)',
                $this->literal_string($this->table)
            ));
        } catch (\Throwable $ignore) {
            // No FTS index, or the extension is not loadable — fine.
        }
        try {
            $this->conn->execute(sprintf('DROP INDEX IF EXISTS %s', $this->quote_ident('idx_' . $this->table . '_hnsw')));
        } catch (\Throwable $ignore) {
            // VSS not loadable in this session — DROP TABLE removes it anyway.
        }
    }

    /**
     * After a rebuild, cached top-Ks still carry the pre-v4 metadata shape
     * (role_restriction 'public' as a positive claim, no title, …): make
     * every bot's cached results unreachable.
     */
    private function bump_query_caches(): void {
        if (!class_exists('MxChat_Plus_DuckDB_Cache')) return;
        $bots = [];
        try {
            $rows = $this->conn->execute(sprintf(
                'SELECT DISTINCT bot_id FROM %s LIMIT 1000',
                $this->quote_ident($this->table)
            ));
            foreach ($rows as $r) {
                $b = (string) ($r['bot_id'] ?? '');
                if ($b !== '') $bots[$b] = true;
            }
        } catch (\Throwable $ignore) {
            // Fall back to the tracked bots only.
        }
        MxChat_Plus_DuckDB_Cache::flush_query_cache();
        foreach (array_keys($bots) as $bot) {
            MxChat_Plus_DuckDB_Cache::bump_cache_generation((string) $bot);
        }
    }

    private function mark_hnsw_unavailable_silent(): void {
        $this->set_meta_silent(self::META_KEY_HNSW_AVAILABLE, '0');
    }

    private function set_meta_silent(string $key, string $value): void {
        try { $this->set_meta($key, $value); } catch (\Throwable $ignore) {}
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

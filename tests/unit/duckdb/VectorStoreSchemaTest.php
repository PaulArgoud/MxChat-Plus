<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the schema lifecycle: migration runner ordering, idempotence,
 * per-request memoisation, version read/write, and the column-type
 * branching for INT8 vs FLOAT32 storage.
 *
 * Why this test exists: schema corruption is the worst class of bug in
 * this plugin. A regression in the migration runner could mean
 * - missing columns (every insert fails),
 * - wrong column type (every vector silently truncated),
 * - or running the same DDL twice (HNSW index re-created from scratch
 *   on every page load, eating CPU until the table is huge).
 *
 * We use a recording mock connection — every SQL statement passed to
 * execute() is captured so assertions can inspect the sequence and
 * shape of statements without needing a real DuckDB.
 */
final class VectorStoreSchemaTest extends TestCase {

    /**
     * In-memory connection that records every SQL string + lets each test
     * stub responses per-statement-pattern. Implements the public surface
     * Schema actually touches.
     */
    private function makeRecordingConnection(array $responses = []): MxChat_Plus_DuckDB_Connection {
        return new class($responses) implements MxChat_Plus_DuckDB_Connection {
            /** @var string[] */
            public array $log = [];
            /** @var array<string,array> */
            public array $responses;
            public function __construct(array $responses) {
                $this->responses = $responses;
            }
            public function execute(string $sql, array $params = []): array {
                $this->log[] = $sql;
                foreach ($this->responses as $pattern => $rows) {
                    if (stripos($sql, $pattern) !== false) return $rows;
                }
                return [];
            }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:test'; }
            public function supports_capability(string $cap): bool { return $cap === MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX; }
        };
    }

    protected function setUp(): void {
        // Wipe the per-request memoisation cache between tests — otherwise
        // a previous test's ensure_schema() short-circuits this one.
        $r = new ReflectionProperty(MxChat_Plus_DuckDB_Vector_Store_Schema::class, 'ensured');
        $r->setValue(null, []);
    }

    // ─── Migration runner ─────────────────────────────────────────────────

    public function test_fresh_install_runs_all_migrations_in_order(): void {
        $conn = $this->makeRecordingConnection([]);
        $schema = new MxChat_Plus_DuckDB_Vector_Store_Schema($conn, 'mxchat_vectors', 1536, 'cosine', true, 'float32');

        $schema->ensure_schema();

        // The runner walks from get_schema_version() (returns 0 here because
        // the SELECT against the meta table comes back empty) up to TARGET=4.
        // We should see the v1 base CREATE TABLE (already in the v4 layout),
        // the v2 ADD COLUMN, the v3 FTS install, and one version stamp each.
        $log = implode("\n", $conn->log);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS "mxchat_plus_duckdb_schema_meta"', $log);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS "mxchat_vectors"', $log);
        $this->assertStringContainsString('PRIMARY KEY (bot_id, vector_id)', $log,
            'a fresh install gets the composite key straight away');
        $this->assertStringContainsString('ADD COLUMN IF NOT EXISTS updated_at', $log);
        $this->assertStringContainsString('create_fts_index', $log);

        // Version stamping: one INSERT per migration applied.
        $version_writes = array_filter($conn->log, fn($sql) =>
            stripos($sql, 'INSERT OR REPLACE INTO "mxchat_plus_duckdb_schema_meta"') !== false
            && stripos($sql, 'schema_version') !== false);
        $this->assertCount(4, $version_writes, 'expected v1, v2, v3, v4 to each stamp their version');
    }

    public function test_resuming_from_v1_skips_v1_and_runs_v2_to_v4(): void {
        // Mock the SELECT against meta_table to return value=1, simulating
        // an install that already ran migration v1.
        $conn = $this->makeRecordingConnection([
            "SELECT value FROM \"mxchat_plus_duckdb_schema_meta\"" => [['value' => '1']],
        ]);
        $schema = new MxChat_Plus_DuckDB_Vector_Store_Schema($conn, 'mxchat_vectors', 1536, 'cosine', true, 'float32');

        $schema->ensure_schema();

        $log = implode("\n", $conn->log);
        // v1 (base CREATE TABLE on mxchat_vectors) must NOT fire again.
        $this->assertStringNotContainsString('CREATE TABLE IF NOT EXISTS "mxchat_vectors"', $log,
            'migration v1 should not re-run on an install already at v1');
        // v2 (ADD COLUMN) and v3 (FTS) should fire.
        $this->assertStringContainsString('ADD COLUMN IF NOT EXISTS updated_at', $log);
        $this->assertStringContainsString('create_fts_index', $log);

        // Three version stamps (v2, v3, v4) — filtered to schema_version so
        // the fts_available flag (also written by v3) doesn't pollute the
        // count. The fts_available marker has its own assertion below.
        $version_writes = array_filter($conn->log, fn($sql) =>
            stripos($sql, 'INSERT OR REPLACE INTO "mxchat_plus_duckdb_schema_meta"') !== false
            && stripos($sql, 'schema_version') !== false);
        $this->assertCount(3, $version_writes);
        $this->assertStringContainsString("'fts_available'", $log,
            'migration v3 must persist the FTS availability flag so the read path can skip BM25 when missing');
    }

    public function test_install_already_at_target_runs_no_migration(): void {
        $conn = $this->makeRecordingConnection([
            "SELECT value FROM \"mxchat_plus_duckdb_schema_meta\"" => [['value' => (string) MxChat_Plus_DuckDB_Vector_Store_Schema::TARGET_SCHEMA_VERSION]],
        ]);
        $schema = new MxChat_Plus_DuckDB_Vector_Store_Schema($conn, 'mxchat_vectors', 1536, 'cosine', true, 'float32');

        $schema->ensure_schema();

        $log = implode("\n", $conn->log);
        $this->assertStringNotContainsString('CREATE TABLE IF NOT EXISTS "mxchat_vectors"', $log);
        $this->assertStringNotContainsString('ADD COLUMN', $log);
        $this->assertStringNotContainsString('create_fts_index', $log);
        // Only the meta table CREATE + the version SELECT should appear.
        $this->assertCount(2, $conn->log, 'ensure_meta + get_schema_version SELECT — nothing else');
    }

    public function test_ensure_schema_is_memoised_per_request(): void {
        $conn = $this->makeRecordingConnection([]);
        $schema = new MxChat_Plus_DuckDB_Vector_Store_Schema($conn, 'mxchat_vectors', 1536, 'cosine', true, 'float32');

        $schema->ensure_schema();
        $first_call_count = count($conn->log);

        // Second call must not re-issue any SQL — same backend+table+dim.
        $schema->ensure_schema();
        $this->assertCount($first_call_count, $conn->log,
            'second ensure_schema() on the same instance must short-circuit');
    }

    public function test_memoisation_is_keyed_on_backend_table_dim_combo(): void {
        $conn = $this->makeRecordingConnection([]);
        $schema1 = new MxChat_Plus_DuckDB_Vector_Store_Schema($conn, 'mxchat_vectors', 1536, 'cosine', true, 'float32');
        $schema2 = new MxChat_Plus_DuckDB_Vector_Store_Schema($conn, 'other_table',    1536, 'cosine', true, 'float32');

        $schema1->ensure_schema();
        $count_after_first = count($conn->log);
        $schema2->ensure_schema();
        $this->assertGreaterThan($count_after_first, count($conn->log),
            'a different table name should NOT hit the memoisation cache');
    }

    // ─── Column type branching ────────────────────────────────────────────

    public function test_float32_storage_creates_float_n_column(): void {
        $conn = $this->makeRecordingConnection([]);
        $schema = new MxChat_Plus_DuckDB_Vector_Store_Schema($conn, 'mxchat_vectors', 1536, 'cosine', true, 'float32');
        $schema->ensure_schema();

        $create_table = self::findStatementContaining($conn->log, 'CREATE TABLE IF NOT EXISTS "mxchat_vectors"');
        $this->assertNotNull($create_table);
        $this->assertStringContainsString('embedding        FLOAT[1536]', $create_table);
    }

    public function test_int8_storage_creates_tinyint_n_column(): void {
        $conn = $this->makeRecordingConnection([]);
        $schema = new MxChat_Plus_DuckDB_Vector_Store_Schema($conn, 'mxchat_vectors', 1536, 'cosine', true, 'int8');
        $schema->ensure_schema();

        $create_table = self::findStatementContaining($conn->log, 'CREATE TABLE IF NOT EXISTS "mxchat_vectors"');
        $this->assertNotNull($create_table);
        $this->assertStringContainsString('embedding        TINYINT[1536]', $create_table);
    }

    // ─── HNSW index gating ────────────────────────────────────────────────

    public function test_hnsw_disabled_skips_index_creation(): void {
        $conn = $this->makeRecordingConnection([]);
        $schema = new MxChat_Plus_DuckDB_Vector_Store_Schema($conn, 'mxchat_vectors', 1536, 'cosine', false, 'float32');
        $schema->ensure_schema();

        $log = implode("\n", $conn->log);
        $this->assertStringNotContainsString('USING HNSW', $log,
            'with hnsw=false the index DDL must not appear');
        $this->assertStringContainsString("'hnsw_available'", $log,
            'hnsw=false still records the verdict in meta so the admin UI knows queries are brute-force');
    }

    public function test_motherduck_backend_skips_hnsw_and_vss_install(): void {
        // A connection that reports no support for the persistent VSS
        // index (e.g. MotherDuck cloud, per
        // https://motherduck.com/docs/concepts/duckdb-extensions/) must
        // make the migration skip the INSTALL/LOAD round-trips and the
        // CREATE INDEX entirely, and persist hnsw_available='0' so the
        // admin UI + any introspection code know queries will be brute-force.
        $conn = new class implements MxChat_Plus_DuckDB_Connection {
            public array $log = [];
            public function execute(string $sql, array $params = []): array {
                $this->log[] = $sql;
                return [];
            }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'motherduck:my_db (ext)'; }
            public function supports_capability(string $cap): bool { return false; /* no VSS */ }
        };

        $schema = new MxChat_Plus_DuckDB_Vector_Store_Schema($conn, 'mxchat_vectors', 1536, 'cosine', true, 'float32');
        $schema->ensure_schema();

        $log = implode("\n", $conn->log);
        $this->assertStringNotContainsString('INSTALL vss', $log,
            'no-VSS backend → no point installing the extension that is unsupported');
        $this->assertStringNotContainsString('USING HNSW', $log,
            'no-VSS backend → the HNSW DDL would just fail or no-op silently, so we skip it cleanly');
        $this->assertStringContainsString("'hnsw_available'", $log);
        // hnsw_available must persist as '0' so a later read picks up the verdict.
        $hnsw_writes = array_filter($conn->log, fn($sql) =>
            stripos($sql, 'INSERT OR REPLACE INTO "mxchat_plus_duckdb_schema_meta"') !== false
            && stripos($sql, 'hnsw_available') !== false
            && stripos($sql, "'0'") !== false);
        $this->assertNotEmpty($hnsw_writes,
            'hnsw_available must be stamped as "0" so the admin UI can show the brute-force fallback state');
    }

    public function test_hnsw_enabled_creates_index_with_matching_metric(): void {
        foreach (['cosine', 'l2sq', 'ip'] as $metric) {
            // Each test instance needs its own memoisation reset because we
            // share the static cache across iterations of this loop.
            $r = new ReflectionProperty(MxChat_Plus_DuckDB_Vector_Store_Schema::class, 'ensured');
            $r->setValue(null, []);

            $conn = $this->makeRecordingConnection([]);
            $schema = new MxChat_Plus_DuckDB_Vector_Store_Schema($conn, 'mxchat_vectors', 1536, $metric, true, 'float32');
            $schema->ensure_schema();

            $log = implode("\n", $conn->log);
            $this->assertStringContainsString("USING HNSW", $log, "metric=$metric: HNSW must be created");
            $this->assertStringContainsString("metric = '$metric'", $log,
                "metric=$metric: HNSW index parameter must echo the metric");
        }
    }

    // ─── table_info ───────────────────────────────────────────────────────

    public function test_table_info_returns_count_when_table_exists(): void {
        $conn = $this->makeRecordingConnection([
            'SELECT COUNT(*)' => [['c' => 42]],
        ]);
        $schema = new MxChat_Plus_DuckDB_Vector_Store_Schema($conn, 'mxchat_vectors', 1536, 'cosine', true, 'float32');
        $this->assertSame(['count' => 42], $schema->table_info());
    }

    public function test_table_info_returns_null_when_table_does_not_exist(): void {
        // The recording connection ignores patterns it doesn't know — we
        // override execute to throw, simulating "table not found".
        $conn = new class implements MxChat_Plus_DuckDB_Connection {
            public function execute(string $sql, array $params = []): array {
                throw new RuntimeException('Table "mxchat_vectors" does not exist');
            }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:throwing'; }
            public function supports_capability(string $cap): bool { return $cap === MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX; }
        };
        $schema = new MxChat_Plus_DuckDB_Vector_Store_Schema($conn, 'mxchat_vectors', 1536, 'cosine', true, 'float32');
        $this->assertNull($schema->table_info());
    }

    // ─── v4: composite key rebuild ────────────────────────────────────────

    /**
     * A connection that keeps a tiny catalog (table => columns) so the v4
     * migration's introspection and its CREATE/DROP statements interact the
     * way they do on DuckDB. Every statement is recorded.
     */
    private function makeCatalogConnection(array $tables, string $version = '3'): MxChat_Plus_DuckDB_Connection {
        return new class($tables, $version) implements MxChat_Plus_DuckDB_Connection {
            public array $log = [];
            public function __construct(public array $tables, public string $version) {}
            public function execute(string $sql, array $params = []): array {
                $this->log[] = $sql;
                if (stripos($sql, 'SELECT value FROM "mxchat_plus_duckdb_schema_meta"') !== false) {
                    return stripos($sql, 'schema_version') !== false ? [['value' => $this->version]] : [];
                }
                if (preg_match("/pragma_table_info\\('([^']+)'\\)/", $sql, $m)) {
                    if (!isset($this->tables[$m[1]])) throw new RuntimeException('Catalog Error: Table with name ' . $m[1] . ' does not exist!');
                    return $this->tables[$m[1]];
                }
                if (preg_match('/^CREATE TABLE "([^"]+)" AS SELECT \* FROM "([^"]+)"/', $sql, $m)) {
                    $this->tables[$m[1]] = $this->tables[$m[2]];
                    return [];
                }
                if (preg_match('/^DROP TABLE (?:IF EXISTS )?"([^"]+)"/', $sql, $m)) {
                    unset($this->tables[$m[1]]);
                    return [];
                }
                if (preg_match('/^CREATE TABLE IF NOT EXISTS "(mxchat_vectors[^"]*)" \(/', $sql, $m) && !isset($this->tables[$m[1]])) {
                    $this->tables[$m[1]] = array_map(
                        fn($c) => ['name' => $c, 'pk' => in_array($c, ['bot_id', 'vector_id'], true), 'type' => $c === 'embedding' ? 'FLOAT[3]' : 'VARCHAR'],
                        MxChat_Plus_DuckDB_Vector_Store_Schema::COLUMNS
                    );
                    return [];
                }
                if (stripos($sql, 'SELECT DISTINCT bot_id') !== false) {
                    return [['bot_id' => 'default'], ['bot_id' => 'sales']];
                }
                return [];
            }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:catalog'; }
            public function supports_capability(string $cap): bool { return $cap === MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX; }
        };
    }

    /** pragma_table_info rows of a pre-v4 table (vector_id is the only key). */
    private static function legacyColumns(string $embedding_type = 'FLOAT[3]', bool $with_updated_at = false): array {
        $cols = ['vector_id', 'bot_id', 'embedding', 'content', 'source_url', 'role_restriction',
                 'content_type', 'chunk_index', 'total_chunks', 'is_chunked', 'created_at'];
        if ($with_updated_at) $cols[] = 'updated_at';
        return array_map(fn($c) => [
            'name' => $c,
            'pk'   => $c === 'vector_id',
            'type' => $c === 'embedding' ? $embedding_type : 'VARCHAR',
        ], $cols);
    }

    private static function indexOf(array $log, string $needle): int {
        foreach ($log as $i => $sql) {
            if (stripos($sql, $needle) !== false) return $i;
        }
        return -1;
    }

    public function test_v4_rebuilds_a_legacy_table_through_a_staging_copy(): void {
        MxChat_Plus_DuckDB_Cache::$cache_gen_map = [];
        $conn = $this->makeCatalogConnection(['mxchat_vectors' => self::legacyColumns()]);
        $schema = new MxChat_Plus_DuckDB_Vector_Store_Schema($conn, 'mxchat_vectors', 3, 'cosine', true, 'float32');

        $schema->ensure_schema();
        $log = $conn->log;

        $stage    = self::indexOf($log, 'CREATE TABLE "mxchat_vectors_v4_staging" AS SELECT * FROM "mxchat_vectors"');
        $drop_fts = self::indexOf($log, "PRAGMA drop_fts_index('mxchat_vectors')");
        $drop     = self::indexOf($log, 'DROP TABLE "mxchat_vectors"');
        $create   = self::indexOf($log, 'CREATE TABLE IF NOT EXISTS "mxchat_vectors" (');
        $copy     = self::indexOf($log, 'INSERT OR IGNORE INTO "mxchat_vectors"');
        $hnsw     = self::indexOf($log, 'USING HNSW');
        $fts      = self::indexOf($log, "PRAGMA create_fts_index('mxchat_vectors', 'row_key', 'content', overwrite = 1)");
        $unstage  = self::indexOf($log, 'DROP TABLE IF EXISTS "mxchat_vectors_v4_staging"');

        // DuckDB cannot alter a primary key, nor ALTER/RENAME a table an
        // index depends on: copy out, drop indexes + table, recreate, copy
        // back, rebuild the indexes, drop the copy.
        foreach (compact('stage', 'drop_fts', 'drop', 'create', 'copy', 'hnsw', 'fts', 'unstage') as $step => $at) {
            $this->assertGreaterThanOrEqual(0, $at, "step $step must run");
        }
        $this->assertTrue($stage < $drop_fts && $drop_fts < $drop && $drop < $create && $create < $copy
            && $copy < $hnsw && $hnsw < $fts && $fts < $unstage, 'steps in a crash-safe order');
        $this->assertStringContainsString('PRIMARY KEY (bot_id, vector_id)', $log[$create]);

        $copy_sql = $log[$copy];
        $this->assertStringContainsString('(' . implode(', ', MxChat_Plus_DuckDB_Vector_Store_Schema::COLUMNS) . ')', $copy_sql,
            'the copy names every v4 column');
        $this->assertStringContainsString("COALESCE(NULLIF(bot_id, ''), 'default')", $copy_sql);
        $this->assertStringContainsString("chr(31)", $copy_sql, 'row_key computed during the copy');
        $this->assertStringContainsString('COALESCE(created_at, current_timestamp), NULL', $copy_sql,
            'updated_at falls back to created_at when v2 never added it; title starts NULL');
        $this->assertStringContainsString('FROM "mxchat_vectors_v4_staging"', $copy_sql);

        // Cached top-Ks from before the rebuild carried role_restriction
        // 'public' and no title: every bot's cache is invalidated.
        $this->assertGreaterThan(1, MxChat_Plus_DuckDB_Cache::cache_generation('default'));
        $this->assertGreaterThan(1, MxChat_Plus_DuckDB_Cache::cache_generation('sales'));
        $this->assertArrayNotHasKey('mxchat_vectors_v4_staging', $conn->tables);
    }

    public function test_v4_is_a_no_op_on_a_table_already_in_the_v4_layout(): void {
        $v4 = array_map(fn($c) => ['name' => $c, 'pk' => in_array($c, ['bot_id', 'vector_id'], true), 'type' => $c === 'embedding' ? 'FLOAT[3]' : 'VARCHAR'],
            MxChat_Plus_DuckDB_Vector_Store_Schema::COLUMNS);
        $conn = $this->makeCatalogConnection(['mxchat_vectors' => $v4]);
        $schema = new MxChat_Plus_DuckDB_Vector_Store_Schema($conn, 'mxchat_vectors', 3, 'cosine', true, 'float32');
        $schema->ensure_schema();

        $log = implode("\n", $conn->log);
        $this->assertStringNotContainsString('_v4_staging" AS SELECT', $log);
        $this->assertStringNotContainsString('DROP TABLE "mxchat_vectors"', $log);
    }

    public function test_v4_resumes_from_the_staging_copy_after_a_crash(): void {
        // Crash between DROP of the old table and the copy back: only the
        // staging copy holds the rows. The next request must restore them.
        $conn = $this->makeCatalogConnection(['mxchat_vectors_v4_staging' => self::legacyColumns()]);
        $schema = new MxChat_Plus_DuckDB_Vector_Store_Schema($conn, 'mxchat_vectors', 3, 'cosine', false, 'float32');
        $schema->ensure_schema();

        $log = $conn->log;
        $create = self::indexOf($log, 'CREATE TABLE IF NOT EXISTS "mxchat_vectors" (');
        $copy   = self::indexOf($log, 'INSERT OR IGNORE INTO "mxchat_vectors"');
        $this->assertGreaterThanOrEqual(0, $create);
        $this->assertGreaterThan($create, $copy);
        $this->assertStringContainsString('FROM "mxchat_vectors_v4_staging"', $log[$copy]);
        $this->assertSame(-1, self::indexOf($log, 'CREATE TABLE "mxchat_vectors_v4_staging" AS'),
            'the staging copy is the only copy left — never overwritten');
        $this->assertArrayNotHasKey('mxchat_vectors_v4_staging', $conn->tables);
    }

    public function test_v4_discards_a_stale_staging_copy_while_the_old_table_still_exists(): void {
        // Crash after the staging copy, before the old table was dropped:
        // the old table is complete, the copy may not be.
        $conn = $this->makeCatalogConnection([
            'mxchat_vectors'            => self::legacyColumns(),
            'mxchat_vectors_v4_staging' => self::legacyColumns(),
        ]);
        $schema = new MxChat_Plus_DuckDB_Vector_Store_Schema($conn, 'mxchat_vectors', 3, 'cosine', false, 'float32');
        $schema->ensure_schema();

        $log = $conn->log;
        $discard = self::indexOf($log, 'DROP TABLE IF EXISTS "mxchat_vectors_v4_staging"');
        $restage = self::indexOf($log, 'CREATE TABLE "mxchat_vectors_v4_staging" AS SELECT * FROM "mxchat_vectors"');
        $this->assertGreaterThanOrEqual(0, $discard);
        $this->assertGreaterThan($discard, $restage, 'stale copy dropped, a fresh one taken');
    }

    public function test_v4_refuses_when_the_stored_vectors_do_not_fit_the_settings(): void {
        // Dimension changed without a table switch: copying back would fail
        // with every row parked in staging. Refuse before touching anything.
        $conn = $this->makeCatalogConnection(['mxchat_vectors' => self::legacyColumns('FLOAT[1536]')]);
        $schema = new MxChat_Plus_DuckDB_Vector_Store_Schema($conn, 'mxchat_vectors', 3, 'cosine', false, 'float32');
        try {
            $schema->ensure_schema();
            $this->fail('expected a refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('refused', $e->getMessage());
        }
        $this->assertArrayHasKey('mxchat_vectors', $conn->tables);
        $this->assertSame(-1, self::indexOf($conn->log, 'DROP TABLE'));
        $stamps = array_filter($conn->log, fn($sql) => stripos($sql, "'schema_version', '4'") !== false);
        $this->assertEmpty($stamps, 'v4 must not be stamped after a refusal');
    }

    public function test_v4_never_stamps_a_table_it_could_not_introspect_without_its_columns(): void {
        // A backend answering nothing to pragma_table_info (no real DuckDB
        // does for an existing table): v4 probes the new columns instead of
        // assuming. On a legacy table the probe throws, so v4 is not
        // recorded — every upsert names those columns.
        $conn = new class implements MxChat_Plus_DuckDB_Connection {
            public array $log = [];
            public function execute(string $sql, array $params = []): array {
                $this->log[] = $sql;
                if (stripos($sql, 'SELECT value FROM "mxchat_plus_duckdb_schema_meta"') !== false && stripos($sql, 'schema_version') !== false) {
                    return [['value' => '3']];
                }
                if (stripos($sql, 'SELECT row_key, title') !== false) {
                    throw new RuntimeException('Binder Error: Referenced column "row_key" not found');
                }
                return [];
            }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:opaque'; }
            public function supports_capability(string $cap): bool { return true; }
        };
        $schema = new MxChat_Plus_DuckDB_Vector_Store_Schema($conn, 'mxchat_vectors', 3, 'cosine', false, 'float32');
        try {
            $schema->ensure_schema();
            $this->fail('the probe must surface');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('row_key', $e->getMessage());
        }
        $stamps = array_filter($conn->log, fn($sql) => stripos($sql, "'schema_version', '4'") !== false);
        $this->assertEmpty($stamps);
    }

    // ─── Copy SQL + row key ───────────────────────────────────────────────

    public function test_copy_rows_sql_fills_missing_columns_and_recomputes_row_key(): void {
        $schema = new MxChat_Plus_DuckDB_Vector_Store_Schema($this->makeRecordingConnection(), 'mxchat_vectors', 3, 'cosine', false, 'int8');
        $sql = $schema->copy_rows_sql('INSERT OR REPLACE', "read_parquet('/x.parquet')", ['vector_id', 'embedding', 'content', 'row_key']);

        $this->assertStringStartsWith('INSERT OR REPLACE INTO "mxchat_vectors" (vector_id, bot_id, embedding,', $sql);
        $this->assertStringContainsString("CAST(embedding AS TINYINT[3])", $sql);
        $this->assertStringContainsString("'default'", $sql, 'a source without bot_id lands under default');
        $this->assertStringContainsString("'public'", $sql);
        $this->assertStringContainsString("('default') || chr(31) || (CAST(vector_id AS VARCHAR))", $sql,
            'row_key is recomputed, never copied from the source');
        $this->assertStringEndsWith("FROM read_parquet('/x.parquet')", $sql);
    }

    public function test_copy_rows_sql_rejects_unknown_verbs_and_sources_without_vectors(): void {
        $schema = new MxChat_Plus_DuckDB_Vector_Store_Schema($this->makeRecordingConnection(), 'mxchat_vectors', 3, 'cosine', false, 'float32');
        try {
            $schema->copy_rows_sql('DELETE', '"x"', ['vector_id', 'embedding']);
            $this->fail('verb must be whitelisted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('unsupported verb', $e->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $schema->copy_rows_sql('INSERT', '"x"', ['vector_id', 'content']);
    }

    public function test_row_key_php_and_sql_agree(): void {
        $this->assertSame("sales\x1F" . 'abc_chunk_0', MxChat_Plus_DuckDB_Vector_Store_Schema::row_key('sales', 'abc_chunk_0'));
        $this->assertSame("('sales') || chr(31) || ('abc')", MxChat_Plus_DuckDB_Vector_Store_Schema::row_key_sql("'sales'", "'abc'"));
    }

    public function test_is_v4_layout_needs_row_key_and_the_composite_key(): void {
        $legacy = [];
        foreach (self::legacyColumns() as $c) $legacy[$c['name']] = ['pk' => $c['pk'], 'type' => $c['type']];
        $this->assertFalse(MxChat_Plus_DuckDB_Vector_Store_Schema::is_v4_layout($legacy));
        $legacy['row_key'] = ['pk' => false, 'type' => 'VARCHAR'];
        $this->assertFalse(MxChat_Plus_DuckDB_Vector_Store_Schema::is_v4_layout($legacy), 'row_key alone is not enough');
        $legacy['bot_id']['pk'] = true;
        $this->assertTrue(MxChat_Plus_DuckDB_Vector_Store_Schema::is_v4_layout($legacy));
    }

    // ─── helper ───────────────────────────────────────────────────────────

    private static function findStatementContaining(array $log, string $needle): ?string {
        foreach ($log as $sql) {
            if (stripos($sql, $needle) !== false) return $sql;
        }
        return null;
    }
}

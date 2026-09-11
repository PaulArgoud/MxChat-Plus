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
        // the SELECT against the meta table comes back empty) up to TARGET=3.
        // We should see the v1 base CREATE TABLE, the v2 ADD COLUMN, the v3
        // FTS install, and three INSERT OR REPLACE into the meta table.
        $log = implode("\n", $conn->log);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS "mxchat_plus_duckdb_schema_meta"', $log);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS "mxchat_vectors"', $log);
        $this->assertStringContainsString('ADD COLUMN IF NOT EXISTS updated_at', $log);
        $this->assertStringContainsString('create_fts_index', $log);

        // Version stamping: one INSERT per migration applied.
        $version_writes = array_filter($conn->log, fn($sql) =>
            stripos($sql, 'INSERT OR REPLACE INTO "mxchat_plus_duckdb_schema_meta"') !== false
            && stripos($sql, 'schema_version') !== false);
        $this->assertCount(3, $version_writes, 'expected v1, v2, v3 to each stamp their version');
    }

    public function test_resuming_from_v1_skips_v1_and_runs_only_v2_and_v3(): void {
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

        // Only two version stamps (v2, v3) — filtered to schema_version so
        // the new fts_available flag (also written by v3) doesn't pollute
        // the count. The fts_available marker has its own assertion below.
        $version_writes = array_filter($conn->log, fn($sql) =>
            stripos($sql, 'INSERT OR REPLACE INTO "mxchat_plus_duckdb_schema_meta"') !== false
            && stripos($sql, 'schema_version') !== false);
        $this->assertCount(2, $version_writes);
        $this->assertStringContainsString("'fts_available'", $log,
            'migration v3 must persist the FTS availability flag so the read path can skip BM25 when missing');
    }

    public function test_install_already_at_target_runs_no_migration(): void {
        $conn = $this->makeRecordingConnection([
            "SELECT value FROM \"mxchat_plus_duckdb_schema_meta\"" => [['value' => '3']],
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

    // ─── helper ───────────────────────────────────────────────────────────

    private static function findStatementContaining(array $log, string $needle): ?string {
        foreach ($log as $sql) {
            if (stripos($sql, $needle) !== false) return $sql;
        }
        return null;
    }
}

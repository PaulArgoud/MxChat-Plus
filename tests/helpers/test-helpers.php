<?php
/**
 * Shared test helpers — eliminate the per-test boilerplate that was
 * copy-pasted across the unit suite.
 *
 *   - MxChat_Test_Helpers: reset memoisation caches, inject a mock
 *     connection into Connection_Factory's cache, all via reflection
 *     so production code stays untouched by test backdoors.
 *
 *   - MxChat_Test_RecordingConnection: a Connection implementation that
 *     records every executed SQL statement and matches canned responses
 *     by substring pattern. Replaces the anonymous-class definition that
 *     was repeated in ~8 test files with subtle drift between copies.
 */

if (!class_exists('MxChat_Test_Helpers')) {

    class MxChat_Test_Helpers {

        /**
         * Clear the per-request memoisation cache on Vector_Store_Schema so
         * the next ensure_schema() call actually re-evaluates against the
         * current mock connection (instead of returning from the cache
         * populated by a previous test).
         */
        public static function reset_schema_memoisation(): void {
            $r = new ReflectionProperty(MxChat_Plus_DuckDB_Vector_Store_Schema::class, 'ensured');
            $r->setValue(null, []);
        }

        /**
         * Drop the Vector_Store::current() singleton so the next call
         * builds a fresh instance against the test's mock connection.
         */
        public static function reset_vector_store_current(): void {
            $r = new ReflectionProperty(MxChat_Plus_DuckDB_Vector_Store::class, 'current');
            $r->setValue(null, null);
        }

        /**
         * Clear the Mysql_Sync class-level extension-availability cache so
         * the next has_duckdb_mysql_extension() call re-evaluates against
         * the current mock connection.
         */
        public static function reset_mysql_extension_cache(): void {
            $r = new ReflectionProperty(MxChat_Plus_DuckDB_Mysql_Sync::class, 'mysql_ext_available');
            $r->setValue(null, null);
        }

        /**
         * Pre-populate Connection_Factory::$cache with the given mock so
         * any `new MxChat_Plus_DuckDB_Vector_Store()` (without an explicit
         * connection) sees the mock instead of trying to spin up a real
         * DuckDB backend.
         *
         * The cache key is computed from the current plugin options via
         * reflection on Connection_Factory::cache_key() so it matches
         * whatever the production code will look up.
         */
        public static function inject_mock_connection(MxChat_Plus_DuckDB_Connection $conn): void {
            MxChat_Plus_DuckDB_Connection_Factory::reset_cache();
            $cache = new ReflectionProperty(MxChat_Plus_DuckDB_Connection_Factory::class, 'cache');
            $key_method = new ReflectionMethod(MxChat_Plus_DuckDB_Connection_Factory::class, 'cache_key');
            $key = $key_method->invoke(null, MxChat_Plus_DuckDB_Options::get());
            $cache->setValue(null, [$key => $conn]);
        }

        /**
         * Wipe every shared cache + singleton + reset_test_globals call
         * that almost every test's setUp() needs. Combines the three
         * reset_* helpers above into one call.
         */
        public static function reset_all(): void {
            self::reset_schema_memoisation();
            self::reset_vector_store_current();
            self::reset_mysql_extension_cache();
        }
    }
}

if (!class_exists('MxChat_Test_RecordingConnection')) {

    /**
     * In-memory Connection implementation that logs every SQL it sees
     * and returns canned responses matched by substring pattern.
     *
     * Usage:
     *     $conn = new MxChat_Test_RecordingConnection();
     *     $conn->responses['SELECT COUNT(*)'] = [['c' => 42]];
     *     // ... pass $conn to a Vector_Store / Sync / Compactor instance
     *     $this->assertStringContainsString('INSERT', implode("\n", $conn->log));
     *
     * Callable responses receive the SQL string and return the rows
     * dynamically (useful for paginated mocks that return one batch then
     * empty).
     *
     * The constructor takes an optional identifier so tests with multiple
     * connections can distinguish them in failure messages.
     *
     * Schema-meta SELECTs are short-circuited to return v3 so
     * ensure_schema() doesn't run any migrations against the mock. Tests
     * that want to exercise the migration runner should override this by
     * setting a 'schema_meta' response themselves OR by passing
     * skip_schema_meta_shortcircuit=true to the constructor.
     */
    class MxChat_Test_RecordingConnection implements MxChat_Plus_DuckDB_Connection {

        /** @var string[] every SQL passed to execute() in order */
        public array $log = [];

        /** @var array<string, mixed> pattern → response (array or callable) */
        public array $responses = [];

        public bool $ping_returns = true;
        public string $identifier;

        public function __construct(string $identifier = 'test:mock', array $responses = []) {
            $this->identifier = $identifier;
            $this->responses  = $responses;
        }

        public function execute(string $sql, array $params = []): array {
            $this->log[] = $sql;

            // Schema-meta short-circuit: tests that haven't explicitly
            // overridden this don't want migration DDL noise in their log.
            if (!isset($this->responses['schema_meta'])
                && stripos($sql, 'schema_meta') !== false
                && stripos($sql, 'SELECT value') !== false) {
                return [['value' => '3']];
            }

            foreach ($this->responses as $pattern => $value) {
                if ($pattern === 'schema_meta') continue; // handled above
                if (stripos($sql, $pattern) !== false) {
                    return is_callable($value) ? $value($sql) : (is_array($value) ? $value : []);
                }
            }
            return [];
        }

        public function ping(): bool {
            return $this->ping_returns;
        }

        public function identifier(): string {
            return $this->identifier;
        }

        /** @var array<string,bool> capability overrides set by individual tests */
        public array $capabilities = [];

        public function supports_capability(string $capability): bool {
            if (array_key_exists($capability, $this->capabilities)) {
                return $this->capabilities[$capability];
            }
            // Default: pretend to be a full-featured local backend, so
            // tests that don't care about capability nuances get the
            // path they expect (VSS available, HNSW DDL fires, …).
            return $capability === MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX;
        }
    }
}

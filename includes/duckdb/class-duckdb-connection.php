<?php
/**
 * Connection abstraction. Factory returns the right backend implementation
 * and caches it for the lifetime of the request to avoid re-spawning CLI
 * processes / re-running ATTACH on every call.
 */

if (!defined('ABSPATH')) {
    exit;
}

interface MxChat_Plus_DuckDB_Connection {

    /**
     * Capability tokens used by callers that need to adapt their behaviour
     * to what the backend can actually do. Connections answer via
     * supports_capability(). Tokens are namespaced strings so the set is
     * extensible without an interface bump every time a new gap is found.
     *
     * Currently used:
     *   - CAP_VSS_PERSISTENT_INDEX: backend stores CREATE INDEX … USING HNSW
     *     persistently and queries can leverage it. False on MotherDuck cloud
     *     (the VSS extension is not supported cloud-side, see
     *     https://motherduck.com/docs/concepts/duckdb-extensions/). True on
     *     local DuckDB (embedded mode).
     */
    public const CAP_VSS_PERSISTENT_INDEX = 'vss.persistent_index';

    /**
     * Execute a SQL statement. Parameters are bound by ? placeholders.
     * Returns an array of associative-array rows. Empty array on non-SELECT.
     *
     * @throws RuntimeException on any error.
     */
    public function execute(string $sql, array $params = []): array;

    /**
     * Lightweight liveness check. Returns true if the backend responds.
     */
    public function ping(): bool;

    /**
     * Human-readable backend identifier (motherduck:db_name / embedded:/path/to.duckdb).
     */
    public function identifier(): string;

    /**
     * Capability probe. Implementations should return the conservative
     * answer when in doubt — returning `true` for a capability the backend
     * doesn't actually have will lead to silent failure or wasted work,
     * whereas returning `false` only degrades to a fallback path.
     *
     * Unknown capability tokens MUST return false (forward-compat: a
     * caller asking about a brand-new capability gets a clean "no, plan
     * accordingly" instead of a fatal).
     */
    public function supports_capability(string $capability): bool;
}

class MxChat_Plus_DuckDB_Connection_Factory {

    /** @var array<string, MxChat_Plus_DuckDB_Connection> */
    private static array $cache = [];

    /**
     * @throws RuntimeException if the configured backend cannot be instantiated.
     */
    public static function from_options(array $opts): MxChat_Plus_DuckDB_Connection {
        $key = self::cache_key($opts);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $mode = $opts['mode'] ?? 'motherduck';
        $primary = $mode === 'embedded'
            ? new MxChat_Plus_DuckDB_Embedded_Connection($opts)
            : new MxChat_Plus_DuckDB_MotherDuck_Connection($opts);

        // Wrap as Mirrored_Connection when (a) mode is motherduck, and
        // (b) the user has explicitly enabled the local mirror. The
        // sanitiser already rejects the toggle when mode != motherduck
        // (with a settings error), so the second condition only fires
        // on a consistent config — defence-in-depth here mirrors that.
        $conn = $primary;
        if ($mode === 'motherduck' && !empty($opts['motherduck_mirror_enabled'])
            && class_exists('MxChat_Plus_DuckDB_Mirrored_Connection')) {
            $mirror_opts = $opts;
            $mirror_opts['db_path']  = !empty($opts['motherduck_mirror_path'])
                ? (string) $opts['motherduck_mirror_path']
                : MxChat_Plus_DuckDB_Options::resolved_mirror_path();
            $local = new MxChat_Plus_DuckDB_Embedded_Connection($mirror_opts);
            $conn = new MxChat_Plus_DuckDB_Mirrored_Connection($primary, $local);
        }

        self::$cache[$key] = $conn;
        return $conn;
    }

    public static function current(): MxChat_Plus_DuckDB_Connection {
        return self::from_options(MxChat_Plus_DuckDB_Options::get());
    }

    /**
     * Drop any cached connections. Called when options are saved so the next
     * request picks up the new config without a process reload.
     */
    public static function reset_cache(): void {
        self::$cache = [];
    }

    private static function cache_key(array $opts): string {
        $mode = $opts['mode'] ?? 'motherduck';
        $fingerprint = [
            $mode,
            $opts['motherduck_database'] ?? '',
            $opts['embedded_path'] ?? '',
            // Include the token's hash, not the token itself.
            isset($opts['motherduck_token']) ? substr(md5((string) $opts['motherduck_token']), 0, 8) : '',
            // Mirror state is part of the cache key: toggling the
            // mirror on/off without a fresh request would otherwise
            // hand back a stale wrapper. The path participates too so
            // changing it forces a rebuild of the local connection.
            !empty($opts['motherduck_mirror_enabled']) ? '1' : '0',
            $opts['motherduck_mirror_path'] ?? '',
        ];
        return implode('|', $fingerprint);
    }
}

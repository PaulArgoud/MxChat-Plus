<?php
/**
 * MotherDuck connection via the official DuckDB MotherDuck extension.
 *
 * MotherDuck is *not* a generic REST/SQL HTTP service — the official client
 * path is: a local DuckDB process loads the `motherduck` extension and runs
 *   ATTACH 'md:<database>?motherduck_token=<token>';
 * then queries pass through DuckDB's native protocol. We piggyback on the
 * Embedded_Connection (PECL ext or duckdb CLI) and inject those init
 * statements as `init_sql`.
 *
 * Note: in CLI fallback mode the ATTACH runs on *every* query, which is slow
 * (network handshake + token validation per call). The admin UI surfaces a
 * warning when CLI + MotherDuck is detected; production deployments should
 * install the PECL duckdb extension.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_MotherDuck_Connection extends MxChat_Plus_DuckDB_Embedded_Connection {

    private string $md_database;

    public function __construct(array $opts) {
        // Prefer a wp-config.php constant override (MXCHAT_PLUS_MOTHERDUCK_TOKEN)
        // over the persisted option, so compliance-bound installs don't have to
        // store the token in wp_options. The caller can still pass an explicit
        // token via $opts['motherduck_token']; constant wins if present.
        $token = class_exists('MxChat_Plus_DuckDB_Options')
            ? MxChat_Plus_DuckDB_Options::resolved_motherduck_token()
            : (string) ($opts['motherduck_token'] ?? '');
        if ($token === '' && !empty($opts['motherduck_token'])) {
            $token = (string) $opts['motherduck_token'];
        }
        $database = (string) ($opts['motherduck_database'] ?? 'my_db');

        if ($token === '') {
            throw new RuntimeException(
                __('MotherDuck token is not configured.', 'mxchat-plus')
            );
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $database)) {
            throw new RuntimeException(
                __('Invalid MotherDuck database name (letters, digits and underscore only).', 'mxchat-plus')
            );
        }

        // Local DuckDB session — use an in-memory DB; all data lives in MotherDuck.
        $local_opts = $opts;
        $local_opts['db_path'] = ':memory:';

        // Use a DuckDB persistent secret rather than embedding the token in
        // every ATTACH URL. Three wins:
        //   1. Security: the token no longer flows through the SQL script piped
        //      to the CLI's stdin on every query — it lives in
        //      ~/.duckdb/stored_secrets/ as a per-server credential.
        //   2. Cleanliness: ATTACH is `'md:dbname'` (readable) instead of
        //      `'md:dbname?motherduck_token=<256-byte JWT>'`.
        //   3. Operability: rotating the token in plugin settings re-runs
        //      CREATE OR REPLACE on the next query, transparently.
        // The CREATE OR REPLACE makes it idempotent so multiple plugin
        // instances on the same server (or a token rotation) stay in sync.
        // Requires DuckDB ≥ 0.10 + the motherduck extension ≥ 0.6, both
        // current on every install where the plugin actually works.
        $escaped_token = str_replace("'", "''", $token);
        $init = [
            'INSTALL motherduck',
            'LOAD motherduck',
            sprintf(
                "CREATE OR REPLACE PERSISTENT SECRET mxchat_motherduck (TYPE motherduck, TOKEN '%s')",
                $escaped_token
            ),
            sprintf("ATTACH 'md:%s'", $database),
            sprintf('USE "%s"', $database),
        ];

        parent::__construct($local_opts, $init);
        $this->md_database = $database;
    }

    public function identifier(): string {
        return 'motherduck:' . $this->md_database . ($this->use_extension ? ' (ext)' : ' (cli)');
    }

    /**
     * MotherDuck cloud-side capability gaps versus a local DuckDB
     * (see https://motherduck.com/docs/concepts/duckdb-extensions/).
     * Falls back to the local-DuckDB defaults for anything we don't
     * explicitly override here.
     */
    public function supports_capability(string $capability): bool {
        switch ($capability) {
            case self::CAP_VSS_PERSISTENT_INDEX:
                // VSS is not supported on MotherDuck cloud tables.
                // Queries fall back to brute-force scans.
                return false;
            default:
                return parent::supports_capability($capability);
        }
    }
}

<?php
/**
 * Static classmap autoloader.
 *
 * Replaces the two divergent loading strategies the merged plugins used
 * (Composer classmap when vendor/ existed, a hand-ordered require_once list
 * otherwise). That duality was not merely redundant — it was a live fatal:
 * the manual list required the MotherDuck connection before its parent class,
 * so any install done by `git clone` (i.e. without `composer install`) died at
 * boot with "Class MxChat_Plus_DuckDB_Embedded_Connection not found".
 *
 * A classmap makes load order irrelevant by construction: a class is read from
 * disk the first time PHP asks for it, parents included, in whatever order the
 * engine needs. It also keeps the plugin free of any runtime dependency on
 * Composer — vendor/ is dev tooling (PHPUnit, PHPStan) and nothing more.
 *
 * The two CLI classes are deliberately absent: their files return early when
 * WP_CLI is undefined, so autoloading them outside WP-CLI would yield a
 * "class not found" after a successful include. They are required explicitly
 * under the WP_CLI guard in the bootstrap.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_Autoloader {

    /** @var array<string,string> class name => path relative to the plugin dir */
    private const CLASSMAP = [
        // Core
        'MxChat_Plus_Host'                          => 'includes/core/class-mxchat-plus-host.php',
        'MxChat_Plus_Modules'                       => 'includes/core/class-mxchat-plus-modules.php',
        'MxChat_Plus_Admin'                         => 'includes/core/class-mxchat-plus-admin.php',

        // DuckDB module — vector storage
        'MxChat_Plus_DuckDB_Admin'                  => 'includes/duckdb/class-duckdb-admin.php',
        'MxChat_Plus_DuckDB_Async_Reprocess'        => 'includes/duckdb/class-duckdb-async-reprocess.php',
        'MxChat_Plus_DuckDB_Cache'                  => 'includes/duckdb/class-duckdb-cache.php',
        'MxChat_Plus_DuckDB_Compactor'              => 'includes/duckdb/class-duckdb-compactor.php',
        'MxChat_Plus_DuckDB_Connection'             => 'includes/duckdb/class-duckdb-connection.php',
        'MxChat_Plus_DuckDB_Connection_Factory'     => 'includes/duckdb/class-duckdb-connection.php',
        'MxChat_Plus_DuckDB_Embedded_Connection'    => 'includes/duckdb/class-duckdb-embedded-connection.php',
        'MxChat_Plus_DuckDB_Health'                 => 'includes/duckdb/class-duckdb-health.php',
        'MxChat_Plus_DuckDB_Metrics'                => 'includes/duckdb/class-duckdb-metrics.php',
        'MxChat_Plus_DuckDB_Mirror_Bootstrap'       => 'includes/duckdb/class-duckdb-mirror-bootstrap.php',
        'MxChat_Plus_DuckDB_Mirror_Drain'           => 'includes/duckdb/class-duckdb-mirror-drain.php',
        'MxChat_Plus_DuckDB_Mirror_Drift_Check'     => 'includes/duckdb/class-duckdb-mirror-drift-check.php',
        'MxChat_Plus_DuckDB_Mirrored_Connection'    => 'includes/duckdb/class-duckdb-mirrored-connection.php',
        'MxChat_Plus_DuckDB_MotherDuck_Connection'  => 'includes/duckdb/class-duckdb-motherduck-connection.php',
        'MxChat_Plus_DuckDB_Mysql_Sync'             => 'includes/duckdb/class-duckdb-mysql-sync.php',
        'MxChat_Plus_DuckDB_Options'                => 'includes/duckdb/class-duckdb-options.php',
        'MxChat_Plus_DuckDB_Pinecone_Migrator'      => 'includes/duckdb/class-duckdb-pinecone-migrator.php',
        'MxChat_Plus_DuckDB_Pinecone_Proxy'         => 'includes/duckdb/class-duckdb-pinecone-proxy.php',
        'MxChat_Plus_DuckDB_Post_Reprocessor'       => 'includes/duckdb/class-duckdb-post-reprocessor.php',
        'MxChat_Plus_DuckDB_Quantization'           => 'includes/duckdb/class-duckdb-quantization.php',
        'MxChat_Plus_DuckDB_Search_Adapter'         => 'includes/duckdb/class-duckdb-search-adapter.php',
        'MxChat_Plus_DuckDB_SQL_Helpers_Trait'      => 'includes/duckdb/trait-duckdb-sql-helpers.php',
        'MxChat_Plus_DuckDB_Sync'                   => 'includes/duckdb/class-duckdb-sync.php',
        'MxChat_Plus_DuckDB_Vector_Store'           => 'includes/duckdb/class-duckdb-vector-store.php',
        'MxChat_Plus_DuckDB_Vector_Store_Query'     => 'includes/duckdb/class-duckdb-vector-store-query.php',
        'MxChat_Plus_DuckDB_Vector_Store_Schema'    => 'includes/duckdb/class-duckdb-vector-store-schema.php',

        // Prompt cache module — Anthropic cache_control injection + metrics
        'MxChat_Plus_PromptCache'                   => 'includes/promptcache/class-promptcache.php',
        'MxChat_Plus_PromptCache_Admin'             => 'includes/promptcache/class-promptcache-admin.php',
        'MxChat_Plus_PromptCache_Models'            => 'includes/promptcache/class-promptcache-models.php',
        'MxChat_Plus_PromptCache_Stats'             => 'includes/promptcache/class-promptcache-stats.php',

        // Transcripts module — CSV export of the host's selected conversations
        'MxChat_Plus_Transcripts_Export'            => 'includes/transcripts/class-transcripts-export.php',

        // Tracking module — Matomo/GA4 click events + a report over the host's
        // own mxchat_url_clicks table
        'MxChat_Plus_Tracking'                      => 'includes/tracking/class-tracking.php',
        'MxChat_Plus_Tracking_Admin'                => 'includes/tracking/class-tracking-admin.php',
        'MxChat_Plus_Tracking_Options'              => 'includes/tracking/class-tracking-options.php',
        'MxChat_Plus_Tracking_Report'               => 'includes/tracking/class-tracking-report.php',
    ];

    public static function register(): void {
        spl_autoload_register([__CLASS__, 'load'], true, true);
    }

    public static function load(string $class): void {
        $rel = self::CLASSMAP[$class] ?? null;
        if ($rel === null) {
            return;
        }
        $path = MXCHAT_PLUS_DIR . $rel;
        if (is_readable($path)) {
            require_once $path;
        }
    }

    /**
     * Every mapped class, for the test suite and the `doctor` command: a
     * missing file is a packaging error we want surfaced, not discovered at
     * runtime by a visitor.
     *
     * @return array<string,string>
     */
    public static function classmap(): array {
        return self::CLASSMAP;
    }
}

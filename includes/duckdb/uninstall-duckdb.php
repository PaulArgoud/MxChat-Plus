<?php
/**
 * Cleanup on plugin uninstall. Runs only when the user clicks "Delete" in the
 * Plugins screen — *not* on deactivation.
 *
 * Removes:
 *   - all plugin options (settings, proxy tokens, metrics, per-bot cache gen)
 *   - scheduled cron hooks (incremental sync, daily compactor) and every
 *     Action Scheduler action (mirror bootstrap/drain/drift, async reprocess)
 *   - named + wildcard transients (search error, rate-limit window, query
 *     cache) and the object-cache rate-limit group where supported
 *   - the embedded DuckDB data directory and any custom file path the user
 *     configured (opt-in via the constant or option below).
 *
 * Preserves by default:
 *   - the .duckdb data file (it may represent hours of embedding work the
 *     user wants to keep). Set MXCHAT_PLUS_DELETE_DATA_ON_UNINSTALL = true
 *     in wp-config.php before uninstalling — or set the
 *     `mxchat_plus_duckdb_delete_data_on_uninstall` option to a truthy value — to
 *     wipe the data too.
 *   - tables on MotherDuck. This script never makes a network call. To remove
 *     remote data, open app.motherduck.com and run
 *     `DROP TABLE IF EXISTS mxchat_vectors; DROP TABLE IF EXISTS mxchat_plus_duckdb_schema_meta;`
 *     against the configured database.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Decide once whether the user opted into data wipe. Read before options are
 * deleted, otherwise the option-based opt-in is impossible to honour.
 */
function mxchat_plus_duckdb_uninstall_delete_data_flag(): bool {
    if (defined('MXCHAT_PLUS_DELETE_DATA_ON_UNINSTALL') && MXCHAT_PLUS_DELETE_DATA_ON_UNINSTALL) {
        return true;
    }
    return (bool) get_option('mxchat_plus_duckdb_delete_data_on_uninstall', false);
}

/**
 * Load the module classes uninstall needs to read its single sources of truth
 * (scheduled hooks, query-cache transient prefix) instead of re-hard-coding
 * them here. Silent + idempotent: a missing file just means the caller falls
 * back to its own guard, never a fatal in the uninstall path.
 */
function mxchat_plus_duckdb_uninstall_load_classes(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    foreach ([
        'MxChat_Plus_DuckDB_Options'            => 'class-duckdb-options.php',
        'MxChat_Plus_DuckDB_Vector_Store_Query' => 'class-duckdb-vector-store-query.php',
    ] as $class => $file) {
        if (class_exists($class)) continue;
        if (!trait_exists('MxChat_Plus_DuckDB_SQL_Helpers_Trait')
            && is_readable(__DIR__ . '/trait-duckdb-sql-helpers.php')) {
            require_once __DIR__ . '/trait-duckdb-sql-helpers.php';
        }
        $path = __DIR__ . '/' . $file;
        if (is_readable($path)) {
            require_once $path;
        }
    }
}

/**
 * Remove every file (including dotfiles) in $dir, then rmdir() it.
 * Idempotent and silent on failure — uninstall must not crash.
 */
function mxchat_plus_duckdb_uninstall_rmtree(string $dir): void {
    if (!is_dir($dir)) return;
    $entries = @scandir($dir);
    if (!is_array($entries)) return;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path) && !is_link($path)) {
            mxchat_plus_duckdb_uninstall_rmtree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * Per-site cleanup. Called once on single-site, once per blog on multisite.
 */
function mxchat_plus_duckdb_uninstall_cleanup_site(bool $delete_data): void {
    global $wpdb;

    mxchat_plus_duckdb_uninstall_load_classes();

    // ── Capture configured paths before deleting the option ─────────────
    $opts = get_option('mxchat_plus_duckdb_options', []);
    $custom_path = is_array($opts) && !empty($opts['embedded_path'])
        ? (string) $opts['embedded_path']
        : '';
    $custom_mirror_path = is_array($opts) && !empty($opts['motherduck_mirror_path'])
        ? (string) $opts['motherduck_mirror_path']
        : '';

    // ── Options ─────────────────────────────────────────────────────────
    // Keep this list in sync with docs/CONFIGURATION.md → Sidecar options.
    // Anything the plugin writes via update_option() must be deleted here so
    // a reinstall starts from a truly clean slate.
    $sidecar_options = [
        'mxchat_plus_duckdb_options',                    // main settings bundle
        'mxchat_plus_duckdb_proxy_token',                // legacy global proxy token
        'mxchat_plus_duckdb_proxy_token_map',            // per-namespace token map
        'mxchat_plus_duckdb_metrics',                    // rolling latency histogram + counters
        'mxchat_plus_duckdb_cache_gen',                  // O(1) cache-invalidation counter (v0.6.0+)
        'mxchat_plus_duckdb_reprocess_state',            // Action Scheduler reprocess snapshot (v0.4.0+)
        'mxchat_plus_duckdb_pinecone_migration_state',   // resumable Pinecone import token (v0.4.0+)
        'mxchat_plus_duckdb_delete_data_on_uninstall',   // opt-in flag itself
        'mxchat_plus_duckdb_mirror_status',              // mirror lifecycle status (v0.10.0+)
        'mxchat_plus_duckdb_mirror_bootstrap_state',     // resumable mirror bootstrap state (v0.10.0+)
        'mxchat_plus_duckdb_mirror_pending',             // failed-local-write queue + quarantine (v0.10.0+)
        'mxchat_plus_duckdb_mirror_last_drift_check',    // drift-check timestamp (v0.10.0+)
        'mxchat_plus_duckdb_custom_embedding_dim',       // probed custom/Azure embedding dim (v0.12.0+)
    ];
    foreach ($sidecar_options as $opt) {
        delete_option($opt);
    }

    // ── Scheduled cron + Action Scheduler ───────────────────────────────
    // No local list: MxChat_Plus_DuckDB_Options::scheduled_hooks() derives every
    // hook/group pair from the scheduling classes' own constants, and
    // deactivation calls the very same routine — so a new cron can't be added
    // in one place and forgotten in the other two.
    if (class_exists('MxChat_Plus_DuckDB_Options')
        && is_callable(['MxChat_Plus_DuckDB_Options', 'unschedule_all'])) {
        MxChat_Plus_DuckDB_Options::unschedule_all();
    }

    // ── Named transient ─────────────────────────────────────────────────
    delete_transient('mxchat_plus_duckdb_search_error');

    // ── Wildcard transients (rate-limit window + query cache) ───────────
    // The transient row name pattern is _transient_<name> and _transient_timeout_<name>.
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mxchat_plus_duckdb_rl_%' ESCAPE '\\\\'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_mxchat_plus_duckdb_rl_%' ESCAPE '\\\\'");
    // Query-cache keys: prefix comes from the class that writes them
    // (MxChat_Plus_DuckDB_Vector_Store_Query::CACHE_KEY_PREFIX), already
    // LIKE-escaped — `_` is a single-char wildcard.
    if (class_exists('MxChat_Plus_DuckDB_Vector_Store_Query')) {
        $q = MxChat_Plus_DuckDB_Vector_Store_Query::cache_key_like_prefix();
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_{$q}%' ESCAPE '\\\\'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_{$q}%' ESCAPE '\\\\'");
    }

    // ── Object-cache rate-limit counters (v0.12.1+) ─────────────────────
    // With a persistent object cache, the proxy rate limiter stores per-window
    // counters in the 'mxchat_plus_duckdb_rl' group (70s TTL, so they self-expire).
    // Flush the group for immediate cleanup where the backend supports it.
    if (function_exists('wp_cache_flush_group')) {
        wp_cache_flush_group('mxchat_plus_duckdb_rl');
    }

    if (!$delete_data) return;

    // ── Data directory: default location under uploads/ ─────────────────
    $upload = wp_upload_dir();
    if (is_array($upload) && !empty($upload['basedir'])) {
        $default_dir = trailingslashit($upload['basedir']) . 'mxchat-plus-private';
        mxchat_plus_duckdb_uninstall_rmtree($default_dir);
    }

    // ── Data directory: user-configured custom path (if any) ────────────
    // Only delete .duckdb-companion files within that file's parent dir, not
    // the whole parent (the user might have pointed us inside a shared dir).
    foreach ([$custom_path, $custom_mirror_path] as $configured_path) {
        if ($configured_path === '' || !file_exists($configured_path)) continue;
        $custom_dir = dirname($configured_path);
        $base = basename($configured_path);
        @unlink($configured_path);
        // DuckDB writes <name>.wal and may leave <name>.tmp lock files.
        foreach (['.wal', '.tmp', '.lock'] as $suffix) {
            $companion = $custom_dir . DIRECTORY_SEPARATOR . $base . $suffix;
            if (file_exists($companion)) @unlink($companion);
        }
    }
}

// Ce fichier ne fait que DÉCLARER les routines de nettoyage.
// L'entrypoint (drapeau + boucle multisite) vit dans uninstall.php à la racine,
// car WordPress ne lit qu’un seul uninstall.php par dossier de plugin.

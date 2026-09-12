<?php
/**
 * Plugin Name: MxChat Plus
 * Plugin URI: https://github.com/PaulArgoud/mxchat-plus
 * Description: Extends MxChat with a DuckDB / MotherDuck vector store (an open-source, SQL-native alternative to Pinecone) and Anthropic prompt caching with cross-provider savings metrics. Companion plugin — it never modifies MxChat.
 * Version: 1.0.0
 * Author: Paul Argoud
 * Author URI: https://github.com/PaulArgoud
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mxchat-plus
 * Domain Path: /languages
 * Requires PHP: 8.1
 * Requires at least: 6.0
 *
 * Merges the former mxchat-duckdb and mxchat-promptcache plugins into a single
 * codebase with two independently switchable modules.
 *
 * Integration principle: MxChat (mxchat-basic) is a third-party plugin with its
 * own update stream. Nothing here patches it. Every feature attaches through
 * WordPress hooks and, for the vector store, a REST endpoint that speaks the
 * Pinecone wire protocol — so MxChat needs no modification and survives its own
 * updates untouched.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MXCHAT_PLUS_VERSION', '1.0.0');
define('MXCHAT_PLUS_FILE', __FILE__);
define('MXCHAT_PLUS_DIR', plugin_dir_path(__FILE__));
define('MXCHAT_PLUS_URL', plugin_dir_url(__FILE__));
// Computed once here: every path built from __FILE__ in a sub-directory file
// would resolve wrongly (a textdomain loaded from includes/promptcache/ would
// silently fail to find languages/).
define('MXCHAT_PLUS_BASENAME', plugin_basename(__FILE__));

define('MXCHAT_PLUS_DUCKDB_OPTION_KEY', 'mxchat_plus_duckdb_options');
// Renamed from the standalone plugin's `mxchat_tracking_options`: the
// mxchat_plus_ prefix is the only thing separating our options from the host's,
// and a key called mxchat_* reads as one of MxChat's own.
// MxChat_Plus_Tracking_Options::migrate_legacy() carries the old value over.
define('MXCHAT_PLUS_TRACKING_OPTION_KEY', 'mxchat_plus_tracking_options');

require_once MXCHAT_PLUS_DIR . 'includes/core/class-mxchat-plus-autoloader.php';
MxChat_Plus_Autoloader::register();

register_activation_hook(__FILE__, ['MxChat_Plus_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['MxChat_Plus_Plugin', 'deactivate']);

// Textdomain on `init`, not `plugins_loaded`: WordPress 6.7+ emits
// _doing_it_wrong('_load_textdomain_just_in_time') for translations loaded
// before init.
add_action('init', ['MxChat_Plus_Plugin', 'load_textdomain']);

// Priority 20: MxChat builds its globals on `plugins_loaded` at the default
// priority, so the host gate below is only meaningful after it has run.
add_action('plugins_loaded', ['MxChat_Plus_Plugin', 'boot'], 20);

class MxChat_Plus_Plugin {

    public static function load_textdomain(): void {
        load_plugin_textdomain(
            'mxchat-plus',
            false,
            dirname(MXCHAT_PLUS_BASENAME) . '/languages'
        );
    }

    public static function boot(): void {
        if (!MxChat_Plus_Host::is_active()) {
            add_action('admin_notices', ['MxChat_Plus_Host', 'render_missing_notice']);
            return;
        }

        $modules = MxChat_Plus_Modules::all();

        if (!empty($modules[MxChat_Plus_Modules::DUCKDB])) {
            self::boot_duckdb();
        }
        if (!empty($modules[MxChat_Plus_Modules::PROMPTCACHE])) {
            self::boot_promptcache();
        }
        if (!empty($modules[MxChat_Plus_Modules::TRANSCRIPTS]) && is_admin()) {
            MxChat_Plus_Transcripts_Export::instance()->register_hooks();
        }
        if (!empty($modules[MxChat_Plus_Modules::TRACKING])) {
            // The only module with a public-facing half: the tracker itself
            // hooks wp_enqueue_scripts, so it must be registered outside the
            // is_admin() gate below.
            MxChat_Plus_Tracking::instance()->register_hooks();
            if (is_admin()) {
                MxChat_Plus_Tracking_Admin::register_hooks();
            }
        }

        if (is_admin()) {
            MxChat_Plus_Admin::instance()->register_hooks();
        }

        if (defined('WP_CLI') && WP_CLI) {
            self::boot_cli();
        }
    }

    private static function boot_duckdb(): void {
        // Search adapter: the Option A filter (`mxchat_pre_vector_query`, which
        // mxchat-basic does not ship as of 3.2.21 — inert unless the host is
        // patched) plus the Pinecone config filter used by Option B.
        MxChat_Plus_DuckDB_Search_Adapter::instance()->register_hooks();

        // Option B, the nominal path: a REST endpoint emulating the Pinecone
        // wire protocol, so the host talks to us believing it talks to Pinecone.
        MxChat_Plus_DuckDB_Pinecone_Proxy::instance()->register_routes();

        MxChat_Plus_DuckDB_Health::instance()->register_routes();
        MxChat_Plus_DuckDB_Sync::instance()->register_hooks();
        MxChat_Plus_DuckDB_Async_Reprocess::instance()->register_hooks();
        MxChat_Plus_DuckDB_Compactor::instance()->register_hooks();

        // Mirror workers are registered unconditionally and short-circuit when
        // the mirror is off, so enabling it later needs no plugin reload.
        MxChat_Plus_DuckDB_Mirror_Bootstrap::instance()->register_hooks();
        MxChat_Plus_DuckDB_Mirror_Drain::instance()->register_hooks();
        MxChat_Plus_DuckDB_Mirror_Drift_Check::instance()->register_hooks();

        // Listening on update_option_* (which fires after the save) means we
        // observe the post-sanitiser state: a toggle the sanitiser rejected
        // cannot accidentally start a bootstrap.
        add_action('update_option_' . MXCHAT_PLUS_DUCKDB_OPTION_KEY, static function ($old, $new): void {
            $was = is_array($old) && !empty($old['motherduck_mirror_enabled']);
            $now = is_array($new) && !empty($new['motherduck_mirror_enabled']);
            if (!$was && $now) {
                MxChat_Plus_DuckDB_Mirror_Bootstrap::start();
            }
        }, 10, 2);

        if (is_admin()) {
            MxChat_Plus_DuckDB_Admin::instance()->register_hooks();
        }
    }

    private static function boot_promptcache(): void {
        require_once MXCHAT_PLUS_DIR . 'includes/promptcache/promptcache-constants.php';
        MxChat_Plus_PromptCache::register_hooks();
        if (is_admin()) {
            MxChat_Plus_PromptCache_Admin::register_hooks();
        }
    }

    private static function boot_cli(): void {
        $modules = MxChat_Plus_Modules::all();

        if (!empty($modules[MxChat_Plus_Modules::DUCKDB])) {
            // Self-registers `wp mxchat-plus duckdb`.
            require_once MXCHAT_PLUS_DIR . 'includes/duckdb/class-duckdb-cli.php';
        }
        if (!empty($modules[MxChat_Plus_Modules::PROMPTCACHE])) {
            require_once MXCHAT_PLUS_DIR . 'includes/promptcache/class-promptcache-cli.php';
            if (class_exists('MxChat_Plus_PromptCache_CLI')) {
                \WP_CLI::add_command('mxchat-plus promptcache', 'MxChat_Plus_PromptCache_CLI');
            }
        }
    }

    public static function activate(): void {
        MxChat_Plus_Modules::install_defaults();

        // Runs whether or not the tracking module is enabled: a site coming
        // from the standalone MxChat Link Tracking plugin should find its
        // settings intact the day it switches the module on, not reset to
        // defaults. The call is idempotent and a no-op without a legacy option.
        MxChat_Plus_Tracking_Options::migrate_legacy();

        if (MxChat_Plus_Modules::is_enabled(MxChat_Plus_Modules::DUCKDB)) {
            MxChat_Plus_DuckDB_Options::install_defaults();

            // Provision the proxy token at activation rather than lazily when
            // the settings screen is first opened: the lazy path left a window
            // in which host-driven requests hit the proxy before a token
            // existed.
            MxChat_Plus_DuckDB_Pinecone_Proxy::get_or_create_token();

            $opts = MxChat_Plus_DuckDB_Options::get();
            if (!empty($opts['enabled'])) {
                try {
                    (new MxChat_Plus_DuckDB_Vector_Store())->ensure_schema();
                } catch (\Throwable $e) {
                    // Activation must never fail on a backend that is merely
                    // unreachable; the settings screen surfaces the error.
                }
            }
        }
    }

    public static function deactivate(): void {
        // Single source of truth for scheduled work: Options::scheduled_hooks()
        // derives the list from the worker classes' own constants, so a new
        // cron cannot be added without being cancelled here.
        if (class_exists('MxChat_Plus_DuckDB_Options')) {
            MxChat_Plus_DuckDB_Options::unschedule_all();
        }
    }
}

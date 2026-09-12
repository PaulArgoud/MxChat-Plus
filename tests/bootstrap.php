<?php
/**
 * PHPUnit bootstrap — orchestrates the shim files + production class
 * requires so the unit suite can run without booting an actual WordPress.
 *
 * Shims live under tests/shims/, grouped by responsibility:
 *
 *   wp-functions.php    — i18n, options, transients, sanitisation, plugin paths
 *   wp-classes.php      — WP_Post, WP_Query, WP_REST_Request, WP_Error
 *   ajax.php            — wp_send_json_*, check_ajax_referer, nonces, add_settings_error
 *   wpdb.php            — MxChat_Test_WPDB ($wpdb pattern-match mock)
 *   wp-cli.php          — WP_CLI + namespaced WP_CLI\Utils helpers
 *   action-scheduler.php — as_* functions backed by a fake queue
 *   mxchat.php          — MxChat_Utils + MxChat_Plus_DuckDB_Cache stubs
 *
 * Test helpers (Connection_Factory injection, RecordingConnection,
 * memoisation resets) live under tests/helpers/test-helpers.php.
 */

if (!defined('ABSPATH'))         define('ABSPATH', __DIR__ . '/');
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);

// ───── Plugin constants the production code expects ─────────────────────
if (!defined('MXCHAT_PLUS_VERSION')) define('MXCHAT_PLUS_VERSION',    'test');
if (!defined('MXCHAT_PLUS_DIR')) define('MXCHAT_PLUS_DIR',        dirname(__DIR__) . '/');
if (!defined('MXCHAT_PLUS_FILE')) define('MXCHAT_PLUS_FILE',       MXCHAT_PLUS_DIR . 'mxchat-plus.php');
if (!defined('MXCHAT_PLUS_URL')) define('MXCHAT_PLUS_URL',        'http://example.test/');
if (!defined('MXCHAT_PLUS_DUCKDB_OPTION_KEY')) define('MXCHAT_PLUS_DUCKDB_OPTION_KEY', 'mxchat_plus_duckdb_options');
if (!defined('MXCHAT_PLUS_TRACKING_OPTION_KEY')) define('MXCHAT_PLUS_TRACKING_OPTION_KEY', 'mxchat_plus_tracking_options');
// ───── WordPress / Action Scheduler / WP-CLI / MxChat shims ─────────────
require_once __DIR__ . '/shims/wp-functions.php';
require_once __DIR__ . '/shims/wp-classes.php';
require_once __DIR__ . '/shims/ajax.php';
require_once __DIR__ . '/shims/wpdb.php';
require_once __DIR__ . '/shims/wp-cli.php';
require_once __DIR__ . '/shims/action-scheduler.php';
require_once __DIR__ . '/shims/mxchat.php';

// ───── Production classes ───────────────────────────────────────────────
// The plugin's own classmap autoloader, registered AFTER the shims above so
// that a shim's `class_exists()` guard wins: several tests rely on stubbed
// doubles (MxChat_Plus_DuckDB_Cache, MxChat_Utils) rather than the real class.
// mxchat-plus.php itself is never loaded here — it calls register_*_hook and
// would need a full WordPress.
require_once MXCHAT_PLUS_DIR . 'includes/core/class-mxchat-plus-autoloader.php';
MxChat_Plus_Autoloader::register();

// The CLI classes self-guard with an early `return` when WP_CLI is undefined,
// so they cannot be autoloaded — the shim defines WP_CLI, and they are pulled
// in explicitly.
require_once MXCHAT_PLUS_DIR . 'includes/duckdb/class-duckdb-cli.php';

// Prompt-cache constants are plain defines, not classes.
require_once MXCHAT_PLUS_DIR . 'includes/promptcache/promptcache-constants.php';

require_once __DIR__ . '/helpers/test-helpers.php';

<?php
/**
 * Single uninstall entrypoint.
 *
 * WordPress runs exactly one uninstall.php per plugin folder, so the two
 * merged plugins' scripts became module files that only DECLARE their cleanup
 * routine. This file owns the flags and the (single) multisite walk.
 *
 * Preserved by default: the embedded .duckdb data file, which can represent
 * hours of embedding work. Set MXCHAT_PLUS_DELETE_DATA_ON_UNINSTALL to true in
 * wp-config.php — or the matching option — to remove it too. MotherDuck data is
 * never touched: this script makes no network call.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/includes/duckdb/uninstall-duckdb.php';
require_once __DIR__ . '/includes/promptcache/uninstall-promptcache.php';

/**
 * Clean one site. Both modules are purged regardless of whether they were
 * enabled: a module switched off still leaves its options behind, and
 * uninstall means uninstall.
 */
function mxchat_plus_uninstall_cleanup_site(bool $delete_data): void {
    if (function_exists('mxchat_plus_duckdb_uninstall_cleanup_site')) {
        mxchat_plus_duckdb_uninstall_cleanup_site($delete_data);
    }
    if (function_exists('mxchat_plus_promptcache_uninstall_cleanup_site')) {
        mxchat_plus_promptcache_uninstall_cleanup_site();
    }
    delete_option('mxchat_plus_modules');
}

// Read the opt-in flag before anything is deleted — it lives in an option the
// cleanup itself removes.
$mxchat_plus_delete_data = function_exists('mxchat_plus_duckdb_uninstall_delete_data_flag')
    ? mxchat_plus_duckdb_uninstall_delete_data_flag()
    : false;

if (is_multisite()) {
    // number => 0 is required: get_sites() defaults to 100 sites, which would
    // silently leave residue on any larger network.
    $mxchat_plus_site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
    if (is_array($mxchat_plus_site_ids)) {
        foreach ($mxchat_plus_site_ids as $mxchat_plus_blog_id) {
            switch_to_blog((int) $mxchat_plus_blog_id);
            mxchat_plus_uninstall_cleanup_site($mxchat_plus_delete_data);
            restore_current_blog();
        }
    }
} else {
    mxchat_plus_uninstall_cleanup_site($mxchat_plus_delete_data);
}

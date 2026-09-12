<?php
/**
 * Tracking module — uninstall cleanup routine.
 *
 * Removes what the module persists for THE CURRENT SITE:
 *   - option mxchat_plus_tracking_options  (the module's settings)
 *   - option mxchat_tracking_options       (the same settings under the name
 *     the standalone "MxChat Link Tracking" plugin used, still present when
 *     the module was never enabled long enough to migrate it)
 *
 * The clicks themselves are NOT touched. They live in
 * {$wpdb->prefix}mxchat_url_clicks, a table MxChat creates and writes on its
 * own; it predates this module and outlives it.
 *
 * The plugin is not loaded during uninstall, so the option keys appear here as
 * literal strings, never as the module's constants.
 *
 * This file only DECLARES the routine. The entrypoint (multisite walk,
 * switch_to_blog/restore_current_blog) lives in uninstall.php at the root,
 * because WordPress reads a single uninstall.php per plugin folder.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Per-site cleanup. Called once on single-site, once per blog on multisite.
 */
function mxchat_plus_tracking_uninstall_cleanup_site(): void {
    delete_option('mxchat_plus_tracking_options');
    // The pre-merge name. Nothing will migrate it once the plugin is gone, so
    // leaving it behind would only be orphaned rows.
    delete_option('mxchat_tracking_options');
}

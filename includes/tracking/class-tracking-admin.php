<?php
/**
 * Tracking module — settings tab and the clicked-links report.
 *
 * The standalone plugin this module replaces had no admin surface at all: its
 * destinations were hardcoded and its only feedback channel was the DevTools
 * console. Everything here is new.
 *
 * The class stays thin on purpose: the form is hand-written in the view (as
 * everywhere else in this plugin — there is no add_settings_section/field call
 * in the tree), and every query behind the report lives in
 * MxChat_Plus_Tracking_Report.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_Tracking_Admin {

    /**
     * Settings API group.
     *
     * It must stay distinct from the 'mxchat-plus' group the DuckDB tab
     * registers into. wp-admin/options.php walks every option registered under
     * the submitted group and writes null over the ones missing from $_POST —
     * so two tabs sharing a group erase each other's option on every save, with
     * no error anywhere.
     */
    const SETTINGS_GROUP = 'mxchat_plus_tracking';

    /**
     * CSV export: AJAX action and nonce action are the same string, as in the
     * transcripts module. The nonce travels in a field named `security`, which
     * is what check_admin_referer() is given on the other side.
     */
    const EXPORT_ACTION = 'mxchat_plus_tracking_export';

    /** How many links the report table lists. */
    const REPORT_LIMIT = 50;

    public static function register_hooks(): void {
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('wp_ajax_' . self::EXPORT_ACTION, [MxChat_Plus_Tracking_Report::class, 'handle_export']);
    }

    public static function register_settings(): void {
        register_setting(self::SETTINGS_GROUP, MXCHAT_PLUS_TRACKING_OPTION_KEY, [
            'sanitize_callback' => [MxChat_Plus_Tracking_Options::class, 'sanitize'],
            'default'           => MxChat_Plus_Tracking_Options::defaults(),
        ]);
    }

    /**
     * Renders the tab. MxChat_Plus_Admin has already opened <div class="wrap">,
     * printed the <h1> and the nav tabs, and called settings_errors(), so the
     * view starts at the <h2> level and prints no notices of its own.
     */
    public static function render_tab(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $opts = MxChat_Plus_Tracking_Options::get();

        // MxChat creates wp_mxchat_url_clicks in its activation routine only,
        // so it is absent on any install activated before that routine existed
        // — and on any install where the plugin was updated in place rather
        // than reactivated. Every reader inside the host guards with a SHOW
        // TABLES LIKE for that reason; the report does the same and the view
        // explains the absence instead of surfacing a database error.
        $table_exists = MxChat_Plus_Tracking_Report::table_exists();
        $rows         = $table_exists ? MxChat_Plus_Tracking_Report::top_links(self::REPORT_LIMIT) : [];
        $totals       = $table_exists
            ? MxChat_Plus_Tracking_Report::totals()
            : ['clicks' => 0, 'links' => 0, 'sessions' => 0];

        $export_url   = admin_url('admin-ajax.php');
        $export_nonce = wp_create_nonce(self::EXPORT_ACTION);

        $view = MXCHAT_PLUS_DIR . 'admin/views/tracking/settings.php';
        if (file_exists($view)) {
            include $view;
        }
    }
}

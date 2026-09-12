<?php
/**
 * Tracking module — the frontend half.
 *
 * MxChat already logs link clicks server-side into `{prefix}mxchat_url_clicks`,
 * but it sends nothing to the site's analytics: there is no Matomo or GA4 event
 * for a click inside a bot answer, and suggested questions are not tracked at
 * all. This module ships a small script that watches the widget and forwards
 * those two interactions to whichever tracker the site already runs.
 *
 * All of the work happens in `assets/js/tracking.js` — the host binds its own
 * click handler directly on each `<a>` and calls `stopPropagation()`, so the
 * only place left to observe a click is a document-level listener in the
 * capture phase. That constraint is a browser one; this class does nothing but
 * decide whether to ship the script and hand it its settings.
 *
 * This is the plugin's only public-facing surface: every other module is
 * admin-gated or runs as a filter. It therefore stays deliberately small, and
 * bails out of anything that is not a real page view.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_Tracking {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function register_hooks(): void {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Registers the tracker on the front end, with the module's settings
     * attached.
     *
     * The widget's presence on the page is deliberately not checked: MxChat can
     * inject it after this hook has run (its `delay` and `interaction` loading
     * strategies build the widget from `wp_footer`), so no reliable answer
     * exists at enqueue time. The script is inert until a click lands inside a
     * chat bubble.
     */
    public function enqueue_assets(): void {
        // Feeds and robots.txt are rendered through the frontend stack, so this
        // hook can fire for them. Neither is a document: a <script> tag makes a
        // feed invalid XML, and robots.txt is served as plain text.
        // is_robots() is called behind function_exists() because it is a
        // query-dependent conditional tag, and an undefined-function fatal here
        // would take the whole response down for the sake of an optional
        // tracker.
        if (is_feed() || (function_exists('is_robots') && is_robots())) {
            return;
        }

        $options = MxChat_Plus_Tracking_Options::get();

        $track_matomo = !empty($options['track_matomo']);
        $track_ga4    = !empty($options['track_ga4']);

        // With both destinations off the script has nothing to send, and
        // shipping it would be pure weight on every page of the site.
        if (!$track_matomo && !$track_ga4) {
            return;
        }

        // The dependency array MUST stay empty. MxChat only registers the
        // `mxchat-chat-js` handle under its `default` and `defer` loading
        // strategies; under `delay` or `interaction` it never registers it and
        // loads the script itself from the footer instead
        // (mxchat-basic/includes/class-mxchat-integrator.php, around line 13635).
        // WordPress drops any script whose dependency is not registered, with
        // no warning anywhere — so declaring that handle here would silently
        // disable tracking on exactly the installs that tuned their loading
        // strategy. The script does not need the host's code: it listens on the
        // document and reads the globals MxChat exports for add-ons.
        wp_enqueue_script(
            'mxchat-plus-tracking',
            MXCHAT_PLUS_URL . 'assets/js/tracking.js',
            [],
            MXCHAT_PLUS_VERSION,
            true
        );

        // wp_localize_script() runs every scalar through (string), so booleans
        // reach the browser as "1" and "" — and an integer 0 reaches it as the
        // string "0", which is truthy in JavaScript. The values are sent as-is
        // and the script does the coercion: truthy checks for the flags (never
        // `=== true`), parseInt for the dimension id.
        wp_localize_script('mxchat-plus-tracking', 'mxchatPlusTracking', [
            'trackMatomo'       => $track_matomo,
            'trackGa4'          => $track_ga4,
            'sendSessionId'     => !empty($options['send_session_id']),
            'matomoDimensionId' => (int) ($options['matomo_dimension_id'] ?? 0),
            'debug'             => !empty($options['debug']),
        ]);
    }
}

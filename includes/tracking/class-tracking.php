<?php
/**
 * Tracking module — the frontend half.
 *
 * MxChat already logs link clicks server-side into `{prefix}mxchat_url_clicks`,
 * but it sends nothing to the site's analytics: there is no Matomo or GA4 event
 * for a click inside a bot answer, and suggested questions are not tracked at
 * all. This module ships a small script that watches the widget and forwards
 * those two interactions — and, opt-in, the chat window being opened — to
 * whichever tracker the site already runs.
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

    const SCRIPT_HANDLE = 'mxchat-plus-tracking';

    /**
     * Host literals behind MxChat's "Only load chatbot files where the chatbot
     * appears" setting (Settings → Optimization → Script Loading). All three
     * belong to MxChat — never rename them.
     *
     *   - the class whose two static methods the host's own enqueue gate
     *     calls (MxChat_Integrator::mxchat_enqueue_scripts_styles());
     *   - the standalone option those methods read, 'on' / 'off';
     *   - the host's stylesheet handle, which its enqueue routine registers
     *     under EVERY loading strategy, and which its own render-time safety
     *     net tests (MxChat_Public::render_chatbot_shortcode()). The script
     *     handle `mxchat-chat-js` would not do: under the `delay` and
     *     `interaction` strategies it is never registered at all.
     */
    const HOST_PUBLIC_CLASS          = 'MxChat_Public';
    const HOST_SMART_LOADING_OPTION  = 'mxchat_smart_asset_loading';
    const HOST_CSS_HANDLE            = 'mxchat-chat-css';

    /**
     * Footer slots for a page whose widget may render late. 19 is the last
     * one before wp_print_footer_scripts (wp_footer at 20), so an enqueue
     * there is still printed by WordPress; 100 comes after the host's own
     * delayed loader (99), for a widget that only rendered after the scripts
     * went out — we then print ours ourselves.
     */
    const FOOTER_ENQUEUE_PRIORITY = 19;
    const FOOTER_PRINT_PRIORITY   = 100;

    private static ?self $instance = null;

    /** The script went into the queue on this request; never twice. */
    private bool $enqueued = false;

    /** The decision was handed to the footer (smart loading skipped MxChat's assets). */
    private bool $deferred = false;

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
     * The script is inert until something happens inside the widget, so the
     * only question is whether shipping it is worth its weight. By default the
     * answer is yes on every page: MxChat prints the widget itself from
     * wp_footer, after this hook, so its presence is not known yet.
     *
     * The exception is a site that turned on MxChat's smart asset loading,
     * which exists precisely so that pages without the widget ship no chatbot
     * code. There the host has already decided, and we follow it: when it
     * says the widget will render, the script is enqueued now; when it
     * skipped its own assets, the decision waits for the footer — see
     * enqueue_for_late_widget().
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

        // With both destinations off the script has nothing to send, and
        // shipping it would be pure weight on every page of the site.
        if (self::script_settings() === null) {
            return;
        }

        if ($this->host_skipped_its_assets()) {
            $this->deferred = true;
            add_action('wp_footer', [$this, 'enqueue_for_late_widget'], self::FOOTER_ENQUEUE_PRIORITY);
            add_action('wp_footer', [$this, 'print_for_late_widget'], self::FOOTER_PRINT_PRIORITY);
            return;
        }

        $this->enqueue();
    }

    /**
     * wp_footer, just before the footer scripts are printed: the widget did
     * render after all. MxChat's safety net force-enqueues its stylesheet
     * whenever the chatbot shortcode actually renders — content, a template,
     * a widget area, or the floating bubble it appends on wp_footer at 10 —
     * so that stylesheet being in the queue is the host's own "the widget is
     * on this page" signal.
     */
    public function enqueue_for_late_widget(): void {
        if (!$this->deferred || $this->enqueued || !self::host_css_enqueued()) {
            return;
        }
        $this->enqueue();
    }

    /**
     * wp_footer, after the footer scripts went out: the second chance for a
     * widget rendered by a later wp_footer callback (footer widget areas,
     * late builder regions — the case MxChat handles by printing its delayed
     * loader inline). WordPress will not print anything more by itself at
     * this point, so the script is printed here, localized data included.
     */
    public function print_for_late_widget(): void {
        if (!$this->deferred || $this->enqueued || !self::host_css_enqueued()) {
            return;
        }
        if ($this->enqueue()) {
            // do_items() directly, NOT wp_print_scripts(). That wrapper fires
            // the global `wp_print_scripts` action on every call, whatever the
            // handles, before doing exactly this; called here it would re-run
            // every callback on that action at the end of <body> — and a
            // plugin still echoing an inline snippet from it (an old analytics
            // tag) would print it twice, doubling its init and its pageview.
            // do_items() prints our handle alone, localized data included.
            wp_scripts()->do_items([self::SCRIPT_HANDLE]);
        }
    }

    /**
     * Enqueue and localize, once per request.
     *
     * @return bool Whether the script was enqueued by this call.
     */
    private function enqueue(): bool {
        if ($this->enqueued) {
            return false;
        }
        $settings = self::script_settings();
        if ($settings === null) {
            return false;
        }
        $this->enqueued = true;

        // The dependency array MUST stay empty. MxChat only registers the
        // `mxchat-chat-js` handle under its `default` and `defer` loading
        // strategies; under `delay` or `interaction` it never registers it and
        // loads the script itself from the footer instead
        // (MxChat_Integrator::mxchat_enqueue_scripts_styles() hooks
        // mxchat_output_delayed_script_loader() on wp_footer, MxChat 3.2.25).
        // WordPress drops any script whose dependency is not registered, with
        // no warning anywhere — so declaring that handle here would silently
        // disable tracking on exactly the installs that tuned their loading
        // strategy. The script does not need the host's code: it listens on the
        // document and reads the globals MxChat exports for add-ons.
        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            MXCHAT_PLUS_URL . 'assets/js/tracking.js',
            [],
            MXCHAT_PLUS_VERSION,
            true
        );

        wp_localize_script(self::SCRIPT_HANDLE, 'mxchatPlusTracking', $settings);

        return true;
    }

    /**
     * The object the script is localized with, or null when there is nothing
     * to send (both destinations off).
     *
     * wp_localize_script() runs every scalar through (string), so booleans
     * reach the browser as "1" and "" — and an integer 0 reaches it as the
     * string "0", which is truthy in JavaScript. The values are sent as-is
     * and the script does the coercion: truthy checks for the flags (never
     * `=== true`), parseInt for the dimension id. A key missing from this
     * array reads as its JS-side default, which for trackChatOpen is off.
     *
     * @return array{trackMatomo:bool,trackGa4:bool,sendSessionId:bool,matomoDimensionId:int,trackChatOpen:bool,debug:bool}|null
     */
    public static function script_settings(): ?array {
        $options = MxChat_Plus_Tracking_Options::get();

        $track_matomo = $options['track_matomo'];
        $track_ga4    = $options['track_ga4'];

        if (!$track_matomo && !$track_ga4) {
            return null;
        }

        return [
            'trackMatomo'       => $track_matomo,
            'trackGa4'          => $track_ga4,
            'sendSessionId'     => $options['send_session_id'],
            'matomoDimensionId' => $options['matomo_dimension_id'],
            'trackChatOpen'     => $options['track_chat_open'],
            'debug'             => $options['debug'],
        ];
    }

    /**
     * Did MxChat's smart asset loading leave its own files off this page?
     *
     * False — enqueue now, exactly as before — when the setting is off, which
     * is the host's default and keeps those sites byte-identical. When it is
     * on, the host's own answer is asked first: MxChat_Public::should_load_assets()
     * is the decision its enqueue gate takes (MxChat 3.2.25), cached for the
     * request, and documented for add-ons. Only when that says no — or when
     * the method cannot be called, which no host shipping the option should
     * do — does the decision move to the footer.
     *
     * Limiting the deferral to smart-loading sites also limits what a future
     * rename of the host's stylesheet handle could break to those sites.
     */
    private function host_skipped_its_assets(): bool {
        $class = $this->host_public_class();

        $enabled = self::call_host_static($class, 'is_smart_asset_loading_enabled');
        if ($enabled === null) {
            // Older host, or the class is not loaded: read the option the
            // method would have read (MxChat_Public::is_smart_asset_loading_enabled(),
            // MxChat 3.2.25 — default 'off'). Absent on hosts that predate it.
            $enabled = get_option(self::HOST_SMART_LOADING_OPTION, 'off') === 'on';
        }
        if (!$enabled) {
            return false;
        }

        $will_render = self::call_host_static($class, 'should_load_assets');

        return $will_render !== true;
    }

    /**
     * The host class holding the smart-loading decision. A method rather than
     * a bare constant so a test double can point it at a stand-in; production
     * code never overrides it.
     */
    protected function host_public_class(): string {
        return self::HOST_PUBLIC_CLASS;
    }

    /**
     * The host's stylesheet is in the queue (printed or not: WordPress keeps
     * a printed handle in the queue, which is what the host's own safety net
     * relies on too).
     */
    private static function host_css_enqueued(): bool {
        return wp_style_is(self::HOST_CSS_HANDLE, 'enqueued');
    }

    /**
     * Call a host static method that takes no argument, as a bool, or null
     * when it does not exist. The class name stays a variable so the static
     * analyser, which does not know the host's classes, has nothing to
     * resolve; and a host that throws costs the tracker, never the page.
     */
    private static function call_host_static(string $class, string $method): ?bool {
        $callable = [$class, $method];
        if (!class_exists($class) || !is_callable($callable)) {
            return null;
        }
        try {
            return (bool) call_user_func($callable);
        } catch (\Throwable $e) {
            return null;
        }
    }
}

<?php
/**
 * The front-end half's only PHP decision: whether, and when, tracking.js goes
 * onto a page, and what it is localized with.
 *
 * Two failure modes here are silent. Shipping the script on every page undoes
 * MxChat's "Only load chatbot files where the chatbot appears" for the site
 * owner who switched it on to pass a "reduce unused JS" audit. Not shipping it
 * on a page whose widget renders late — a footer widget area, a builder region
 * — loses every event on that page with no error anywhere.
 *
 * The host's MxChat_Public is never declared here: nothing else in the suite
 * references it, and a global double would leak into every later test. The
 * class under test resolves the host class through one overridable method, and
 * the doubles below carry our own prefix.
 */

use PHPUnit\Framework\TestCase;

// The second argument of class_exists() MUST stay false: see the note at the
// top of TrackingReportTest.php (tests/ is classmapped in autoload-dev).
if (!class_exists('MxChat_Plus_Test_Smart_Public', false)) {

    /** Stand-in for MxChat_Public on a host with smart asset loading. */
    class MxChat_Plus_Test_Smart_Public {

        public static bool $will_render = false;

        public static int $calls = 0;

        public static function is_smart_asset_loading_enabled(): bool {
            return get_option('mxchat_smart_asset_loading', 'off') === 'on';
        }

        public static function should_load_assets(): bool {
            self::$calls++;
            return self::$will_render;
        }
    }

    /** A host that throws from its decision: the tracker must not take the page down. */
    class MxChat_Plus_Test_Throwing_Public {

        public static function is_smart_asset_loading_enabled(): bool {
            return true;
        }

        public static function should_load_assets(): bool {
            throw new RuntimeException('host internals changed');
        }
    }

    /** MxChat_Plus_Tracking pointed at whichever host double a test names. */
    class MxChat_Plus_Test_Tracking extends MxChat_Plus_Tracking {

        public string $host_class = 'MxChat_Plus_Test_No_Such_Class';

        protected function host_public_class(): string {
            return $this->host_class;
        }
    }
}

final class TrackingEnqueueTest extends TestCase {

    private const HANDLE = 'mxchat-plus-tracking';

    protected function setUp(): void {
        $GLOBALS['__test_options'] = [];
        $GLOBALS['__test_scripts'] = [];
        $GLOBALS['__test_styles']  = [];
        $GLOBALS['__test_actions'] = [];
        unset($GLOBALS['__test_is_feed']);

        MxChat_Plus_Test_Smart_Public::$will_render = false;
        MxChat_Plus_Test_Smart_Public::$calls       = 0;
    }

    protected function tearDown(): void {
        unset($GLOBALS['__test_scripts'], $GLOBALS['__test_styles'], $GLOBALS['__test_is_feed'], $GLOBALS['__test_actions']);
    }

    private function tracker(string $host_class = 'MxChat_Plus_Test_No_Such_Class'): MxChat_Plus_Test_Tracking {
        $t = new MxChat_Plus_Test_Tracking();
        $t->host_class = $host_class;
        return $t;
    }

    private function smartLoading(bool $on): void {
        // A host literal: MxChat's own standalone option, 'on' / 'off'.
        update_option('mxchat_smart_asset_loading', $on ? 'on' : 'off');
    }

    private function hostCssEnqueued(): void {
        wp_enqueue_style('mxchat-chat-css');
    }

    private function isEnqueued(): bool {
        return isset($GLOBALS['__test_scripts']['enqueued'][self::HANDLE]);
    }

    /** @return array<string,mixed> */
    private function localized(): array {
        $data = $GLOBALS['__test_scripts']['localized'][self::HANDLE]['mxchatPlusTracking'] ?? [];
        return is_array($data) ? $data : [];
    }

    /** @return list<string> */
    private function printed(): array {
        $printed = $GLOBALS['__test_scripts']['printed'] ?? [];
        return is_array($printed) ? array_values($printed) : [];
    }

    /** How many times do_action($hook) ran since setUp. */
    private function actionCount(string $hook): int {
        $count = 0;
        foreach ((array) ($GLOBALS['__test_actions'] ?? []) as $action) {
            if (is_array($action) && ($action['hook'] ?? null) === $hook) {
                $count++;
            }
        }
        return $count;
    }

    // ─── Smart asset loading off: unchanged behaviour ─────────────────────

    public function test_without_smart_loading_the_script_is_enqueued_at_once(): void {
        $t = $this->tracker('MxChat_Plus_Test_Smart_Public');

        $t->enqueue_assets();

        $this->assertTrue($this->isEnqueued());
        // Off means off: the host's decision is not even asked for.
        $this->assertSame(0, MxChat_Plus_Test_Smart_Public::$calls);
    }

    public function test_the_dependency_array_stays_empty_and_the_script_goes_in_the_footer(): void {
        // MxChat never registers mxchat-chat-js under its delay and
        // interaction strategies, and WordPress drops a script whose
        // dependency is missing — silently.
        $this->tracker()->enqueue_assets();

        $script = $GLOBALS['__test_scripts']['enqueued'][self::HANDLE];
        $this->assertSame([], $script['deps']);
        $this->assertTrue($script['args']);
        $this->assertStringEndsWith('assets/js/tracking.js', $script['src']);
    }

    public function test_an_old_host_without_the_option_is_treated_as_smart_loading_off(): void {
        // No MxChat_Public at all, no option: exactly the pre-gate behaviour.
        $this->tracker()->enqueue_assets();

        $this->assertTrue($this->isEnqueued());
    }

    public function test_nothing_ships_when_both_destinations_are_off(): void {
        update_option(MXCHAT_PLUS_TRACKING_OPTION_KEY, MxChat_Plus_Tracking_Options::sanitize([]));

        $t = $this->tracker();
        $t->enqueue_assets();

        $this->assertFalse($this->isEnqueued());
        $this->assertNull(MxChat_Plus_Tracking::script_settings());
    }

    public function test_nothing_ships_on_a_feed(): void {
        $GLOBALS['__test_is_feed'] = true;

        $this->tracker()->enqueue_assets();

        $this->assertFalse($this->isEnqueued());
    }

    // ─── Smart asset loading on ───────────────────────────────────────────

    public function test_with_smart_loading_the_hosts_yes_enqueues_at_once(): void {
        $this->smartLoading(true);
        MxChat_Plus_Test_Smart_Public::$will_render = true;

        $this->tracker('MxChat_Plus_Test_Smart_Public')->enqueue_assets();

        $this->assertTrue($this->isEnqueued());
        $this->assertSame(1, MxChat_Plus_Test_Smart_Public::$calls);
    }

    public function test_with_smart_loading_the_hosts_no_keeps_the_page_clean(): void {
        $this->smartLoading(true);

        $t = $this->tracker('MxChat_Plus_Test_Smart_Public');
        $t->enqueue_assets();
        $this->assertFalse($this->isEnqueued(), 'deferred, not enqueued');

        // The widget never rendered: the host's stylesheet never went out.
        $t->enqueue_for_late_widget();
        $t->print_for_late_widget();

        $this->assertFalse($this->isEnqueued());
        $this->assertSame([], $this->printed());
    }

    public function test_a_widget_rendered_before_the_footer_scripts_is_caught_at_priority_19(): void {
        $this->smartLoading(true);

        $t = $this->tracker('MxChat_Plus_Test_Smart_Public');
        $t->enqueue_assets();

        // MxChat's render-time safety net force-enqueued its assets, e.g. for
        // a shortcode in a template has_shortcode() could not see.
        $this->hostCssEnqueued();
        $t->enqueue_for_late_widget();

        $this->assertTrue($this->isEnqueued());
        // WordPress prints it at wp_footer 20; nothing to print by hand.
        $this->assertSame([], $this->printed());
        $this->assertNotSame([], $this->localized());

        // The second chance must not enqueue or print it again.
        $t->print_for_late_widget();
        $this->assertSame([], $this->printed());
    }

    public function test_a_widget_rendered_after_the_footer_scripts_is_printed_at_priority_100(): void {
        $this->smartLoading(true);

        $t = $this->tracker('MxChat_Plus_Test_Smart_Public');
        $t->enqueue_assets();
        $t->enqueue_for_late_widget();
        $this->assertFalse($this->isEnqueued());

        // A later wp_footer callback rendered the widget (footer widget area).
        $this->hostCssEnqueued();
        $t->print_for_late_widget();

        $this->assertTrue($this->isEnqueued());
        $this->assertSame([self::HANDLE], $this->printed());
        $this->assertNotSame([], $this->localized(), 'printed with its settings');
    }

    public function test_the_late_print_does_not_re_run_the_global_wp_print_scripts_action(): void {
        // wp_print_scripts() fires the `wp_print_scripts` action on every call,
        // whatever the handle. Called at wp_footer 100 it would re-run every
        // callback on that action at the end of <body>, and a plugin echoing
        // an inline snippet from it would print that snippet twice. Ours goes
        // through wp_scripts()->do_items(), which prints our handle alone.
        $this->smartLoading(true);

        $t = $this->tracker('MxChat_Plus_Test_Smart_Public');
        $t->enqueue_assets();
        $this->hostCssEnqueued();
        $t->print_for_late_widget();

        $this->assertSame([self::HANDLE], $this->printed());
        $this->assertSame(0, $this->actionCount('wp_print_scripts'));

        // The line above can only fail if the shim models core: a
        // wp_print_scripts() call must record the action. Checked here so the
        // lock cannot go vacuous if the shim is ever simplified.
        wp_print_scripts('mxchat-plus-test-other');
        $this->assertSame(1, $this->actionCount('wp_print_scripts'));
        $this->assertSame([self::HANDLE, 'mxchat-plus-test-other'], $this->printed());
    }

    public function test_the_footer_callbacks_do_nothing_unless_the_decision_was_deferred(): void {
        // Smart loading off: already enqueued at wp_enqueue_scripts. A stray
        // footer call must not print it a second time.
        $t = $this->tracker();
        $t->enqueue_assets();
        $this->hostCssEnqueued();

        $t->enqueue_for_late_widget();
        $t->print_for_late_widget();

        $this->assertSame([], $this->printed());
    }

    public function test_smart_loading_on_a_host_without_its_decision_method_defers(): void {
        // The option says on, but should_load_assets() cannot be called. The
        // footer check is the safe side: it ships wherever the widget renders.
        $this->smartLoading(true);

        $t = $this->tracker();
        $t->enqueue_assets();
        $this->assertFalse($this->isEnqueued());

        $this->hostCssEnqueued();
        $t->enqueue_for_late_widget();
        $this->assertTrue($this->isEnqueued());
    }

    public function test_a_host_that_throws_defers_rather_than_failing_the_page(): void {
        $t = $this->tracker('MxChat_Plus_Test_Throwing_Public');

        $t->enqueue_assets();
        $this->assertFalse($this->isEnqueued());

        $this->hostCssEnqueued();
        $t->enqueue_for_late_widget();
        $this->assertTrue($this->isEnqueued());
    }

    // ─── The localized settings ───────────────────────────────────────────

    public function test_the_script_is_localized_with_every_key_it_reads(): void {
        $this->tracker()->enqueue_assets();

        $this->assertSame(
            ['trackMatomo', 'trackGa4', 'sendSessionId', 'matomoDimensionId', 'trackChatOpen', 'debug'],
            array_keys($this->localized())
        );
    }

    public function test_the_chat_opened_flag_follows_the_setting_and_defaults_off(): void {
        $this->tracker()->enqueue_assets();
        $this->assertFalse($this->localized()['trackChatOpen']);

        $GLOBALS['__test_scripts'] = [];
        update_option(MXCHAT_PLUS_TRACKING_OPTION_KEY, MxChat_Plus_Tracking_Options::sanitize([
            'track_matomo'    => '1',
            'track_chat_open' => '1',
        ]));
        $this->tracker()->enqueue_assets();

        $this->assertTrue($this->localized()['trackChatOpen']);
    }

    public function test_the_host_literals_are_the_hosts_own(): void {
        // Renaming any of these points the gate at something MxChat never
        // sets: the tracker would then never ship on a smart-loading site.
        $this->assertSame('MxChat_Public', MxChat_Plus_Tracking::HOST_PUBLIC_CLASS);
        $this->assertSame('mxchat_smart_asset_loading', MxChat_Plus_Tracking::HOST_SMART_LOADING_OPTION);
        $this->assertSame('mxchat-chat-css', MxChat_Plus_Tracking::HOST_CSS_HANDLE);
    }

    public function test_the_footer_slots_bracket_the_footer_scripts_and_the_hosts_loader(): void {
        // wp_print_footer_scripts runs on wp_footer at 20, MxChat's delayed
        // loader at 99.
        $this->assertLessThan(20, MxChat_Plus_Tracking::FOOTER_ENQUEUE_PRIORITY);
        $this->assertGreaterThan(99, MxChat_Plus_Tracking::FOOTER_PRINT_PRIORITY);
    }
}

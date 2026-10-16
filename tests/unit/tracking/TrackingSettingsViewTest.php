<?php
/**
 * The Click tracking tab, rendered with the exact arrays the report class
 * returns.
 *
 * The view reads the report's rows and totals by key, through `?? ''` and
 * `?? 0` fallbacks. A key it spells differently from the report is therefore
 * not an error but an empty cell or a zero — which is how the URL column and
 * the "on N links" figure both went blank: the view read `clicked_url` and
 * `links`, the report returns `url` and `urls`. The fixtures below use the
 * report's shapes, which TrackingReportTest locks key by key
 * (test_top_links_shapes_and_types_every_row,
 * test_totals_counts_clicks_sessions_and_distinct_urls): change the report and
 * those fail; change the view and these do.
 */

use PHPUnit\Framework\TestCase;

final class TrackingSettingsViewTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__test_options'] = [];
        $GLOBALS['__test_home_url'] = 'https://example.test';
    }

    protected function tearDown(): void {
        unset($GLOBALS['__test_home_url']);
    }

    /**
     * @param array<string,mixed> $opts
     * @param list<array{url:string,clicks:int,sessions:int,scope:'internal'|'external',last_click:string}> $rows
     * @param array{clicks:int,sessions:int,urls:int,internal:int,external:int} $totals
     */
    private function render(array $opts, array $rows, array $totals, bool $table_exists = true): string {
        $export_url   = 'https://example.test/wp-admin/admin-ajax.php';
        $export_nonce = 'nonce';

        ob_start();
        include MXCHAT_PLUS_DIR . 'admin/views/tracking/settings.php';
        return (string) ob_get_clean();
    }

    /** @return list<array{url:string,clicks:int,sessions:int,scope:'internal'|'external',last_click:string}> */
    private static function reportRows(): array {
        return [
            ['url' => 'https://example.test/pricing', 'clicks' => 9, 'sessions' => 4, 'scope' => 'internal', 'last_click' => '2026-02-03 10:11:12'],
            ['url' => 'https://other.test/docs',      'clicks' => 2, 'sessions' => 2, 'scope' => 'external', 'last_click' => '2026-02-01 08:00:00'],
        ];
    }

    /** @return array{clicks:int,sessions:int,urls:int,internal:int,external:int} */
    private static function reportTotals(): array {
        return ['clicks' => 11, 'sessions' => 5, 'urls' => 2, 'internal' => 9, 'external' => 2];
    }

    public function test_the_report_rows_show_their_url(): void {
        $html = $this->render(MxChat_Plus_Tracking_Options::defaults(), self::reportRows(), self::reportTotals());

        $this->assertStringContainsString('href="https://example.test/pricing"', $html);
        $this->assertStringContainsString('>https://other.test/docs</a>', $html);
    }

    public function test_the_totals_line_counts_distinct_links(): void {
        $html = $this->render(MxChat_Plus_Tracking_Options::defaults(), self::reportRows(), self::reportTotals());

        $this->assertStringContainsString('11 clicks recorded, on 2 links, across 5 sessions.', $html);
    }

    public function test_the_chat_opened_checkbox_posts_under_the_option_key(): void {
        $html = $this->render(MxChat_Plus_Tracking_Options::defaults(), [], self::reportTotals());

        $this->assertMatchesRegularExpression(
            '/name="mxchat_plus_tracking_options\[track_chat_open\]" value="1"\s*>/',
            $html,
            'unticked by default'
        );
        // The Matomo action is shown as the literal row key, untranslated.
        $this->assertStringContainsString('<code>Ouverture chat</code>', $html);
        $this->assertStringContainsString('<code>mxchat_chat_open</code>', $html);
    }

    public function test_the_chat_opened_checkbox_reflects_a_saved_yes(): void {
        $opts = MxChat_Plus_Tracking_Options::sanitize(['track_matomo' => '1', 'track_chat_open' => '1']);

        $html = $this->render($opts, [], self::reportTotals());

        $this->assertMatchesRegularExpression(
            '/name="mxchat_plus_tracking_options\[track_chat_open\]" value="1"\s*checked=/',
            $html
        );
    }

    public function test_a_missing_table_explains_how_mxchat_recreates_it(): void {
        $html = $this->render(MxChat_Plus_Tracking_Options::defaults(), [], self::reportTotals(), false);

        // Not "the next time MxChat is activated" alone: every MxChat update
        // re-runs its activation routine too.
        $this->assertStringContainsString('again after each MxChat update', $html);
        $this->assertStringNotContainsString('<table class="widefat', $html);
    }

    public function test_the_report_warns_that_counts_change_across_the_host_update(): void {
        $html = $this->render(MxChat_Plus_Tracking_Options::defaults(), self::reportRows(), self::reportTotals());

        $this->assertStringContainsString('not comparable across the MxChat 3.2.24 and 3.2.25 updates', $html);
    }
}

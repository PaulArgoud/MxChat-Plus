<?php
/**
 * Transcripts CSV export — input normalisation and CSV-injection hardening.
 *
 * These two are the module's whole attack surface: session ids arrive from the
 * browser, and message bodies are visitor-authored text that lands in a file
 * someone will open in Excel.
 */

use PHPUnit\Framework\TestCase;

final class TranscriptsExportTest extends TestCase {

    /** @return mixed */
    private static function neutralise(string $value) {
        $m = new ReflectionMethod(MxChat_Plus_Transcripts_Export::class, 'neutralise_formula');
        return $m->invoke(null, $value);
    }

    // ─── Session id normalisation ────────────────────────────────────────

    public function test_trims_and_keeps_plain_ids(): void {
        $this->assertSame(
            ['abc123', 'def456'],
            MxChat_Plus_Transcripts_Export::sanitize_session_ids(['abc123', '  def456  '])
        );
    }

    public function test_dedupes_while_preserving_first_seen_order(): void {
        $this->assertSame(
            ['b', 'a', 'c'],
            MxChat_Plus_Transcripts_Export::sanitize_session_ids(['b', 'a', 'b', 'c', 'a'])
        );
    }

    public function test_drops_empty_and_non_scalar_entries(): void {
        $this->assertSame(
            ['keep'],
            MxChat_Plus_Transcripts_Export::sanitize_session_ids(['', 'keep', [], null, '   '])
        );
    }

    public function test_wraps_a_bare_scalar_into_a_list(): void {
        $this->assertSame(['solo'], MxChat_Plus_Transcripts_Export::sanitize_session_ids('solo'));
    }

    public function test_empty_input_yields_empty_list(): void {
        $this->assertSame([], MxChat_Plus_Transcripts_Export::sanitize_session_ids([]));
        $this->assertSame([], MxChat_Plus_Transcripts_Export::sanitize_session_ids(null));
    }

    public function test_caps_the_export_at_max_sessions(): void {
        $many = [];
        for ($i = 0; $i < MxChat_Plus_Transcripts_Export::MAX_SESSIONS + 250; $i++) {
            $many[] = 'session-' . $i;
        }
        $out = MxChat_Plus_Transcripts_Export::sanitize_session_ids($many);

        $this->assertCount(MxChat_Plus_Transcripts_Export::MAX_SESSIONS, $out);
        // Truncation keeps the head of the list, so the cap is predictable.
        $this->assertSame('session-0', $out[0]);
    }

    /**
     * The placeholder list is generated from the COUNT of ids, never from their
     * content — that is what keeps the IN (...) clause injection-proof.
     */
    public function test_placeholder_count_matches_sanitized_id_count(): void {
        $ids = MxChat_Plus_Transcripts_Export::sanitize_session_ids(['a', 'b', 'b', 'c']);
        $placeholders = implode(',', array_fill(0, count($ids), '%s'));

        $this->assertSame('%s,%s,%s', $placeholders);
        $this->assertSame(count($ids), substr_count($placeholders, '%s'));
    }

    // ─── CSV injection ───────────────────────────────────────────────────

    /**
     * A message beginning with one of these is executed as a formula by Excel
     * and Google Sheets. Since messages are written by site visitors, this is a
     * real path from "someone typed in the chat widget" to "code ran on an
     * admin's machine".
     *
     * @dataProvider dangerousPrefixes
     */
    public function test_formula_prefixes_are_neutralised(string $payload): void {
        $out = self::neutralise($payload);

        $this->assertSame("\t" . $payload, $out, 'Expected a tab prefix to defuse the formula.');
        // The payload is no longer the first thing the spreadsheet parses, which
        // is what strips its formula meaning.
        $this->assertStringStartsWith("\t", $out);
        $this->assertSame($payload, substr($out, 1));
    }

    /** @return array<string,array{0:string}> */
    public static function dangerousPrefixes(): array {
        return [
            'equals'      => ['=1+1'],
            'plus'        => ['+1+1'],
            'minus'       => ['-1+1'],
            'at'          => ['@SUM(A1:A9)'],
            'tab'         => ["\tsneaky"],
            'CR'          => ["\rsneaky"],
            'cmd exec'    => ['=cmd|\' /C calc\'!A0'],
            'hyperlink'   => ['=HYPERLINK("http://evil.test","click")'],
        ];
    }

    public function test_ordinary_messages_are_left_untouched(): void {
        foreach (['Hello there', '  leading space', 'a=b', '1+1', 'coût déjà vu', ''] as $safe) {
            $this->assertSame($safe, self::neutralise($safe));
        }
    }

    public function test_null_message_becomes_empty_string(): void {
        $m = new ReflectionMethod(MxChat_Plus_Transcripts_Export::class, 'neutralise_formula');
        $this->assertSame('', $m->invoke(null, null));
    }

    // ─── Date range (used when nothing is selected) ──────────────────────

    public function test_valid_range_spans_from_midnight_to_end_of_day(): void {
        $r = MxChat_Plus_Transcripts_Export::sanitize_date_range('2026-01-05', '2026-01-31');

        $this->assertSame('2026-01-05 00:00:00', $r['from']);
        // Inclusive end: with a bare date the TIMESTAMP column would compare
        // against midnight and silently drop the final day.
        $this->assertSame('2026-01-31 23:59:59', $r['to']);
    }

    public function test_single_day_range_covers_that_whole_day(): void {
        $r = MxChat_Plus_Transcripts_Export::sanitize_date_range('2026-03-09', '2026-03-09');

        $this->assertSame('2026-03-09 00:00:00', $r['from']);
        $this->assertSame('2026-03-09 23:59:59', $r['to']);
    }

    public function test_reversed_bounds_are_swapped_rather_than_rejected(): void {
        $r = MxChat_Plus_Transcripts_Export::sanitize_date_range('2026-05-20', '2026-05-01');

        $this->assertSame('2026-05-01 00:00:00', $r['from']);
        $this->assertSame('2026-05-20 23:59:59', $r['to']);
    }

    /**
     * @dataProvider badDates
     * @param mixed $from
     * @param mixed $to
     */
    public function test_unusable_ranges_are_rejected($from, $to): void {
        $this->assertNull(MxChat_Plus_Transcripts_Export::sanitize_date_range($from, $to));
    }

    /** @return array<string,array{0:mixed,1:mixed}> */
    public static function badDates(): array {
        return [
            'both empty'        => ['', ''],
            'missing end'       => ['2026-01-01', ''],
            'missing start'     => ['', '2026-01-01'],
            'not a date'        => ['hier', 'aujourdhui'],
            'wrong separator'   => ['2026/01/01', '2026/01/31'],
            'day out of range'  => ['2026-02-30', '2026-03-01'],
            'month out of range'=> ['2026-13-01', '2026-13-02'],
            'short year'        => ['26-01-01', '26-01-31'],
            'sql injection'     => ["2026-01-01' OR '1'='1", '2026-01-31'],
            'array'             => [['2026-01-01'], '2026-01-31'],
            'null'              => [null, null],
        ];
    }

    public function test_an_empty_selection_with_no_dates_cannot_export_everything(): void {
        // The guard that stops a stray click from dumping the whole table.
        $this->assertSame([], MxChat_Plus_Transcripts_Export::sanitize_session_ids([]));
        $this->assertNull(MxChat_Plus_Transcripts_Export::sanitize_date_range('', ''));
    }

    // ─── Wiring ──────────────────────────────────────────────────────────

    public function test_ajax_action_and_nonce_are_namespaced_to_this_plugin(): void {
        // Must never collide with the host's own mxchat_export_transcripts.
        $this->assertSame('mxchat_plus_export_transcripts', MxChat_Plus_Transcripts_Export::AJAX_ACTION);
        $this->assertNotSame('mxchat_export_transcripts', MxChat_Plus_Transcripts_Export::AJAX_ACTION);
        $this->assertStringStartsWith('mxchat_plus_', MxChat_Plus_Transcripts_Export::NONCE_ACTION);
    }

    public function test_targets_the_host_transcripts_screen(): void {
        $this->assertSame('mxchat-transcripts', MxChat_Plus_Transcripts_Export::HOST_PAGE);
    }
}

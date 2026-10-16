<?php
/**
 * Transcripts CSV export — input normalisation, CSV-injection hardening, and
 * the site-local-to-GMT conversion of date ranges.
 *
 * The first two are the module's whole attack surface: session ids arrive from
 * the browser, and message bodies are visitor-authored text that lands in a
 * file someone will open in Excel. The file's shape and the query live in
 * TranscriptsCsvTest.
 */

use PHPUnit\Framework\TestCase;

final class TranscriptsExportTest extends TestCase {

    protected function tearDown(): void {
        unset($GLOBALS['__test_timezone']);
    }

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

    public function test_numeric_session_ids_stay_strings(): void {
        // A numeric-string array key comes back from array_keys() as an int;
        // the declared list<string> must hold anyway.
        $this->assertSame(['123', 'abc'], MxChat_Plus_Transcripts_Export::sanitize_session_ids(['123', 'abc', 123]));
    }

    // ─── Session ids posted as one JSON field ────────────────────────────

    public function test_json_field_decodes_to_a_list(): void {
        $this->assertSame(
            ['a', 'b'],
            MxChat_Plus_Transcripts_Export::decode_session_ids_json('["a","b"]')
        );
    }

    public function test_json_field_survives_more_ids_than_max_input_vars(): void {
        // PHP drops inputs past max_input_vars (1000 by default); one field
        // carrying 1500 ids is one input.
        $ids = [];
        for ($i = 0; $i < 1500; $i++) {
            $ids[] = 'mxchat_chat_' . $i;
        }
        $raw = MxChat_Plus_Transcripts_Export::decode_session_ids_json((string) json_encode($ids));

        $this->assertSame($ids, MxChat_Plus_Transcripts_Export::sanitize_session_ids($raw));
    }

    /**
     * @dataProvider badJson
     * @param mixed $json
     */
    public function test_unusable_json_field_yields_no_ids($json): void {
        $raw = MxChat_Plus_Transcripts_Export::decode_session_ids_json($json);
        $this->assertSame([], MxChat_Plus_Transcripts_Export::sanitize_session_ids($raw));
    }

    /** @return array<string,array{0:mixed}> */
    public static function badJson(): array {
        return [
            'empty string' => [''],
            'not json'     => ['a,b,c'],
            'json string'  => ['"solo"'],
            'json number'  => ['42'],
            'json null'    => ['null'],
            'array input'  => [['a', 'b']],
            'null'         => [null],
        ];
    }

    public function test_json_field_drops_non_scalar_members(): void {
        $raw = MxChat_Plus_Transcripts_Export::decode_session_ids_json('{"x":"keep","y":["nested"],"z":null}');
        $this->assertSame(['keep'], MxChat_Plus_Transcripts_Export::sanitize_session_ids($raw));
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

    // ─── Site-local days to the column's GMT ─────────────────────────────

    public function test_gmt_conversion_is_a_no_op_on_a_utc_site(): void {
        $local = ['from' => '2026-10-16 00:00:00', 'to' => '2026-10-16 23:59:59'];
        $this->assertSame($local, MxChat_Plus_Transcripts_Export::local_range_to_gmt($local));
    }

    /**
     * The scenario that motivated the conversion: on a UTC+2 site, a chat the
     * panel shows at 00:30 on the 16th is stored as 22:30 on the 15th, and one
     * shown at 01:30 on the 17th is stored as 23:30 on the 16th.
     */
    public function test_an_east_of_greenwich_day_starts_the_previous_evening_in_gmt(): void {
        $GLOBALS['__test_timezone'] = 'Europe/Paris';
        $range = MxChat_Plus_Transcripts_Export::sanitize_date_range('2026-10-16', '2026-10-16');
        $gmt   = MxChat_Plus_Transcripts_Export::local_range_to_gmt($range);

        $this->assertSame(['from' => '2026-10-15 22:00:00', 'to' => '2026-10-16 21:59:59'], $gmt);
        $this->assertTrue('2026-10-15 22:30:00' >= $gmt['from'], 'the 00:30 local chat must be inside');
        $this->assertTrue('2026-10-16 23:30:00' > $gmt['to'], 'the 01:30 local chat of the 17th must be outside');
    }

    public function test_a_west_of_greenwich_day_ends_the_next_morning_in_gmt(): void {
        $GLOBALS['__test_timezone'] = 'America/New_York';
        $gmt = MxChat_Plus_Transcripts_Export::local_range_to_gmt(
            ['from' => '2026-01-05 00:00:00', 'to' => '2026-01-05 23:59:59']
        );

        $this->assertSame(['from' => '2026-01-05 05:00:00', 'to' => '2026-01-06 04:59:59'], $gmt);
    }

    public function test_each_bound_takes_its_own_utc_offset_across_a_dst_change(): void {
        // Europe/Paris leaves summer time on 2026-10-25: midnight is UTC+2,
        // the end of that day is UTC+1 — a 25-hour day.
        $GLOBALS['__test_timezone'] = 'Europe/Paris';
        $range = MxChat_Plus_Transcripts_Export::sanitize_date_range('2026-10-25', '2026-10-25');

        $this->assertSame(
            ['from' => '2026-10-24 22:00:00', 'to' => '2026-10-25 22:59:59'],
            MxChat_Plus_Transcripts_Export::local_range_to_gmt($range)
        );
    }

    /** @return array{from:string,to:string} */
    private static function gmt_day(string $day): array {
        return MxChat_Plus_Transcripts_Export::local_range_to_gmt(
            (array) MxChat_Plus_Transcripts_Export::sanitize_date_range($day, $day)
        );
    }

    /** One second after a GMT 'Y-m-d H:i:s' bound. */
    private static function plus_one_second(string $gmt): string {
        return (new DateTimeImmutable($gmt, new DateTimeZone('UTC')))->modify('+1 second')->format('Y-m-d H:i:s');
    }

    public function test_an_hour_repeated_at_local_midnight_belongs_to_the_day_it_ends(): void {
        // America/Santiago leaves summer time at 2026-04-05 03:00 UTC: local
        // 00:00 becomes 23:00 on the 4th, so 23:00-23:59 happens twice. Local
        // "23:59:59" alone resolves to the first pass (02:59:59 UTC), and the
        // second hour (03:00-03:59 UTC) used to fall in neither day.
        $GLOBALS['__test_timezone'] = 'America/Santiago';
        $saturday = self::gmt_day('2026-04-04');
        $sunday   = self::gmt_day('2026-04-05');

        $this->assertSame('2026-04-05 03:59:59', $saturday['to']);
        $this->assertSame('2026-04-05 04:00:00', $sunday['from']);
        $this->assertSame($sunday['from'], self::plus_one_second($saturday['to']), 'contiguous');
    }

    public function test_a_skipped_hour_before_local_midnight_is_not_exported_twice(): void {
        // America/Nuuk starts summer time at 2026-03-29 01:00 UTC, jumping
        // from 23:00 on the 28th to 00:00 on the 29th. Local "23:59:59" does
        // not exist and used to land an hour INTO the next day (01:59:59 UTC),
        // which both days' exports then contained.
        $GLOBALS['__test_timezone'] = 'America/Nuuk';
        $saturday = self::gmt_day('2026-03-28');
        $sunday   = self::gmt_day('2026-03-29');

        $this->assertSame('2026-03-29 00:59:59', $saturday['to']);
        $this->assertSame('2026-03-29 01:00:00', $sunday['from']);
        $this->assertSame($sunday['from'], self::plus_one_second($saturday['to']), 'contiguous');
    }

    public function test_a_range_ending_on_a_transition_day_ends_where_the_next_day_starts(): void {
        // Not only single-day ranges: any range whose last day is the
        // transition day had the same edge.
        $GLOBALS['__test_timezone'] = 'America/Santiago';
        $range = MxChat_Plus_Transcripts_Export::local_range_to_gmt(
            (array) MxChat_Plus_Transcripts_Export::sanitize_date_range('2026-03-01', '2026-04-04')
        );

        $this->assertSame('2026-03-01 03:00:00', $range['from']);
        $this->assertSame('2026-04-05 03:59:59', $range['to']);
    }

    /**
     * Whatever the zone does around a clock change, consecutive days tile
     * the GMT line: day N ends one second before day N+1 begins. Checked on
     * the two days around every 2026 transition of every zone PHP knows.
     */
    public function test_consecutive_days_are_contiguous_around_every_transition(): void {
        $failures = [];
        $start    = (int) strtotime('2026-01-01 00:00:00 UTC');
        $end      = (int) strtotime('2027-01-01 00:00:00 UTC');

        foreach (DateTimeZone::listIdentifiers() as $zone_id) {
            $zone = new DateTimeZone($zone_id);
            $GLOBALS['__test_timezone'] = $zone_id;
            foreach (array_slice($zone->getTransitions($start, $end), 1) as $transition) {
                $local_day = (new DateTimeImmutable('@' . ($transition['ts'] - 1)))->setTimezone($zone);
                $day       = $local_day->format('Y-m-d');
                $next      = $local_day->modify('+1 day')->format('Y-m-d');
                $to        = self::gmt_day($day)['to'];
                $from      = self::gmt_day($next)['from'];
                if (self::plus_one_second($to) !== $from) {
                    $failures[] = "{$zone_id} {$day}: ends {$to}, next day starts {$from}";
                }
            }
        }

        $this->assertSame([], $failures);
    }

    public function test_a_far_future_end_date_west_of_greenwich_stays_a_four_digit_year(): void {
        // "From January onwards" is often typed as an end date of 9999-12-31.
        // In New York its last second is 10000-01-01 04:59:59 GMT: outside
        // MySQL's DATETIME syntax, and matching no row however it is read.
        $GLOBALS['__test_timezone'] = 'America/New_York';
        $range = MxChat_Plus_Transcripts_Export::local_range_to_gmt(
            (array) MxChat_Plus_Transcripts_Export::sanitize_date_range('2026-01-01', '9999-12-31')
        );

        $this->assertSame('2026-01-01 05:00:00', $range['from']);
        $this->assertSame(MxChat_Plus_Transcripts_Export::GMT_MAX, $range['to']);
        $this->assertSame('9999-12-31 23:59:59', $range['to']);
    }

    public function test_a_far_future_end_date_east_of_greenwich_is_converted_exactly(): void {
        $GLOBALS['__test_timezone'] = 'Asia/Tokyo';
        $range = self::gmt_day('9999-12-31');

        $this->assertSame(['from' => '9999-12-30 15:00:00', 'to' => '9999-12-31 14:59:59'], $range);
    }

    public function test_a_bound_that_is_not_an_end_of_day_is_converted_as_given(): void {
        $GLOBALS['__test_timezone'] = 'Europe/Paris';
        $this->assertSame(
            ['from' => '2026-01-05 11:00:00', 'to' => '2026-01-05 16:30:00'],
            MxChat_Plus_Transcripts_Export::local_range_to_gmt(
                ['from' => '2026-01-05 12:00:00', 'to' => '2026-01-05 17:30:00']
            )
        );
    }

    public function test_the_validator_itself_stays_timezone_free(): void {
        // sanitize_date_range() is shared with the tracking report and must
        // keep returning the dates it was given; conversion is a separate step.
        $GLOBALS['__test_timezone'] = 'Asia/Tokyo';
        $this->assertSame(
            ['from' => '2026-03-09 00:00:00', 'to' => '2026-03-09 23:59:59'],
            MxChat_Plus_Transcripts_Export::sanitize_date_range('2026-03-09', '2026-03-09')
        );
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

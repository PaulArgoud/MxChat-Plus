<?php
/**
 * Tracking options — the read/write path between the settings form and the
 * five values the front-end script is localized with.
 *
 * Three things here fail silently rather than loudly, which is why they are
 * locked:
 *
 *   - an unticked checkbox is simply absent from $_POST, so a sanitiser that
 *     returned only what it was given would leave get() to resurrect the
 *     *default* — `true` for track_matomo and send_session_id. Unticking
 *     either would look like it had done nothing at all;
 *   - get() must materialise all five keys whatever the row holds, because
 *     the localized object is built straight from it and a missing key
 *     becomes an `undefined` in the browser with no PHP-side trace;
 *   - migrate_legacy() runs once, and the only marker that it has run is the
 *     deletion of the old row. Get that wrong and a site either loses its
 *     settings or has them reverted on every request.
 */

use PHPUnit\Framework\TestCase;

final class TrackingOptionsTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__test_options']         = [];
        $GLOBALS['__test_settings_errors'] = [];
    }

    /** @return array<string,mixed> */
    private function stored(): array {
        $value = get_option(MXCHAT_PLUS_TRACKING_OPTION_KEY, []);
        return is_array($value) ? $value : [];
    }

    // ─── Defaults ─────────────────────────────────────────────────────────

    public function test_defaults_are_the_five_documented_settings(): void {
        $this->assertSame(
            [
                'track_matomo'        => true,
                'track_ga4'           => false,
                'send_session_id'     => true,
                'matomo_dimension_id' => 0,
                'debug'               => false,
            ],
            MxChat_Plus_Tracking_Options::defaults()
        );
    }

    public function test_matomo_is_on_and_ga4_off_out_of_the_box(): void {
        $d = MxChat_Plus_Tracking_Options::defaults();

        // gtag is absent from most installs this module targets; firing at a
        // missing global would only produce console noise.
        $this->assertTrue($d['track_matomo']);
        $this->assertFalse($d['track_ga4']);
    }

    // ─── get() ────────────────────────────────────────────────────────────

    public function test_get_returns_defaults_when_nothing_is_stored(): void {
        $this->assertSame(MxChat_Plus_Tracking_Options::defaults(), MxChat_Plus_Tracking_Options::get());
    }

    /**
     * A row can be a string if something else wrote over our option name.
     * Reading it must not produce an array of nulls.
     *
     * @dataProvider nonArrayRows
     * @param mixed $row
     */
    public function test_get_falls_back_to_defaults_for_a_non_array_row($row): void {
        update_option(MXCHAT_PLUS_TRACKING_OPTION_KEY, $row);

        $this->assertSame(MxChat_Plus_Tracking_Options::defaults(), MxChat_Plus_Tracking_Options::get());
    }

    /** @return array<string,array{0:mixed}> */
    public static function nonArrayRows(): array {
        return [
            'string'  => ['a:1:{}'],
            'int'     => [7],
            'bool'    => [true],
            'null'    => [null],
        ];
    }

    public function test_get_always_yields_exactly_the_five_keys(): void {
        update_option(MXCHAT_PLUS_TRACKING_OPTION_KEY, [
            'track_matomo' => false,
            // A key from an older build, or a hand-edited row: it must not
            // survive into the array the script is localized from.
            'track_piwik'  => true,
        ]);

        $out = MxChat_Plus_Tracking_Options::get();

        $this->assertSame(
            ['track_matomo', 'track_ga4', 'send_session_id', 'matomo_dimension_id', 'debug'],
            array_keys($out)
        );
        $this->assertArrayNotHasKey('track_piwik', $out);
    }

    public function test_get_fills_a_key_the_row_predates_with_its_default(): void {
        // Exactly the shape migrate_legacy() leaves behind on an install that
        // came from the standalone plugin, which never had these two settings.
        update_option(MXCHAT_PLUS_TRACKING_OPTION_KEY, [
            'track_matomo' => true,
            'track_ga4'    => true,
            'debug'        => false,
        ]);

        $out = MxChat_Plus_Tracking_Options::get();

        $this->assertTrue($out['send_session_id']);
        $this->assertSame(0, $out['matomo_dimension_id']);
    }

    public function test_get_coerces_string_values_to_their_declared_types(): void {
        // WP-CLI and hand-edited rows store strings where the form stores bools.
        update_option(MXCHAT_PLUS_TRACKING_OPTION_KEY, [
            'track_matomo'        => '0',
            'track_ga4'           => '1',
            'send_session_id'     => '',
            'matomo_dimension_id' => '42',
            'debug'               => 'yes',
        ]);

        $out = MxChat_Plus_Tracking_Options::get();

        $this->assertFalse($out['track_matomo']);
        $this->assertTrue($out['track_ga4']);
        $this->assertFalse($out['send_session_id']);
        $this->assertSame(42, $out['matomo_dimension_id']);
        $this->assertTrue($out['debug']);
    }

    public function test_get_clamps_a_stored_dimension_that_is_out_of_range(): void {
        update_option(MXCHAT_PLUS_TRACKING_OPTION_KEY, ['matomo_dimension_id' => 1000]);

        // Not merely read back: Matomo would reject dimension 1000, and the
        // whole event with it.
        $this->assertSame(0, MxChat_Plus_Tracking_Options::get()['matomo_dimension_id']);
    }

    // ─── sanitize() ───────────────────────────────────────────────────────

    public function test_sanitize_turns_an_absent_checkbox_into_false(): void {
        // The submission of a form with every box unticked: only the number
        // input is present, because a checkbox posts nothing when off.
        $out = MxChat_Plus_Tracking_Options::sanitize(['matomo_dimension_id' => '0']);

        foreach (['track_matomo', 'track_ga4', 'send_session_id', 'debug'] as $key) {
            $this->assertArrayHasKey($key, $out, "$key must be written as false, not left out");
            $this->assertFalse($out[$key]);
        }
    }

    public function test_sanitize_always_returns_all_five_keys(): void {
        $this->assertSame(
            ['track_matomo', 'track_ga4', 'send_session_id', 'matomo_dimension_id', 'debug'],
            array_keys(MxChat_Plus_Tracking_Options::sanitize([]))
        );
    }

    public function test_sanitize_accepts_the_values_a_browser_actually_posts(): void {
        $out = MxChat_Plus_Tracking_Options::sanitize([
            'track_matomo'        => '1',
            'track_ga4'           => 'on',
            'send_session_id'     => '1',
            'matomo_dimension_id' => '7',
            'debug'               => '1',
        ]);

        $this->assertSame(
            [
                'track_matomo'        => true,
                'track_ga4'           => true,
                'send_session_id'     => true,
                'matomo_dimension_id' => 7,
                'debug'               => true,
            ],
            $out
        );
    }

    /**
     * @dataProvider dimensionInputs
     * @param mixed $input
     */
    public function test_sanitize_clamps_the_matomo_dimension($input, int $expected): void {
        $this->assertSame($expected, MxChat_Plus_Tracking_Options::sanitize(
            ['matomo_dimension_id' => $input]
        )['matomo_dimension_id']);
    }

    /** @return array<string,array{0:mixed,1:int}> */
    public static function dimensionInputs(): array {
        return [
            'unset (0)'        => ['0', 0],
            'empty string'     => ['', 0],
            'lowest valid'     => ['1', 1],
            'mid range'        => ['250', 250],
            'highest valid'    => ['999', 999],
            'one above range'  => ['1000', 0],
            'far above range'  => [123456, 0],
            // absint() takes the absolute value, so a stray minus sign is
            // corrected rather than disabling the dimension. Documented
            // behaviour, not an oversight.
            'negative in range' => ['-4', 4],
            'negative out of range' => ['-1000', 0],
            'non numeric'      => ['abc', 0],
            'float string'     => ['12.9', 12],
            'non scalar'       => [['3'], 0],
            'null'             => [null, 0],
        ];
    }

    public function test_sanitize_treats_a_non_array_submission_as_everything_unticked(): void {
        $expected = [
            'track_matomo'        => false,
            'track_ga4'           => false,
            'send_session_id'     => false,
            'matomo_dimension_id' => 0,
            'debug'               => false,
        ];

        $this->assertSame($expected, MxChat_Plus_Tracking_Options::sanitize('corrupt'));
        $this->assertSame($expected, MxChat_Plus_Tracking_Options::sanitize(null));
        $this->assertSame($expected, MxChat_Plus_Tracking_Options::sanitize(42));
    }

    public function test_sanitize_warns_once_when_it_drops_an_out_of_range_dimension(): void {
        MxChat_Plus_Tracking_Options::sanitize(['matomo_dimension_id' => '1000']);

        $errors = $GLOBALS['__test_settings_errors'];
        $this->assertCount(1, $errors, 'a silently dropped dimension looks like a saved one');
        $this->assertSame('matomo_dimension_out_of_range', $errors[0]['code']);
        $this->assertSame('warning', $errors[0]['type']);
        $this->assertSame(MXCHAT_PLUS_TRACKING_OPTION_KEY, $errors[0]['setting']);
    }

    public function test_sanitize_stays_quiet_when_the_dimension_is_simply_unset(): void {
        // "0" and "" both mean "no custom dimension", which is the default —
        // warning about it would put a scary notice on every save.
        MxChat_Plus_Tracking_Options::sanitize(['matomo_dimension_id' => '0']);
        MxChat_Plus_Tracking_Options::sanitize(['matomo_dimension_id' => '']);
        MxChat_Plus_Tracking_Options::sanitize([]);

        $this->assertSame([], $GLOBALS['__test_settings_errors']);
    }

    public function test_sanitize_output_survives_a_round_trip_through_get(): void {
        // The pairing that matters: what sanitize() stores must read back
        // identically, or a saved setting reverts on the next page load.
        $saved = MxChat_Plus_Tracking_Options::sanitize([
            'track_ga4'           => '1',
            'matomo_dimension_id' => '5',
        ]);
        update_option(MXCHAT_PLUS_TRACKING_OPTION_KEY, $saved);

        $this->assertSame($saved, MxChat_Plus_Tracking_Options::get());
        // Specifically: the two boxes left unticked stay off.
        $this->assertFalse(MxChat_Plus_Tracking_Options::get()['track_matomo']);
        $this->assertFalse(MxChat_Plus_Tracking_Options::get()['send_session_id']);
    }

    // ─── migrate_legacy() ─────────────────────────────────────────────────

    public function test_legacy_option_name_is_the_standalone_plugins_own(): void {
        // A rename here strands the settings of every install that came from
        // the standalone plugin — with no error anywhere.
        $this->assertSame('mxchat_tracking_options', MxChat_Plus_Tracking_Options::LEGACY_OPTION_KEY);
    }

    public function test_migrate_carries_the_legacy_settings_across(): void {
        update_option(MxChat_Plus_Tracking_Options::LEGACY_OPTION_KEY, [
            'track_matomo' => false,
            'track_ga4'    => true,
            'debug'        => true,
        ]);

        $this->assertTrue(MxChat_Plus_Tracking_Options::migrate_legacy());

        $out = MxChat_Plus_Tracking_Options::get();
        $this->assertFalse($out['track_matomo']);
        $this->assertTrue($out['track_ga4']);
        $this->assertTrue($out['debug']);
    }

    public function test_migrate_gives_the_two_new_settings_our_defaults(): void {
        // The standalone plugin had no send_session_id and no dimension. Read
        // as unticked boxes they would arrive as false, quietly turning off
        // the session id that joins a click to a transcript.
        update_option(MxChat_Plus_Tracking_Options::LEGACY_OPTION_KEY, ['track_matomo' => true]);

        MxChat_Plus_Tracking_Options::migrate_legacy();

        $out = MxChat_Plus_Tracking_Options::get();
        $this->assertTrue($out['send_session_id']);
        $this->assertSame(0, $out['matomo_dimension_id']);
    }

    public function test_migrate_deletes_the_legacy_row(): void {
        update_option(MxChat_Plus_Tracking_Options::LEGACY_OPTION_KEY, ['track_ga4' => true]);

        MxChat_Plus_Tracking_Options::migrate_legacy();

        // That deletion *is* the one-shot marker — there is no separate flag.
        $this->assertFalse(get_option(MxChat_Plus_Tracking_Options::LEGACY_OPTION_KEY, false));
    }

    public function test_migrate_is_idempotent(): void {
        update_option(MxChat_Plus_Tracking_Options::LEGACY_OPTION_KEY, ['track_matomo' => false]);

        $this->assertTrue(MxChat_Plus_Tracking_Options::migrate_legacy());
        $migrated = $this->stored();

        $this->assertFalse(MxChat_Plus_Tracking_Options::migrate_legacy());
        $this->assertSame($migrated, $this->stored(), 'a second run must not rewrite the row');
    }

    public function test_migrate_does_not_overwrite_settings_we_already_have(): void {
        $ours = MxChat_Plus_Tracking_Options::sanitize(['track_matomo' => '1']);
        update_option(MXCHAT_PLUS_TRACKING_OPTION_KEY, $ours);
        update_option(MxChat_Plus_Tracking_Options::LEGACY_OPTION_KEY, ['track_matomo' => false]);

        $this->assertFalse(MxChat_Plus_Tracking_Options::migrate_legacy());
        $this->assertSame($ours, $this->stored());
        // The legacy row is left where it is: we did not read it, so we have
        // no business deleting it.
        $this->assertSame(
            ['track_matomo' => false],
            get_option(MxChat_Plus_Tracking_Options::LEGACY_OPTION_KEY, [])
        );
    }

    public function test_migrate_treats_an_empty_array_as_settings_we_already_have(): void {
        // An install that saved the tab with every box unticked stores [] ...
        // which must not be mistaken for "never configured" and overwritten.
        update_option(MXCHAT_PLUS_TRACKING_OPTION_KEY, []);
        update_option(MxChat_Plus_Tracking_Options::LEGACY_OPTION_KEY, ['track_ga4' => true]);

        $this->assertFalse(MxChat_Plus_Tracking_Options::migrate_legacy());
    }

    public function test_migrate_is_a_no_op_with_no_legacy_row(): void {
        $this->assertFalse(MxChat_Plus_Tracking_Options::migrate_legacy());
        $this->assertSame([], $this->stored(), 'nothing to migrate must write nothing');
    }

    public function test_migrate_leaves_an_unreadable_legacy_row_alone(): void {
        // Deleting a row we could not read would destroy the only copy of
        // those settings.
        update_option(MxChat_Plus_Tracking_Options::LEGACY_OPTION_KEY, 'a:1:{s:3:"foo";}');

        $this->assertFalse(MxChat_Plus_Tracking_Options::migrate_legacy());
        $this->assertSame(
            'a:1:{s:3:"foo";}',
            get_option(MxChat_Plus_Tracking_Options::LEGACY_OPTION_KEY, false)
        );
    }

    public function test_migrate_normalises_the_legacy_values_it_copies(): void {
        // The standalone plugin stored checkbox values as strings.
        update_option(MxChat_Plus_Tracking_Options::LEGACY_OPTION_KEY, [
            'track_matomo' => '1',
            'track_ga4'    => '',
            'debug'        => '1',
        ]);

        MxChat_Plus_Tracking_Options::migrate_legacy();

        $this->assertSame(
            [
                'track_matomo'        => true,
                'track_ga4'           => false,
                'send_session_id'     => true,
                'matomo_dimension_id' => 0,
                'debug'               => true,
            ],
            $this->stored()
        );
    }
}

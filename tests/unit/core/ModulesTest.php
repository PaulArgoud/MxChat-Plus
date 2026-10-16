<?php
/**
 * MxChat_Plus_Modules: defaults and the one-time 1.1.0 DuckDB switch-off.
 */

use PHPUnit\Framework\TestCase;

final class ModulesTest extends TestCase {

    protected function setUp(): void {
        unset(
            $GLOBALS['__test_options'][MxChat_Plus_Modules::OPTION],
            $GLOBALS['__test_options'][MxChat_Plus_Modules::DUCKDB_OFF_MARKER],
            $GLOBALS['__test_options'][MxChat_Plus_Modules::DUCKDB_OFF_NOTICE]
        );
    }

    protected function tearDown(): void {
        $this->setUp();
    }

    public function test_duckdb_and_tracking_are_off_by_default(): void {
        $all = MxChat_Plus_Modules::all();
        $this->assertFalse($all[MxChat_Plus_Modules::DUCKDB]);
        $this->assertFalse($all[MxChat_Plus_Modules::TRACKING]);
        $this->assertTrue($all[MxChat_Plus_Modules::PROMPTCACHE]);
        $this->assertTrue($all[MxChat_Plus_Modules::TRANSCRIPTS]);
    }

    public function test_existing_install_with_duckdb_on_is_switched_off_once_with_a_notice(): void {
        update_option(MxChat_Plus_Modules::OPTION, [
            MxChat_Plus_Modules::DUCKDB => true, MxChat_Plus_Modules::PROMPTCACHE => true,
            MxChat_Plus_Modules::TRANSCRIPTS => false, MxChat_Plus_Modules::TRACKING => true,
        ]);

        MxChat_Plus_Modules::switch_off_duckdb_once();

        $all = MxChat_Plus_Modules::all();
        $this->assertFalse($all[MxChat_Plus_Modules::DUCKDB]);
        $this->assertFalse($all[MxChat_Plus_Modules::TRANSCRIPTS], 'other choices are kept');
        $this->assertTrue($all[MxChat_Plus_Modules::TRACKING], 'other choices are kept');
        $this->assertSame('1', get_option(MxChat_Plus_Modules::DUCKDB_OFF_NOTICE));

        // The admin turns it back on: a later boot must not switch it off again.
        MxChat_Plus_Modules::save([MxChat_Plus_Modules::DUCKDB => '1', MxChat_Plus_Modules::PROMPTCACHE => '1']);
        $this->assertFalse(get_option(MxChat_Plus_Modules::DUCKDB_OFF_NOTICE), 'saving the Modules screen clears the notice');
        MxChat_Plus_Modules::switch_off_duckdb_once();
        $this->assertTrue(MxChat_Plus_Modules::is_enabled(MxChat_Plus_Modules::DUCKDB));
    }

    public function test_fresh_or_duckdb_off_install_gets_no_notice(): void {
        MxChat_Plus_Modules::install_defaults();
        MxChat_Plus_Modules::switch_off_duckdb_once();
        $this->assertFalse(get_option(MxChat_Plus_Modules::DUCKDB_OFF_NOTICE));
        $this->assertSame('1', get_option(MxChat_Plus_Modules::DUCKDB_OFF_MARKER));

        unset($GLOBALS['__test_options'][MxChat_Plus_Modules::DUCKDB_OFF_MARKER], $GLOBALS['__test_options'][MxChat_Plus_Modules::OPTION]);
        MxChat_Plus_Modules::switch_off_duckdb_once();
        $this->assertFalse(get_option(MxChat_Plus_Modules::DUCKDB_OFF_NOTICE), 'nothing stored yet: nothing to switch off');
    }
}

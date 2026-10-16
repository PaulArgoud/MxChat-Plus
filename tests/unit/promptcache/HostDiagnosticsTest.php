<?php
/**
 * Diagnostics of the host shown on the Modules screen (MxChat_Plus_Host,
 * MxChat_Plus_Admin::environment_rows()).
 *
 * Kept in the promptcache suite (phpunit.xml.dist has no core suite): the
 * streaming and AI Tools rows exist for this module, and the version row sits
 * in the same table.
 *
 * Locked here:
 *   - streaming_enabled() reads `enable_streaming_toggle` exactly like the
 *     host's server-side gate — it read two keys no MxChat release ever wrote,
 *     so the screen always said "Off";
 *   - with AI Tools active and a Claude model, the screen no longer claims the
 *     main chat cannot be cached while streaming;
 *   - the running host version is compared with TESTED_UP_TO / MINIMUM, and an
 *     undetectable version is never called "too old";
 *   - tool_loop_profile() reads the AI Tools loop limits the host itself reads,
 *     and dates the budget gate (3.2.22) and the single-call tool_choice (3.2.23).
 */

use PHPUnit\Framework\TestCase;

final class HostDiagnosticsTest extends TestCase {

    protected function setUp(): void {
        unset(
            $GLOBALS['__test_options']['mxchat_options'],
            $GLOBALS['__test_options'][MxChat_Plus_Modules::OPTION],
            $GLOBALS['__test_filter_overrides']['mxchat_fc_keep_first_hop_text'],
            $GLOBALS['__test_filter_overrides']['mxchat_function_call_max_depth'],
            $GLOBALS['__test_filter_overrides']['mxchat_function_call_max_per_turn']
        );
        MxChat_Tool_Registry::reset();
        MxChat_Model_Catalog::reset();
    }

    protected function tearDown(): void {
        $this->setUp();
    }

    /** A Claude site with one AI Tool enabled — the host's FC gate passes. */
    private static function claude_with_ai_tools(string $streaming = 'on'): void {
        $GLOBALS['__test_options']['mxchat_options'] = [
            'model'                   => 'claude-opus-5',
            'claude_api_key'          => 'sk-ant-test',
            'enable_streaming_toggle' => $streaming,
        ];
        MxChat_Tool_Registry::$enabled       = true;
        MxChat_Tool_Registry::$enabled_tools = [['callback' => 'mxchat_search_kb', 'enabled' => true]];
    }

    /** @return array<string,string> label => html */
    private static function rows(): array {
        $out = [];
        foreach (MxChat_Plus_Admin::environment_rows() as [$label, $html]) {
            $out[$label] = $html;
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────────────
    //  streaming_enabled()
    // ──────────────────────────────────────────────────────────────────

    /** @dataProvider streamingProvider */
    public function test_streaming_enabled_mirrors_the_hosts_server_side_gate(mixed $option, bool $expected): void {
        $GLOBALS['__test_options']['mxchat_options'] = $option;
        $this->assertSame($expected, MxChat_Plus_Host::streaming_enabled());
    }

    /** @return array<string, array{0:mixed, 1:bool}> */
    public static function streamingProvider(): array {
        return [
            'on'                       => [['enable_streaming_toggle' => 'on'], true],
            'off'                      => [['enable_streaming_toggle' => 'off'], false],
            // isset() && === 'on' : a missing key does NOT stream on the server.
            'missing key'              => [['model' => 'claude-opus-5'], false],
            'not exactly on'           => [['enable_streaming_toggle' => 'ON'], false],
            'boolean true'             => [['enable_streaming_toggle' => true], false],
            // The keys read before the fix never existed in MxChat.
            'legacy enable_streaming'  => [['enable_streaming' => '1'], false],
            'legacy mxchat_ prefix'    => [['mxchat_enable_streaming' => 'on'], false],
            'non-array option'         => ['on', false],
        ];
    }

    public function test_streaming_enabled_without_any_option(): void {
        $this->assertFalse(MxChat_Plus_Host::streaming_enabled());
    }

    // ──────────────────────────────────────────────────────────────────
    //  AI Tools route (mirror of mxchat_fc_should_run)
    // ──────────────────────────────────────────────────────────────────

    public function test_ai_tools_route_claude_when_the_host_gate_passes(): void {
        self::claude_with_ai_tools();
        $this->assertTrue(MxChat_Plus_Host::ai_tools_route_claude());
    }

    public function test_ai_tools_route_claude_mirrors_every_condition_of_the_host_gate(): void {
        self::claude_with_ai_tools();
        MxChat_Tool_Registry::$enabled = false;
        $this->assertFalse(MxChat_Plus_Host::ai_tools_route_claude(), 'no tool in the AI Tools list');

        self::claude_with_ai_tools();
        MxChat_Tool_Registry::$enabled_tools = [];
        $this->assertFalse(MxChat_Plus_Host::ai_tools_route_claude(), 'only tool withheld (live-agent schedule)');

        self::claude_with_ai_tools();
        MxChat_Model_Catalog::$supports_tools = false;
        $this->assertFalse(MxChat_Plus_Host::ai_tools_route_claude(), 'catalog says the model has no tools');
        MxChat_Model_Catalog::reset();

        self::claude_with_ai_tools();
        $GLOBALS['__test_options']['mxchat_options']['model'] = 'gpt-5.6-sol';
        $this->assertFalse(MxChat_Plus_Host::ai_tools_route_claude(), 'not a Claude model');

        self::claude_with_ai_tools();
        unset($GLOBALS['__test_options']['mxchat_options']['claude_api_key']);
        $this->assertFalse(MxChat_Plus_Host::ai_tools_route_claude(), 'mxchat_fc_resolve_provider() needs the key');

        $GLOBALS['__test_options']['mxchat_options'] = 'garbage';
        $this->assertFalse(MxChat_Plus_Host::ai_tools_route_claude());
    }

    public function test_keeps_first_hop_text_follows_the_hosts_filter(): void {
        // No version constant in the suite: version() is '' and the filter decides.
        $this->assertTrue(MxChat_Plus_Host::keeps_first_hop_text('claude-opus-5'));
        $GLOBALS['__test_filter_overrides']['mxchat_fc_keep_first_hop_text'] = false;
        $this->assertFalse(MxChat_Plus_Host::keeps_first_hop_text('claude-opus-5'));
    }

    // ──────────────────────────────────────────────────────────────────
    //  tool_loop_profile()
    // ──────────────────────────────────────────────────────────────────

    public function test_tool_loop_profile_reads_the_host_limits(): void {
        // No version constant in the suite: the newest loop behaviour.
        $this->assertSame(
            ['depth' => 5, 'budget' => 8, 'budget_gate' => true, 'single_call_choice' => true],
            MxChat_Plus_Host::tool_loop_profile(),
            'host defaults of mxchat_function_call_max_depth / _max_per_turn'
        );

        $GLOBALS['__test_filter_overrides']['mxchat_function_call_max_depth']    = 2;
        $GLOBALS['__test_filter_overrides']['mxchat_function_call_max_per_turn'] = 99;
        $profile = MxChat_Plus_Host::tool_loop_profile();
        $this->assertSame(2, $profile['depth']);
        $this->assertSame(20, $profile['budget'], 'clamped by the host, not by us');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_tool_loop_profile_on_3_2_21(): void {
        define('MXCHAT_BASE_VERSION', '3.2.21');
        $profile = MxChat_Plus_Host::tool_loop_profile();
        $this->assertFalse($profile['budget_gate'], 'tools offered until the depth only');
        $this->assertFalse($profile['single_call_choice']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_tool_loop_profile_on_3_2_22(): void {
        define('MXCHAT_BASE_VERSION', '3.2.22');
        $profile = MxChat_Plus_Host::tool_loop_profile();
        $this->assertTrue($profile['budget_gate']);
        $this->assertFalse($profile['single_call_choice'], 'disable_parallel_tool_use arrived in 3.2.23');
    }

    // ──────────────────────────────────────────────────────────────────
    //  version_status()
    // ──────────────────────────────────────────────────────────────────

    public function test_version_status_compares_with_minimum_and_tested_up_to(): void {
        $h = MxChat_Plus_Host::class;
        $this->assertSame('unknown', $h::version_status(''), 'never "too old" when undetectable');
        $this->assertSame('below_minimum', $h::version_status('0.9'));
        $this->assertSame('below_minimum', $h::version_status('3.2.20'), 'the README requires 3.2.21');
        $this->assertSame('supported', $h::version_status('3.2.21'));
        $this->assertSame('supported', $h::version_status($h::MINIMUM));
        $this->assertSame('supported', $h::version_status($h::TESTED_UP_TO));
        $this->assertSame('newer_than_tested', $h::version_status($h::TESTED_UP_TO . '.1'));
        $this->assertSame('newer_than_tested', $h::version_status('99.0.0'));
        $this->assertTrue(version_compare($h::MINIMUM, $h::TESTED_UP_TO, '<='), 'constants consistent');
    }

    public function test_version_status_defaults_to_the_running_host(): void {
        // The suite defines neither MXCHAT_BASE_VERSION nor MXCHAT_VERSION.
        $this->assertSame('', MxChat_Plus_Host::version());
        $this->assertSame('unknown', MxChat_Plus_Host::version_status());
    }

    // ──────────────────────────────────────────────────────────────────
    //  Environment rows
    // ──────────────────────────────────────────────────────────────────

    public function test_rows_with_streaming_off(): void {
        $GLOBALS['__test_options']['mxchat_options'] = ['enable_streaming_toggle' => 'off'];
        $rows = self::rows();

        $this->assertSame('unknown', $rows['MxChat version']);
        $this->assertStringStartsWith('Off — the main chat goes through the WordPress HTTP API', $rows['MxChat streaming']);
        $this->assertArrayNotHasKey('Native prompt cache', $rows, 'unknown version: no claim about the host marker');
    }

    public function test_rows_with_streaming_on_and_no_ai_tools(): void {
        $GLOBALS['__test_options']['mxchat_options'] = ['enable_streaming_toggle' => 'on', 'model' => 'claude-opus-5'];
        $html = self::rows()['MxChat streaming'];

        $this->assertStringContainsString('On — this module cannot add its breakpoints to streamed replies.', $html);
        $this->assertStringContainsString('Turn streaming off in MxChat settings to cache the main chat.', $html);
        $this->assertStringNotContainsString('AI Tools', $html);
    }

    public function test_rows_with_streaming_on_and_ai_tools_active(): void {
        self::claude_with_ai_tools();
        $html = self::rows()['MxChat streaming'];

        $this->assertStringContainsString('MxChat answers most turns through its buffered tool loop', $html);
        $this->assertStringNotContainsString('Turn streaming off in MxChat settings to cache the main chat.', $html);

        // An add-on turned the 3.2.22 behaviour off: only tool turns are buffered.
        $GLOBALS['__test_filter_overrides']['mxchat_fc_keep_first_hop_text'] = false;
        $html = self::rows()['MxChat streaming'];
        $this->assertStringContainsString('turns where Claude calls a tool', $html);
        $this->assertStringContainsString('Turn streaming off in MxChat settings to cache them too.', $html);
    }

    public function test_rows_stop_after_the_version_when_promptcache_is_off(): void {
        $GLOBALS['__test_options'][MxChat_Plus_Modules::OPTION] = [MxChat_Plus_Modules::PROMPTCACHE => false];
        $GLOBALS['__test_options']['mxchat_options'] = ['enable_streaming_toggle' => 'on'];
        $this->assertSame(['MxChat version'], array_keys(self::rows()));
    }

    /**
     * Separate process: the host version is a constant.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_rows_on_a_tested_host_with_native_cache_and_streaming_on(): void {
        define('MXCHAT_BASE_VERSION', '3.2.21');
        MxChat_Tool_Registry::reset();
        $GLOBALS['__test_options']['mxchat_options'] = ['enable_streaming_toggle' => 'on'];
        $rows = self::rows();

        $this->assertSame('3.2.21', $rows['MxChat version'], 'supported version: no notice');
        $this->assertStringContainsString("MxChat's own system-prompt breakpoint still applies to them (5-minute TTL)", $rows['MxChat streaming']);
        $this->assertStringContainsString('which also covers the AI Tools definitions sent before it', $rows['Native prompt cache']);
        $this->assertStringContainsString('{current_datetime}', $rows['Native prompt cache']);
    }

    /**
     * Before 3.2.22 a no-tool turn fell back to the normal path.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_rows_on_a_host_older_than_3_2_22_with_ai_tools(): void {
        define('MXCHAT_BASE_VERSION', '3.2.21');
        self::claude_with_ai_tools();
        $this->assertFalse(MxChat_Plus_Host::keeps_first_hop_text('claude-opus-5'));
        $this->assertStringContainsString('turns where Claude calls a tool', self::rows()['MxChat streaming']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_version_row_flags_a_host_newer_than_tested(): void {
        define('MXCHAT_BASE_VERSION', '99.0.0');
        $html = self::rows()['MxChat version'];
        $this->assertStringStartsWith('99.0.0 — newer than ' . MxChat_Plus_Host::TESTED_UP_TO, $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_version_row_flags_a_host_older_than_minimum(): void {
        define('MXCHAT_BASE_VERSION', '3.1.0');
        $html = self::rows()['MxChat version'];
        $this->assertStringContainsString('<strong>older than ' . MxChat_Plus_Host::MINIMUM, $html);
        $this->assertArrayNotHasKey('Native prompt cache', self::rows());
    }
}

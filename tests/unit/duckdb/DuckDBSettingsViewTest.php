<?php

use PHPUnit\Framework\TestCase;

/**
 * Renders the DuckDB settings partials whose text depends on the routing
 * settings, and checks what the owner is told:
 *
 *   - Activation: the Multi-Bot routing fields round-trip through the form
 *     (an older install shows its effective route-everything state, so a
 *     save never silently moves its bots);
 *   - Diagnostics: the Option B paragraph says whether the default bot is
 *     really served, instead of "No action required" for everyone, and the
 *     reprocess help names the Custom Provider and the media import.
 */
final class DuckDBSettingsViewTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__test_options']    = [];
        $GLOBALS['__test_transients'] = [];
        MxChat_Test_Helpers::reset_schema_memoisation();
        MxChat_Test_Helpers::inject_mock_connection(new MxChat_Test_RecordingConnection('mock:view', [
            'SELECT COUNT(*)' => [['c' => 3]],
        ]));
    }

    private function render(string $partial, array $opts, array $vars = []): string {
        update_option(MXCHAT_PLUS_DUCKDB_OPTION_KEY, $opts);
        $opts = MxChat_Plus_DuckDB_Options::get();
        $proxy_token = 'tok_abcdefghijklmnop';
        $proxy_host  = 'example.test/wp-json/mxchat-plus/v1/pinecone-proxy';
        extract($vars);
        ob_start();
        include MXCHAT_PLUS_DIR . 'admin/views/duckdb/partials/' . $partial;
        return (string) ob_get_clean();
    }

    private function opts(array $patch): array {
        return array_merge(MxChat_Plus_DuckDB_Options::defaults(), $patch);
    }

    public function test_activation_renders_the_routing_choice(): void {
        $opts = $this->opts(['enabled' => true, 'takeover_multibot_bots' => false, 'takeover_bot_ids' => ['sales', 'support']]);
        update_option(MXCHAT_PLUS_DUCKDB_OPTION_KEY, $opts);
        $html = $this->render('section-activation.php', $opts, [
            'bot_routing' => MxChat_Plus_DuckDB_Options::bot_routing(), 'multibot_active' => true,
        ]);

        $this->assertStringContainsString('name="mxchat_plus_duckdb_options[takeover_multibot_bots]"', $html);
        $this->assertStringContainsString('name="mxchat_plus_duckdb_options[takeover_bot_ids]"', $html);
        $this->assertStringContainsString('value="sales, support"', $html);
        $this->assertStringNotContainsString('add-on is not active', $html);
        $this->assertStringNotContainsString('predates the setting', $html);
    }

    public function test_activation_shows_an_older_install_as_routing_every_bot(): void {
        $legacy = MxChat_Plus_DuckDB_Options::defaults();
        unset($legacy['takeover_multibot_bots'], $legacy['takeover_bot_ids']);
        update_option(MXCHAT_PLUS_DUCKDB_OPTION_KEY, $legacy);
        $routing = MxChat_Plus_DuckDB_Options::bot_routing();

        $html = $this->render('section-activation.php', $legacy, ['bot_routing' => $routing, 'multibot_active' => false]);

        $this->assertMatchesRegularExpression('/takeover_multibot_bots\]"\s+value="1"\s+checked/', $html,
            'saving the form as shown must keep what the install does today');
        $this->assertStringContainsString('predates the setting', $html);
        $this->assertStringContainsString('add-on is not active', $html);
    }

    public function test_diagnostics_says_the_default_bot_is_not_served_without_the_takeover(): void {
        $html = $this->render('section-diagnostics.php', $this->opts(['enabled' => true, 'takeover_default_bot_pinecone' => false]));

        $this->assertStringContainsString('Default-bot routing is off', $html);
        $this->assertStringNotContainsString('No action required', $html);
        $this->assertStringContainsString('Custom Provider', $html);
        $this->assertStringContainsString('Import → Media', $html);
    }

    public function test_diagnostics_names_the_takeover_seam_when_it_is_on(): void {
        $html = $this->render('section-diagnostics.php', $this->opts(['enabled' => true, 'takeover_default_bot_pinecone' => true]));

        $this->assertStringContainsString('Default-bot routing is on', $html);
        $this->assertStringContainsString('pre_option_mxchat_pinecone_addon_options', $html);
    }
}

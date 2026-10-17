<?php
/**
 * The Prompt Cache tab / dashboard widget must say WHY there is no data when
 * none can come: MxChat streams the chat through curl_exec, outside the
 * WordPress HTTP API. A plain "No data yet" kept an admin waiting forever.
 */

use PHPUnit\Framework\TestCase;

final class PromptCacheAdminNoticeTest extends TestCase {

    protected function setUp(): void {
        unset($GLOBALS['__test_options']['mxchat_options'], $GLOBALS['__test_options'][MXCHAT_PLUS_PC_STORE_KEY]);
        MxChat_Tool_Registry::$enabled       = false;
        MxChat_Tool_Registry::$enabled_tools = [];
    }

    protected function tearDown(): void {
        $this->setUp();
    }

    private static function render(): string {
        ob_start();
        MxChat_Plus_PromptCache_Admin::render_tab();
        return (string) ob_get_clean();
    }

    public function test_streaming_without_ai_tools_explains_that_no_data_will_come_and_how_to_fix_it(): void {
        $GLOBALS['__test_options']['mxchat_options'] = ['model' => 'claude-sonnet-5', 'enable_streaming_toggle' => 'on'];

        $html = self::render();

        $this->assertStringContainsString('none will come from the chat: MxChat streaming is on', $html);
        $this->assertStringContainsString('page=mxchat-settings', $html, 'links to the host settings screen');
        $this->assertStringContainsString('AI Tools', $html, 'offers the AI Tools alternative');
        $this->assertStringNotContainsString('No data yet. Send one chat message', $html);
    }

    public function test_streaming_with_ai_tools_says_the_tool_loop_will_record_the_first_sample(): void {
        $GLOBALS['__test_options']['mxchat_options'] = [
            'model' => 'claude-sonnet-5', 'claude_api_key' => 'sk-ant-test', 'enable_streaming_toggle' => 'on',
        ];
        MxChat_Tool_Registry::$enabled       = true;
        MxChat_Tool_Registry::$enabled_tools = [['callback' => 'mxchat_search_kb', 'enabled' => true]];

        $html = self::render();

        $this->assertStringContainsString('the first reply records the first sample', $html);
        $this->assertStringNotContainsString('notice-warning', $html);
    }

    public function test_without_streaming_one_message_is_enough(): void {
        $GLOBALS['__test_options']['mxchat_options'] = ['model' => 'claude-sonnet-5', 'enable_streaming_toggle' => 'off'];

        $html = self::render();

        $this->assertStringContainsString('Send one chat message', $html);
        $this->assertStringNotContainsString('notice-warning', $html);
    }
}

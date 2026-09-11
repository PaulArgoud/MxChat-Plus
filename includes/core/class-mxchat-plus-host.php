<?php
/**
 * The host gate.
 *
 * mxchat-plus is a companion to MxChat (mxchat-basic), a third-party plugin it
 * never modifies: every feature here is grafted on through WordPress hooks
 * alone. That is a deliberate constraint — mxchat-basic ships its own updates,
 * and any patch applied to its files would be silently overwritten by the next
 * one.
 *
 * Consequence: without the host active, nothing this plugin does makes sense
 * (no knowledge base to index, no LLM calls to cache). The bootstrap therefore
 * refuses to register a single hook and shows an admin notice instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_Host {

    /** Host version this plugin was developed and verified against. */
    const TESTED_UP_TO = '3.2.21';

    /** Oldest host version whose integration seams we rely on. */
    const MINIMUM = '3.2.5';

    /**
     * Is the host loaded? Checked on `plugins_loaded` at a late priority, so
     * both signals are reliable by then. MxChat exposes the integrator class
     * and a set of global functions; either signal is enough, and accepting
     * both keeps us working if the host reorganises one of them.
     */
    public static function is_active(): bool {
        return class_exists('MxChat_Integrator') || function_exists('mxchat_activate');
    }

    /**
     * Host version, or '' when it cannot be determined. MXCHAT_BASE_VERSION is
     * the header version the host defines for itself; we prefer it over
     * MXCHAT_VERSION, which carries a time() suffix in the host's dev mode and
     * would break any comparison.
     */
    public static function version(): string {
        if (defined('MXCHAT_BASE_VERSION')) {
            return (string) MXCHAT_BASE_VERSION;
        }
        if (defined('MXCHAT_VERSION')) {
            return (string) MXCHAT_VERSION;
        }
        return '';
    }

    /**
     * Does the host already place its own Anthropic prompt-cache breakpoint?
     *
     * Since 3.2.21 mxchat-basic wraps its system prompt in content blocks
     * carrying `cache_control: ephemeral` (MxChat_Integrator::
     * mxchat_anthropic_system_blocks). Anthropic allows at most four
     * breakpoints per request, so the prompt-cache module must detect what the
     * host already marked rather than spend a second breakpoint on the same
     * bytes. The module inspects the outgoing payload for this; the method is
     * here so the admin screen and `doctor` can explain the situation.
     */
    public static function has_native_prompt_cache(): bool {
        // Version-based, deliberately: the host method that does this
        // (mxchat_anthropic_system_blocks) is private, so probing for it says
        // nothing reliable about behaviour. 3.2.21 is the release that
        // introduced it.
        $version = self::version();
        return $version !== '' && version_compare($version, '3.2.21', '>=');
    }

    /**
     * Is the host's streaming mode on? Streaming goes out through curl_exec,
     * which bypasses the WordPress HTTP API entirely — `http_request_args`
     * never fires, so prompt caching cannot be injected on that path. This is
     * the single most common reason for "the cache does nothing".
     */
    public static function streaming_enabled(): bool {
        $options = get_option('mxchat_options', []);
        if (!is_array($options)) {
            return false;
        }
        foreach (['enable_streaming', 'mxchat_enable_streaming'] as $key) {
            if (isset($options[$key])) {
                return !in_array($options[$key], ['0', 0, '', false, 'off'], true);
            }
        }
        return false;
    }

    public static function render_missing_notice(): void {
        echo '<div class="notice notice-error"><p><strong>MxChat Plus</strong>: ';
        echo esc_html__(
            'The MxChat plugin must be installed and activated for MxChat Plus to do anything. MxChat Plus extends MxChat and never modifies it.',
            'mxchat-plus'
        );
        echo '</p></div>';
    }
}

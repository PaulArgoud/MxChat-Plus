<?php
/**
 * Module registry.
 *
 * The features gathered in this plugin are functionally orthogonal — one
 * replaces the vector store, one caches LLM prompts, one exports transcripts,
 * one tracks clicks — and share no state. Keeping them separately switchable
 * means a site that only wants prompt caching never loads the 26 DuckDB
 * classes, and a retrieval problem can be bisected by turning one module off
 * rather than the whole plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_Modules {

    const OPTION = 'mxchat_plus_modules';

    const DUCKDB      = 'duckdb';
    const PROMPTCACHE = 'promptcache';
    const TRANSCRIPTS = 'transcripts';
    const TRACKING    = 'tracking';

    /** Set once the 1.1.0 switch-off of DuckDB has run, so it never runs twice. */
    const DUCKDB_OFF_MARKER = 'mxchat_plus_modules_duckdb_off_110';
    /** Present while the admin has not seen the Modules screen since that switch-off. */
    const DUCKDB_OFF_NOTICE = 'mxchat_plus_modules_duckdb_off_notice';

    /**
     * @return array<string,bool>
     *
     * Every key here must also appear in labels(): the settings screen renders
     * one checkbox per label, and save() writes false for any key whose
     * checkbox was not posted — so a key with no label silently switches
     * itself off the first time the Modules tab is saved.
     */
    public static function defaults(): array {
        return [
            // Off by default since 1.1.0: a vector-store replacement is a
            // deliberate choice, and most sites only want prompt caching.
            self::DUCKDB      => false,
            self::PROMPTCACHE => true,
            self::TRANSCRIPTS => true,
            // Off by default: the only module that puts code on public pages,
            // and the only one that sends data to a third party. Both are
            // decisions for the site owner to make deliberately.
            self::TRACKING    => false,
        ];
    }

    /** @return array<string,bool> */
    public static function all(): array {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $out = [];
        foreach (self::defaults() as $key => $default) {
            $out[$key] = array_key_exists($key, $stored) ? (bool) $stored[$key] : $default;
        }
        return $out;
    }

    public static function is_enabled(string $module): bool {
        $all = self::all();
        return !empty($all[$module]);
    }

    /**
     * @param array<string,mixed> $modules
     */
    public static function save(array $modules): void {
        $clean = [];
        foreach (self::defaults() as $key => $default) {
            $clean[$key] = !empty($modules[$key]);
        }
        update_option(self::OPTION, $clean, true);
        // The admin has now seen the Modules screen and made a choice.
        delete_option(self::DUCKDB_OFF_NOTICE);
    }

    /**
     * One-time 1.1.0 upgrade step: switches the DuckDB module off on installs
     * that had it on, since it is no longer on by default. Runs at most once
     * (marker option), so an admin who turns DuckDB back on keeps it on.
     * Leaves a notice flag when it actually changed something: a site that
     * served MxChat from DuckDB now answers from MxChat's own store again.
     */
    public static function switch_off_duckdb_once(): void {
        if (get_option(self::DUCKDB_OFF_MARKER)) {
            return;
        }
        $stored = get_option(self::OPTION, null);
        if (is_array($stored) && !empty($stored[self::DUCKDB])) {
            $stored[self::DUCKDB] = false;
            update_option(self::OPTION, $stored, true);
            update_option(self::DUCKDB_OFF_NOTICE, '1', false);
        }
        update_option(self::DUCKDB_OFF_MARKER, '1', false);
    }

    /** Admin notice left by switch_off_duckdb_once(), until the Modules screen is saved. */
    public static function render_duckdb_off_notice(): void {
        if (!get_option(self::DUCKDB_OFF_NOTICE) || !current_user_can('manage_options')) {
            return;
        }
        $url = add_query_arg(['page' => MxChat_Plus_Admin::MENU_SLUG, 'tab' => 'modules'], admin_url('admin.php'));
        printf(
            '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__('MxChat Plus 1.1.0 turned the DuckDB / MotherDuck vector store module off: it is no longer on by default. MxChat now answers from its own knowledge store again. If you used DuckDB, turn the module back on.', 'mxchat-plus'),
            esc_url($url),
            esc_html__('Open the Modules screen', 'mxchat-plus')
        );
    }

    public static function install_defaults(): void {
        if (get_option(self::OPTION, null) === null) {
            update_option(self::OPTION, self::defaults(), true);
        }
    }

    /** @return array<string,string> module key => human label */
    public static function labels(): array {
        return [
            self::DUCKDB      => __('DuckDB / MotherDuck vector store', 'mxchat-plus'),
            self::PROMPTCACHE => __('Anthropic prompt cache', 'mxchat-plus'),
            self::TRANSCRIPTS => __('CSV export of selected transcripts', 'mxchat-plus'),
            self::TRACKING    => __('Link and suggestion click tracking', 'mxchat-plus'),
        ];
    }
}

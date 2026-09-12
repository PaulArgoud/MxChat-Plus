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
            self::DUCKDB      => true,
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

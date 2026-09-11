<?php
/**
 * Module registry.
 *
 * The two features merged into this plugin are functionally orthogonal — one
 * replaces the vector store, the other caches LLM prompts — and share no state.
 * Keeping them separately switchable means a site that only wants prompt
 * caching never loads the 27 DuckDB classes, and a retrieval problem can be
 * bisected by turning one module off rather than the whole plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_Modules {

    const OPTION = 'mxchat_plus_modules';

    const DUCKDB      = 'duckdb';
    const PROMPTCACHE = 'promptcache';
    const TRANSCRIPTS = 'transcripts';

    /** @return array<string,bool> */
    public static function defaults(): array {
        return [
            self::DUCKDB      => true,
            self::PROMPTCACHE => true,
            self::TRANSCRIPTS => true,
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
        ];
    }
}

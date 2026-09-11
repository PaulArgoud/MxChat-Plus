<?php
/**
 * Per-bot query-cache generation counters.
 *
 * Extracted from the former standalone plugin bootstrap (MxChat_DuckDB_Plugin)
 * when the two plugins merged into mxchat-plus: the bootstrap file disappeared,
 * but these counters are hot-path state that Vector_Store_Query, the compactor,
 * the sync pipelines and the CLI all depend on.
 *
 * The option holds a map `{ bot_id => int }`; each bot's counter is woven into
 * its query-cache keys by Vector_Store_Query. A write to one bot bumps only
 * that bot's counter, so a sync on bot A no longer invalidates bot B's cached
 * top-Ks. Reads are O(1); orphaned keys left behind by a bump are reclaimed by
 * the compactor sweep (per-bot) and by TTL.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Cache {

    const CACHE_GEN_OPTION = 'mxchat_plus_duckdb_cache_gen';

    /**
     * @return array<string,int>
     */
    public static function cache_generation_map(): array {
        $map = get_option(self::CACHE_GEN_OPTION, []);
        // Back-compat: the option used to hold a single global int. A scalar is
        // treated as "no per-bot data yet" — every bot starts fresh at 1, and
        // the old global-style transient keys lapse via TTL.
        return is_array($map) ? $map : [];
    }

    /**
     * Current cache generation for a bot (default 1). Read on the hot path by
     * Vector_Store_Query to compose the transient key.
     */
    public static function cache_generation(string $bot_id = 'default'): int {
        $map = self::cache_generation_map();
        $g = isset($map[$bot_id]) ? (int) $map[$bot_id] : 1;
        return $g > 0 ? $g : 1;
    }

    /**
     * Bump a single bot's generation, making that bot's existing cached
     * results unreachable in O(1) (no LIKE DELETE over wp_options).
     */
    public static function bump_cache_generation(string $bot_id = 'default'): void {
        $map = self::cache_generation_map();
        $map[$bot_id] = self::cache_generation($bot_id) + 1;
        update_option(self::CACHE_GEN_OPTION, $map, false);
    }

    /**
     * Invalidate cached queries. With a $bot_id, scoped to that bot. Without
     * one (a broad change — full resync, Parquet import), bumps every tracked
     * bot in a single write; if nothing is tracked yet, seeds 'default' so the
     * flush is still observable.
     */
    public static function flush_query_cache(?string $bot_id = null): void {
        if ($bot_id !== null) {
            self::bump_cache_generation($bot_id);
            return;
        }
        $map = self::cache_generation_map();
        if ($map === []) {
            self::bump_cache_generation('default');
            return;
        }
        foreach ($map as $bot => $g) {
            $cur = (int) $g;
            $map[$bot] = ($cur > 0 ? $cur : 1) + 1;
        }
        update_option(self::CACHE_GEN_OPTION, $map, false);
    }
}

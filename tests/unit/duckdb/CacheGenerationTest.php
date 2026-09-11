<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the O(1), per-bot cache-invalidation scheme.
 *
 * Writes used to issue a `DELETE … LIKE '_transient_mxp_q_%'` on wp_options.
 * The path now bumps a PER-BOT Plugin::cache_generation($bot) instead, and
 * Vector_Store_Query weaves both a bot-key segment and that bot's generation
 * into the transient key — `mxp_q_<botkey>_<gen>_<hash>`. A write to one bot
 * invalidates only that bot; the compactor sweep reclaims stale per-bot keys.
 *
 * These tests cover the contract from both ends:
 *   - cache_key() emits `mxp_q_<botkey>_<gen>_<hash>` and isolates bots
 *   - Plugin::bump_cache_generation($bot) changes only that bot's generation
 *     (the test bootstrap stubs Plugin — see tests/shims/mxchat.php)
 */
final class CacheGenerationTest extends TestCase {

    protected function setUp(): void {
        // The per-bot generation map is a static on the Plugin stub; reset it
        // so flush/bump assertions are deterministic regardless of test order.
        MxChat_Plus_DuckDB_Cache::$cache_gen_map = [];
    }

    private static function callKey(array $args) {
        $r = new ReflectionMethod(MxChat_Plus_DuckDB_Vector_Store_Query::class, 'cache_key');
        return $r->invokeArgs(null, $args);
    }

    public function test_cache_key_has_botkey_and_generation_segments(): void {
        // gen=0 is rendered as 1 (the floor), botkey for 'default' is verbatim.
        $key = self::callKey([[0.1, 0.2], 10, 'default', []]);
        $this->assertMatchesRegularExpression('/^mxp_q_default_1_[a-f0-9]{32}$/', $key);
    }

    public function test_cache_key_with_gen_carries_botkey_and_generation_prefix(): void {
        $key = self::callKey([[0.1, 0.2], 10, 'default', [], 7]);
        $this->assertMatchesRegularExpression('/^mxp_q_default_7_[a-f0-9]{32}$/', $key);
    }

    public function test_cache_key_isolates_bots(): void {
        // Same embedding/top_k/filter/gen, different bot → different key, so a
        // bump on one bot can't collide with another bot's cached entry.
        $emb = [0.1, 0.2, 0.3];
        $a = self::callKey([$emb, 10, 'support_fr', [], 5]);
        $b = self::callKey([$emb, 10, 'sales_en', [], 5]);
        $this->assertNotSame($a, $b);
        $this->assertStringContainsString('mxp_q_support_fr_5_', $a);
        $this->assertStringContainsString('mxp_q_sales_en_5_', $b);
    }

    public function test_bot_key_sanitises_unusual_ids(): void {
        // Safe ids pass through; anything outside [A-Za-z0-9_-]{1,40} is hashed
        // (with an 'h' guard so a hashed id can't collide with a literal one).
        $this->assertSame('support_fr', MxChat_Plus_DuckDB_Vector_Store_Query::bot_key('support_fr'));
        $weird = MxChat_Plus_DuckDB_Vector_Store_Query::bot_key("héllo/bot\n<x>");
        $this->assertMatchesRegularExpression('/^h[a-f0-9]{12}$/', $weird);
        // A 41-char id exceeds the verbatim bound and is hashed too.
        $this->assertMatchesRegularExpression('/^h[a-f0-9]{12}$/',
            MxChat_Plus_DuckDB_Vector_Store_Query::bot_key(str_repeat('a', 41)));
    }

    public function test_cache_key_changes_when_generation_changes(): void {
        $emb = [0.1, 0.2];
        $a = self::callKey([$emb, 10, 'default', [], 1]);
        $b = self::callKey([$emb, 10, 'default', [], 2]);
        $this->assertNotSame($a, $b, 'bumping generation must produce a new key');
    }

    public function test_cache_key_stable_within_same_generation(): void {
        $emb = [0.1, 0.2, 0.3, 0.4];
        $a = self::callKey([$emb, 5, 'bot_x', ['type' => ['$eq' => 'post']], 42]);
        $b = self::callKey([$emb, 5, 'bot_x', ['type' => ['$eq' => 'post']], 42]);
        $this->assertSame($a, $b);
    }

    public function test_cache_key_uses_packed_floats_not_strval(): void {
        // 0.1 + 0.2 in pack('g*') round-trips through float32, but two distinct
        // values still produce distinct bytes — make sure the new path doesn't
        // silently collide for trivially-different embeddings.
        $a = self::callKey([[0.1, 0.2, 0.3], 10, 'd', []]);
        $b = self::callKey([[0.1, 0.2, 0.31], 10, 'd', []]);
        $this->assertNotSame($a, $b);
    }

    public function test_plugin_bump_changes_reported_generation(): void {
        $start = MxChat_Plus_DuckDB_Cache::cache_generation();
        MxChat_Plus_DuckDB_Cache::bump_cache_generation();
        $this->assertGreaterThan($start, MxChat_Plus_DuckDB_Cache::cache_generation());
    }

    public function test_plugin_flush_alias_still_bumps_generation(): void {
        // flush_query_cache() is kept as a back-compat shim that bumps the
        // generation; tests on Vector_Store::upsert() rely on the count of
        // flushes, so the legacy contract must keep ticking up.
        $before_gen = MxChat_Plus_DuckDB_Cache::cache_generation();
        $before_flushes = count(MxChat_Plus_DuckDB_Cache::$flushed);
        MxChat_Plus_DuckDB_Cache::flush_query_cache();
        $this->assertCount($before_flushes + 1, MxChat_Plus_DuckDB_Cache::$flushed);
        $this->assertGreaterThan($before_gen, MxChat_Plus_DuckDB_Cache::cache_generation());
    }
}

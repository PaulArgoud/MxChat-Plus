<?php
/**
 * Compactor: nightly job that prunes orphan vectors.
 *
 * A vector is orphan when its id no longer maps to any row in
 * wp_mxchat_system_prompt_content (post deleted in WP, mxchat row removed,
 * cascade-delete missed for any reason). That test only means something for
 * bots whose DuckDB rows the MySQL sync wrote. Bots the host writes through
 * the Pinecone proxy have NO MySQL rows at all — in Pinecone mode MxChat
 * stores straight to the vector store (MxChat_Utils::submit_content_to_db()
 * → store_in_pinecone_only(), MxChat 3.2.25) — so every one of their vectors
 * would look orphaned. Those bots are never pruned (protected_bots()); the
 * host deletes their vectors itself through /vectors/delete.
 *
 * The compactor is conservative:
 *   - it never runs before a first MySQL sync (last_sync_at = 0) nor within
 *     an hour of the last one (the sync may be mid-transit);
 *   - it refuses (and records last_error) when the alive set is empty while
 *     prunable rows exist, or when the orphans exceed a fraction of the rows
 *     scanned (filter `mxchat_plus_duckdb_compactor_max_orphan_ratio`,
 *     default 0.5) — `wp mxchat-plus duckdb compact --force` overrides both;
 *   - it caps the per-run delete count (filter
 *     `mxchat_plus_duckdb_compactor_max_deletes`, default 5000) to avoid
 *     blowing up MotherDuck billing on a misconfigured install.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Compactor {

    private static ?self $instance = null;
    const CRON_HOOK = 'mxchat_plus_duckdb_compact';
    const MIN_SYNC_AGE_SECONDS = 3600;

    /**
     * Durable record of the bots the host has written through the Pinecone
     * proxy: {bots: {bot_id: first_seen_unix}, seeded_at: unix}. Written by
     * note_proxy_write() (once per bot, ever) and seeded once for installs
     * that wrote through the proxy before this record existed. Never pruned.
     */
    const PROXY_WRITTEN_OPTION = 'mxchat_plus_duckdb_proxy_written_bots';

    /** Default of `mxchat_plus_duckdb_compactor_max_orphan_ratio`. */
    const DEFAULT_MAX_ORPHAN_RATIO = 0.5;

    /** Below this many orphans the ratio safety valve does not apply. */
    const RATIO_MIN_ORPHANS = 10;

    /**
     * Host literal: the content type MxChat's media auto-sync indexes
     * attachments under (submit_content_to_db(…, 'default', 'attachment'),
     * MxChat 3.2.25) — the only entries its attachment signature vouches for.
     */
    const ATTACHMENT_CONTENT_TYPE = 'attachment';

    /** @var array<string, true> per-request memo of note_proxy_write() */
    private static array $noted = [];

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function register_hooks(): void {
        // Wrap rather than hook run() directly: actions ignore return values
        // but run() returns a useful summary for tests + AJAX. The wrapper
        // makes the action's void contract explicit (PHPStan-WP enforces it).
        add_action(self::CRON_HOOK, [$this, 'run_as_action']);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(self::next_run_timestamp(), 'daily', self::CRON_HOOK);
        }
    }

    public function run_as_action(): void {
        $this->run();
    }

    /**
     * Anchor the first run at 03:00 UTC + a deterministic per-install jitter
     * (0–59 min, derived from home_url()) so:
     *   - we land in a low-traffic window (~04:00–05:00 in most EU timezones);
     *   - many installs running this plugin don't all hit MotherDuck at the
     *     same UTC minute, which would otherwise look like a coordinated load
     *     spike to the upstream;
     *   - the schedule is stable across activations on the same site (jitter
     *     is hashed from a value that doesn't change).
     */
    private static function next_run_timestamp(): int {
        $jitter_minutes = abs(crc32(home_url())) % 60;
        $base = strtotime('today 03:00 UTC');
        if ($base === false) $base = time();
        $first = $base + ($jitter_minutes * MINUTE_IN_SECONDS);
        return $first > time() ? $first : $first + DAY_IN_SECONDS;
    }

    /**
     * @param bool $force bypass the two safety valves (empty alive set,
     *                    orphan ratio) — never the protected bots nor the
     *                    sync-freshness guards. Used by `compact --force`.
     */
    public function run(bool $force = false): array {
        $opts = MxChat_Plus_DuckDB_Options::get();
        if (empty($opts['enabled'])) {
            return ['ok' => false, 'reason' => 'plugin disabled', 'deleted' => 0, 'cache_swept' => 0];
        }

        // Sweep stale query-cache transients FIRST — before the sync-freshness
        // guard. A write-heavy install syncs often, so it would early-return on
        // "last sync too recent" every day and never reach the orphan-vector
        // pass; that's exactly the install whose superseded-generation cache
        // transients pile up the fastest, so the sweep must not be gated behind
        // the freshness floor. It touches only wp_options (MySQL), never the
        // DuckDB backend.
        $cache_swept = $this->sweep_stale_query_cache();

        // No MySQL sync has ever completed: the alive set would be empty and
        // every vector an "orphan". (This used to skip the freshness guard
        // entirely, so the first hour after enabling was the riskiest one.)
        if (empty($opts['last_sync_at'])) {
            return ['ok' => false, 'reason' => 'never synced', 'deleted' => 0, 'cache_swept' => $cache_swept];
        }
        if ((time() - (int) $opts['last_sync_at']) < self::MIN_SYNC_AGE_SECONDS) {
            return ['ok' => false, 'reason' => 'last sync too recent', 'deleted' => 0, 'cache_swept' => $cache_swept];
        }

        $max = (int) apply_filters('mxchat_plus_duckdb_compactor_max_deletes', 5000);
        $deleted = 0;

        try {
            $deleted = $this->prune_orphans($max, $force);
            MxChat_Plus_DuckDB_Options::update([
                'last_compact_at' => time(),
                'last_error'      => '',
            ]);
            return ['ok' => true, 'deleted' => $deleted, 'cache_swept' => $cache_swept];
        } catch (\Throwable $e) {
            error_log('[mxchat-plus] compactor: ' . $e->getMessage());
            MxChat_Plus_DuckDB_Options::update(['last_error' => 'compactor: ' . $e->getMessage()]);
            return ['ok' => false, 'reason' => $e->getMessage(), 'deleted' => $deleted, 'cache_swept' => $cache_swept];
        }
    }

    /** Cap on bots processed per sweep — guards a pathological bot count. */
    const SWEEP_MAX_BOTS = 200;

    /** Ids per DELETE … IN (…), sized to stay under MotherDuck HTTP body limits. */
    const DELETE_CHUNK_SIZE = 100;

    /**
     * Delete leftover query-cache transients from superseded cache generations.
     *
     * The query cache invalidates in O(1) by bumping a per-bot generation
     * counter (MxChat_Plus_DuckDB_Cache::bump_cache_generation), which makes that
     * bot's prior-generation transients unreachable but does NOT delete them —
     * they linger in wp_options until TTL. On a write-heavy install (frequent
     * upserts → frequent bumps) those orphaned `_transient_mxp_q_<bot>_<oldgen>_*`
     * rows accumulate faster than WordPress' own expired-transient GC reclaims
     * them. This sweep walks the per-bot generation map and, for each bot that
     * has been bumped, deletes every one of its query-cache transients EXCEPT
     * the current generation. Bots never bumped (still at gen 1) aren't in the
     * map and aren't touched — their current keys stay intact.
     *
     * No-op when an external object cache (Redis/Memcached) is in use, since
     * transients don't live in wp_options then — the DELETE simply matches
     * nothing. Patterns mirror the LIKE-escaping convention in uninstall.php:
     * `_` is a LIKE wildcard, so every literal underscore (fixed prefix AND the
     * bot-key segment) is escaped via `ESCAPE '\'`. Legacy global-style keys
     * (`mxp_q_<int>_*`, pre-v0.12.1) carry no bot segment and aren't matched
     * here — they lapse via TTL within one window after upgrade.
     *
     * @return int rows deleted (value + timeout rows combined)
     */
    private function sweep_stale_query_cache(): int {
        global $wpdb;
        // (No is_object() guard: $wpdb is the always-present WP global — the
        // is_object narrowing would erase its `wpdb` type and break the method
        // calls below under PHPStan level 7. The rest of the compactor uses
        // $wpdb directly too.)
        if (!class_exists('MxChat_Plus_DuckDB_Cache') || !class_exists('MxChat_Plus_DuckDB_Vector_Store_Query')) {
            return 0;
        }

        $map = MxChat_Plus_DuckDB_Cache::cache_generation_map();
        if (empty($map)) {
            return 0; // no bot has ever been bumped → nothing superseded
        }

        // Prefix comes from the class that writes the keys
        // (Vector_Store_Query::CACHE_KEY_PREFIX), already LIKE-escaped —
        // re-spelling it here is how this sweep silently stopped matching.
        $key_prefix = MxChat_Plus_DuckDB_Vector_Store_Query::cache_key_like_prefix();

        $deleted   = 0;
        $processed = 0;
        foreach ($map as $bot_id => $gen) {
            if ($processed++ >= self::SWEEP_MAX_BOTS) break;
            $gen = (int) $gen;
            if ($gen < 1) $gen = 1;

            // ASCII-safe bot-key segment (same rendering used in the cache key),
            // with its literal underscores LIKE-escaped.
            $bk     = MxChat_Plus_DuckDB_Vector_Store_Query::bot_key((string) $bot_id);
            $bk_esc = str_replace('_', '\_', $bk);

            foreach (['\_transient\_' . $key_prefix, '\_transient\_timeout\_' . $key_prefix] as $prefix) {
                $all     = $prefix . $bk_esc . '\_';        // every gen for this bot
                $current = $all . $gen . '\_';              // the live generation
                $deleted += (int) $wpdb->query(
                    "DELETE FROM {$wpdb->options} " .
                    "WHERE option_name LIKE '{$all}%' ESCAPE '\\\\' " .
                    "AND option_name NOT LIKE '{$current}%' ESCAPE '\\\\'"
                );
            }
        }
        return $deleted;
    }

    const KB_PAGE_SIZE = 5000;

    /** Rows per keyset page of the DuckDB scan. */
    const SCAN_PAGE_SIZE = 1000;

    /**
     * Strategy: build a set of "alive" vector_ids by paging through the MySQL
     * KB (avoids one ~20 MB allocation for big KBs — PHP can free each batch's
     * row objects as we move on, keeping only the much smaller id-only map
     * around). Then scan the DuckDB rows of the prunable bots (keyset on
     * (vector_id, bot_id)), collect the orphans, run the safety valves, and
     * only then delete — per bot, chunked to 100 ids to stay under
     * MotherDuck HTTP body limits.
     *
     * @throws RuntimeException when a safety valve refuses the run.
     */
    private function prune_orphans(int $max_deletes, bool $force = false): int {
        if ($max_deletes <= 0 || !self::orphan_pruning_allowed()) {
            return 0;
        }

        $store = new MxChat_Plus_DuckDB_Vector_Store();
        $store->ensure_schema();
        $conn  = $store->connection();
        $table = $store->table_name_quoted();

        self::maybe_seed_proxy_written_bots($store);
        $protected = self::protected_bots();
        // With the Multi-Bot add-on, a non-default bot may read and write
        // through the proxy (inject_pinecone_config answers
        // mxchat_get_bot_pinecone_config for the bots opted in under
        // Multi-Bot routing, and installs from before that setting routed
        // every bot), so only the default bot is ever pruned: leaving a
        // non-default bot's orphans alone is the conservative side.
        $default_only = class_exists('MxChat_Multi_Bot_Manager');
        if ($default_only && in_array('default', $protected, true)) {
            return 0;
        }

        $scope = [];
        if ($protected !== []) {
            $scope[] = sprintf('bot_id NOT IN (%s)', implode(',', array_map([self::class, 'sql_literal'], $protected)));
        }
        if ($default_only) {
            $scope[] = "bot_id = 'default'";
        }

        $alive = $this->load_alive_ids();

        // Keyset (seek) cursor on (vector_id, bot_id), NOT an OFFSET, and
        // NOT vector_id alone: the same md5(url) id exists once per bot
        // (schema v4), so a cursor on vector_id would skip the other bots'
        // copy at a page boundary.
        $orphans  = [];   // list of [bot, id, source_url, content_type]
        $scanned  = 0;
        $orphan_total = 0;
        $cursor_id  = null;
        $cursor_bot = '';
        while (true) {
            $where = $scope;
            if ($cursor_id !== null) {
                $id_lit = self::sql_literal($cursor_id);
                $where[] = sprintf('(vector_id > %1$s OR (vector_id = %1$s AND bot_id > %2$s))', $id_lit, self::sql_literal($cursor_bot));
            }
            $page = $conn->execute(sprintf(
                'SELECT vector_id, bot_id, source_url, content_type FROM %s%s ORDER BY vector_id, bot_id LIMIT %d',
                $table,
                $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
                self::SCAN_PAGE_SIZE
            ));
            if (empty($page)) {
                break;
            }

            foreach ($page as $row) {
                $id = (string) ($row['vector_id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $bot = (string) ($row['bot_id'] ?? '');
                $bot = $bot !== '' ? $bot : 'default';
                $cursor_id  = $id;
                $cursor_bot = $bot;
                if (in_array($bot, $protected, true) || ($default_only && $bot !== 'default')) {
                    continue; // belt and braces: never prune a protected bot
                }
                $scanned++;
                if (!isset($alive[$id])) {
                    $orphan_total++;
                    if (count($orphans) < $max_deletes) {
                        $orphans[] = [$bot, $id, (string) ($row['source_url'] ?? ''), (string) ($row['content_type'] ?? '')];
                    }
                }
            }

            if (count($page) < self::SCAN_PAGE_SIZE) {
                break; // short page → end of table
            }
        }

        if (!$force) {
            $this->assert_safe_to_prune($alive, $scanned, $orphan_total);
        }

        // Delete per bot, at most DELETE_CHUNK_SIZE ids per statement. The
        // collection loop above already bounded $orphans at $max_deletes.
        $by_bot = [];
        foreach ($orphans as $o) {
            $by_bot[$o[0]][] = $o;
        }
        $deleted = 0;
        foreach ($by_bot as $bot => $rows) {
            foreach (array_chunk($rows, self::DELETE_CHUNK_SIZE) as $chunk) {
                $deleted += $this->delete_chunk($conn, $table, (string) $bot, array_map(fn($o) => (string) $o[1], $chunk));
                $this->forget_attachment_signatures((string) $bot, $chunk);
            }
            // The rows are gone: cached top-Ks of this bot must stop serving them.
            MxChat_Plus_DuckDB_Cache::flush_query_cache((string) $bot);
        }

        return $deleted;
    }

    /**
     * Safety valves. Throw (the caller records last_error) instead of
     * deleting when the alive set looks wrong.
     *
     * @param array<string, true> $alive
     */
    private function assert_safe_to_prune(array $alive, int $scanned, int $orphan_total): void {
        if ($scanned > 0 && $alive === []) {
            throw new RuntimeException(sprintf(
                'refused to prune: the MySQL knowledge base is empty while %d vectors would be deleted. Run `wp mxchat-plus duckdb compact --force` if that is intended.',
                $orphan_total
            ));
        }
        $ratio = (float) apply_filters('mxchat_plus_duckdb_compactor_max_orphan_ratio', self::DEFAULT_MAX_ORPHAN_RATIO);
        if ($scanned > 0 && $orphan_total > self::RATIO_MIN_ORPHANS && $ratio < 1.0
            && ($orphan_total / $scanned) > max(0.0, $ratio)) {
            throw new RuntimeException(sprintf(
                'refused to prune: %d of %d scanned vectors look orphaned (more than %d%%). Run `wp mxchat-plus duckdb compact --force` if that is intended.',
                $orphan_total,
                $scanned,
                (int) round(max(0.0, $ratio) * 100)
            ));
        }
    }

    /**
     * Whether the nightly sweep may delete vectors that have no matching row
     * in the MySQL knowledge base.
     *
     * It may not, once vectors have been imported straight from Pinecone. The
     * migrator preserves Pinecone's own vector ids, which need not follow the
     * md5(url)[_chunk_N] convention the sync writes — so those vectors look
     * like orphans to this sweep and would be deleted the very night after a
     * migration that was meant to avoid re-embedding them. The marker is set
     * by the migrator and survives the completion of the run.
     *
     * Sites that know their imported ids do match can re-enable pruning:
     *   add_filter('mxchat_plus_duckdb_compactor_prune_orphans', '__return_true');
     *
     * Bots written through the proxy are excluded separately, whatever this
     * returns (protected_bots()).
     */
    public static function orphan_pruning_allowed(): bool {
        $imported = get_option(MxChat_Plus_DuckDB_Pinecone_Migrator::IMPORTED_MARKER_OPTION, null);
        $allowed  = !is_array($imported) || empty($imported['copied']);

        return (bool) apply_filters('mxchat_plus_duckdb_compactor_prune_orphans', $allowed, $imported);
    }

    // ─── Proxy-written bots ─────────────────────────────────────────────

    /**
     * Record that the host wrote $bot_id's vectors through the Pinecone
     * proxy, so the compactor never treats them as orphans. Cheap on the hot
     * path: one per-request memo check, one option read the first time per
     * request, and an option write only the first time ever for a bot.
     * Called by the REST proxy's /vectors/upsert handler after a successful
     * write.
     */
    public static function note_proxy_write(string $bot_id): void {
        $bot_id = $bot_id !== '' ? $bot_id : 'default';
        if (isset(self::$noted[$bot_id])) {
            return;
        }
        self::$noted[$bot_id] = true;
        $state = self::proxy_written_state();
        if (isset($state['bots'][$bot_id])) {
            return;
        }
        $state['bots'][$bot_id] = time();
        update_option(self::PROXY_WRITTEN_OPTION, $state, false);
    }

    /**
     * Bots recorded as proxy-written (durable), ascending.
     *
     * @return list<string>
     */
    public static function proxy_written_bots(): array {
        $bots = array_map('strval', array_keys(self::proxy_written_state()['bots']));
        sort($bots);
        return $bots;
    }

    /**
     * Every bot the sweep must leave alone: the recorded proxy-written bots,
     * plus 'default' while the default-bot takeover is on (its writes go
     * through the proxy whether or not one has been recorded yet).
     *
     * @return list<string>
     */
    public static function protected_bots(): array {
        $bots = self::proxy_written_bots();
        $opts = MxChat_Plus_DuckDB_Options::get();
        if (!empty($opts['takeover_default_bot_pinecone']) && !in_array('default', $bots, true)) {
            $bots[] = 'default';
        }
        sort($bots);
        return $bots;
    }

    /**
     * One-time seed for installs that wrote through the proxy before
     * note_proxy_write() existed: 'default' when the takeover is on, and
     * every non-default bot already in the store. Before opt-in Multi-Bot
     * routing, inject_pinecone_config answered mxchat_get_bot_pinecone_config
     * for every non-default bot, so the host wrote those bots through the
     * proxy and their vectors have no MySQL row to be "alive" against; a
     * missed cleanup is cheap, a deleted knowledge base is not.
     */
    private static function maybe_seed_proxy_written_bots(MxChat_Plus_DuckDB_Vector_Store $store): void {
        $state = self::proxy_written_state();
        if (!empty($state['seeded_at'])) {
            return;
        }
        $now  = time();
        $opts = MxChat_Plus_DuckDB_Options::get();
        if (!empty($opts['takeover_default_bot_pinecone']) && !isset($state['bots']['default'])) {
            $state['bots']['default'] = $now;
        }
        try {
            foreach ($store->list_bot_ids(1000) as $bot) {
                if ($bot !== 'default' && !isset($state['bots'][$bot])) {
                    $state['bots'][$bot] = $now;
                }
            }
        } catch (\Throwable $e) {
            // Unreadable store: do not record the seed, retry next run.
            throw new RuntimeException('could not list the bots in the vector store before pruning: ' . $e->getMessage(), 0, $e);
        }
        $state['seeded_at'] = $now;
        update_option(self::PROXY_WRITTEN_OPTION, $state, false);
    }

    /**
     * @return array{bots: array<string, int>, seeded_at: int}
     */
    private static function proxy_written_state(): array {
        $raw = get_option(self::PROXY_WRITTEN_OPTION, []);
        $bots = [];
        if (is_array($raw) && isset($raw['bots']) && is_array($raw['bots'])) {
            foreach ($raw['bots'] as $bot => $ts) {
                if ((string) $bot !== '') $bots[(string) $bot] = (int) $ts;
            }
        }
        return [
            'bots'      => $bots,
            'seeded_at' => is_array($raw) ? (int) ($raw['seeded_at'] ?? 0) : 0,
        ];
    }

    /** Test hook: drop the per-request note_proxy_write() memo. */
    public static function reset_noted_cache(): void {
        self::$noted = [];
    }

    // ─── Delete + side effects ──────────────────────────────────────────

    /**
     * @param list<string> $ids
     */
    private function delete_chunk(MxChat_Plus_DuckDB_Connection $conn, string $quoted_table, string $bot_id, array $ids): int {
        if ($ids === []) {
            return 0;
        }
        $conn->execute(sprintf(
            'DELETE FROM %s WHERE bot_id = %s AND vector_id IN (%s)',
            $quoted_table,
            self::sql_literal($bot_id),
            implode(',', array_map([self::class, 'sql_literal'], $ids))
        ));
        return count($ids);
    }

    /**
     * MxChat 3.2.22+ skips re-embedding an attachment whose signature
     * (`_mxchat_kb_attachment_sig`) is unchanged, and clears that signature
     * itself whenever IT deletes the entry (delete_chunks_for_url() →
     * forget_attachment_signature(), MxChat 3.2.24). A deletion it did not
     * make must clear it too, or the file is never re-indexed. Skipped when
     * the default bot's real store is an external Pinecone index (takeover
     * off): our copy was not authoritative there, and the signature still
     * vouches for the real entry.
     *
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $rows
     */
    private function forget_attachment_signatures(string $bot_id, array $rows): void {
        if (!is_callable(['MxChat_Utils', 'forget_attachment_signature'])) {
            return; // host older than 3.2.24
        }
        if ($bot_id === 'default' && self::default_bot_on_external_pinecone()) {
            return;
        }
        $seen = [];
        foreach ($rows as $o) {
            $url = $o[2];
            if ($o[3] !== self::ATTACHMENT_CONTENT_TYPE || $url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            try {
                MxChat_Utils::forget_attachment_signature($url, $bot_id);
            } catch (\Throwable $e) {
                error_log('[mxchat-plus] compactor: could not clear the attachment signature of ' . $url . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Host literal: mxchat_pinecone_addon_options / mxchat_use_pinecone. With
     * the takeover off nothing of ours filters that option, so it reflects
     * the site's real Pinecone settings.
     */
    private static function default_bot_on_external_pinecone(): bool {
        $opts = MxChat_Plus_DuckDB_Options::get();
        if (!empty($opts['takeover_default_bot_pinecone'])) {
            return false;
        }
        $pc = get_option('mxchat_pinecone_addon_options', []);
        return is_array($pc)
            && (string) ($pc['mxchat_use_pinecone'] ?? '') === '1'
            && !empty($pc['mxchat_pinecone_api_key']);
    }

    private static function sql_literal(string $value): string {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Stream mxchat KB rows in pages so a 100k-row KB doesn't allocate ~20 MB
     * of $wpdb objects in one shot. Returns a vector_id → true map (a few MB
     * even at 100k entries — orders of magnitude smaller than the row objects).
     *
     * The alive set MUST be derived with exactly the same chunk logic as the
     * write path (MxChat_Plus_DuckDB_Mysql_Sync::row_to_vector →
     * parse_chunk_prefix + vector_id_for_row), otherwise every chunked row's
     * id collapses to bare md5(url) while DuckDB holds `md5(url)_chunk_N` —
     * no chunk would ever match the alive set and this job would delete the
     * entire chunked KB, max_deletes rows per night, silently.
     *
     * We do NOT select `article_content` itself: on a chunked KB that is the
     * whole corpus and would defeat the paging that keeps this method's memory
     * flat. Instead MySQL returns only the chunk header — everything up to and
     * including the `\n---\n` separator — and an empty string for non-chunked
     * rows (LOCATE() yields 0 there, so LEFT(..., 4) can never look like a
     * chunk header to parse_chunk_prefix()).
     *
     * @return array<string, true>
     */
    private function load_alive_ids(): array {
        global $wpdb;
        $kb = $wpdb->prefix . 'mxchat_system_prompt_content';

        // `{"document_type"` is 16 bytes; the separator is 5 bytes ("\n---\n"),
        // so header+separator = LOCATE(sep) - 1 + 5 = LOCATE(sep) + 4.
        $chunk_prefix_expr =
            "CASE WHEN LEFT(article_content, 16) = '{\"document_type\"' " .
            "THEN LEFT(article_content, LOCATE(CONCAT(CHAR(10), '---', CHAR(10)), article_content) + 4) " .
            "ELSE '' END AS chunk_prefix";

        $alive = [];
        $offset = 0;
        while (true) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, url AS source_url, {$chunk_prefix_expr} FROM {$kb} ORDER BY id LIMIT %d OFFSET %d",
                self::KB_PAGE_SIZE,
                $offset
            ));
            if ($rows === null) {
                throw new RuntimeException('MySQL KB table unreadable; aborting compaction.');
            }
            if (empty($rows)) break;
            foreach ($rows as $r) {
                $chunk_meta = MxChat_Plus_DuckDB_Mysql_Sync::parse_chunk_prefix(
                    (string) ($r->chunk_prefix ?? '')
                );
                $alive[MxChat_Plus_DuckDB_Mysql_Sync::vector_id_for_row($r, $chunk_meta)] = true;
            }
            $offset += self::KB_PAGE_SIZE;
            unset($rows); // let PHP reclaim the batch before fetching the next
        }
        return $alive;
    }
}

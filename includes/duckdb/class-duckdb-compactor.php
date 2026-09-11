<?php
/**
 * Compactor: nightly job that prunes orphan vectors.
 *
 * A vector is orphan when:
 *   - its source_url no longer maps to any row in wp_mxchat_system_prompt_content
 *     (post deleted in WP, mxchat row removed, cascade-delete missed for any reason);
 *   OR
 *   - its bot_id no longer matches any known bot (kept best-effort, optional).
 *
 * The compactor is conservative: it never runs if last sync was within the past
 * hour (the sync may be mid-transit), and it caps the per-run delete count
 * (filter `mxchat_plus_duckdb_compactor_max_deletes`, default 5000) to avoid blowing
 * up MotherDuck billing on a misconfigured install.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Compactor {

    private static ?self $instance = null;
    const CRON_HOOK = 'mxchat_plus_duckdb_compact';
    const MIN_SYNC_AGE_SECONDS = 3600;

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

    public function run(): array {
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

        if (!empty($opts['last_sync_at']) && (time() - (int) $opts['last_sync_at']) < self::MIN_SYNC_AGE_SECONDS) {
            return ['ok' => false, 'reason' => 'last sync too recent', 'deleted' => 0, 'cache_swept' => $cache_swept];
        }

        $max = (int) apply_filters('mxchat_plus_duckdb_compactor_max_deletes', 5000);
        $deleted = 0;

        try {
            $deleted = $this->prune_orphans($max);
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

    /**
     * Strategy: build a set of "alive" vector_ids by paging through the MySQL
     * KB (avoids one ~20 MB allocation for big KBs — PHP can free each batch's
     * row objects as we move on, keeping only the much smaller id-only map
     * around). Then page through DuckDB and delete everything that isn't in
     * that set. DELETE is chunked to 100 IDs at a time to stay under
     * MotherDuck HTTP body limits.
     */
    private function prune_orphans(int $max_deletes): int {
        if (!self::orphan_pruning_allowed()) {
            return 0;
        }

        $alive = $this->load_alive_ids();

        $store = new MxChat_Plus_DuckDB_Vector_Store();
        $store->ensure_schema();
        $conn = $store->connection();
        $table = $store->table_name_quoted();

        $deleted   = 0;
        $page_size = 1000;
        $orphans   = [];
        // Keyset (seek) cursor, NOT an OFFSET. The scan deletes from the very
        // table it is paging through, and with OFFSET every delete shifts the
        // remaining rows left while the offset keeps advancing by page_size —
        // so up to one page of rows was skipped after each drain and a single
        // sweep could never compact everything. A cursor on the ordering
        // column is immune: it resumes from the last vector_id actually seen.
        $cursor = null;

        while ($deleted < $max_deletes) {
            $page = $conn->execute(
                $cursor === null
                    ? sprintf('SELECT vector_id FROM %s ORDER BY vector_id LIMIT %d', $table, $page_size)
                    : sprintf(
                        "SELECT vector_id FROM %s WHERE vector_id > '%s' ORDER BY vector_id LIMIT %d",
                        $table,
                        str_replace("'", "''", $cursor),
                        $page_size
                    )
            );
            if (empty($page)) {
                break;
            }

            foreach ($page as $row) {
                $id = (string) ($row['vector_id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $cursor = $id;
                if (!isset($alive[$id])) {
                    $orphans[] = $id;
                }
            }

            // Drain the buffer in chunks to avoid massive IN(…) lists.
            while (count($orphans) >= self::DELETE_CHUNK_SIZE && $deleted < $max_deletes) {
                $deleted += $this->flush_orphans($conn, $table, $orphans, $max_deletes - $deleted);
            }

            if (count($page) < $page_size) {
                break; // short page → end of table
            }
        }

        // Final flush.
        while (!empty($orphans) && $deleted < $max_deletes) {
            $deleted += $this->flush_orphans($conn, $table, $orphans, $max_deletes - $deleted);
        }

        return $deleted;
    }

    /**
     * Delete at most $budget ids from the head of $orphans.
     *
     * The budget is what keeps `max_deletes` an actual ceiling: draining fixed
     * 100-id chunks while only testing `$deleted < $max_deletes` beforehand
     * overshot the advertised cap by up to 99 rows on the final chunk.
     *
     * @param list<string> $orphans consumed in place
     */
    private function flush_orphans(
        MxChat_Plus_DuckDB_Connection $conn,
        string $table,
        array &$orphans,
        int $budget
    ): int {
        $size = min(self::DELETE_CHUNK_SIZE, $budget, count($orphans));
        if ($size <= 0) {
            return 0;
        }
        return $this->delete_chunk($conn, $table, array_splice($orphans, 0, $size));
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
     */
    public static function orphan_pruning_allowed(): bool {
        $imported = get_option(MxChat_Plus_DuckDB_Pinecone_Migrator::IMPORTED_MARKER_OPTION, null);
        $allowed  = !is_array($imported) || empty($imported['copied']);

        return (bool) apply_filters('mxchat_plus_duckdb_compactor_prune_orphans', $allowed, $imported);
    }

    private function delete_chunk(MxChat_Plus_DuckDB_Connection $conn, string $quoted_table, array $ids): int {
        $list = implode(',', array_map(fn($id) => "'" . str_replace("'", "''", $id) . "'", $ids));
        $conn->execute(sprintf(
            'DELETE FROM %s WHERE vector_id IN (%s)',
            $quoted_table,
            $list
        ));
        return count($ids);
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

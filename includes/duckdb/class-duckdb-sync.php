<?php
/**
 * Façade over the two ingestion pipelines:
 *
 *   - MxChat_Plus_DuckDB_Mysql_Sync     — MySQL KB → DuckDB (bulk + incremental + cascade delete)
 *   - MxChat_Plus_DuckDB_Post_Reprocessor — WordPress posts → DuckDB via mxchat's pipeline
 *
 * Keeps the existing public API (`Sync::instance()->full_sync()`,
 * `Sync::vector_id_for_row($row)`, etc.) so call-sites in async-reprocess,
 * compactor, CLI, admin, REST proxy, and tests keep compiling unchanged.
 *
 * Also owns the hook registration that keeps DuckDB in step with writes
 * MxChat makes elsewhere:
 *   - the hourly incremental MySQL sync, plus a debounced re-sync after
 *     MxChat rewrites knowledge rows in place (kb-reimport, a role change);
 *   - the cascade on MxChat's delete AJAX actions;
 *   - the Pinecone write mirror: while DuckDB holds a copy of a real
 *     Pinecone index, every successful write MxChat sends that index is
 *     replayed into DuckDB (see mirror_pinecone_write()).
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Sync {

    const BATCH_SIZE = MxChat_Plus_DuckDB_Mysql_Sync::BATCH_SIZE;
    const CRON_HOOK = 'mxchat_plus_duckdb_incremental_sync';

    /**
     * Single event that re-reads MySQL KB rows MxChat rewrote without
     * touching their `timestamp` (so the incremental sync cannot see them).
     * No argument = full sync; one argument = a list of row ids.
     */
    const RESYNC_HOOK = 'mxchat_plus_duckdb_kb_resync';
    /** Seconds before a full re-sync runs; each new trigger pushes it back. */
    const RESYNC_DELAY = 300;
    /** Seconds before a targeted re-sync of a few rows runs. */
    const ROW_RESYNC_DELAY = 30;

    /**
     * Host literals: the nonce actions MxChat checks on the two AJAX handlers
     * observed below (MxChat_Knowledge_Manager::ajax_mxchat_kb_reimport and
     * ::ajax_mxchat_update_role_restriction, MxChat 3.2.25).
     */
    const HOST_REIMPORT_NONCE = 'mxchat_kb_reimport';
    const HOST_ROLE_NONCE     = 'mxchat_update_role_nonce';

    /**
     * Pinecone write endpoints MxChat calls (MxChat 3.2.25): the classic
     * vector index, and the document index MxChat_Pinecone_Documents speaks
     * since 3.2.22. Groups: 1 = classic op; 2 = document namespace; 3 =
     * document op.
     */
    const PINECONE_WRITE_PATH = '#^/(?:vectors/(delete|upsert)|namespaces/([^/?]+)/documents/(delete|upsert))$#';

    /** Pinecone's path segment for the default ('') namespace. */
    const PINECONE_DEFAULT_NAMESPACE_SEGMENT = '__default__';

    /** Upper bound of list pages walked to replay a delete-all (1000 ids each). */
    const MIRROR_DELETE_ALL_MAX_PAGES = 1000;

    private static ?self $instance = null;
    private MxChat_Plus_DuckDB_Mysql_Sync $mysql;
    private MxChat_Plus_DuckDB_Post_Reprocessor $posts;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function __construct() {
        $this->mysql = new MxChat_Plus_DuckDB_Mysql_Sync();
        $this->posts = new MxChat_Plus_DuckDB_Post_Reprocessor();
    }

    public function register_hooks(): void {
        // Wrap incremental_sync() so the action contract is void
        // (the return value is the row count, useful for tests + AJAX
        // but ignored by WordPress on cron tick).
        add_action(self::CRON_HOOK, [$this, 'incremental_sync_as_action']);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 3600, 'hourly', self::CRON_HOOK);
        }
        add_action(self::RESYNC_HOOK, [$this, 'run_scheduled_resync'], 10, 1);

        // Cascade of MxChat's own delete actions, at priority 1 (MxChat's
        // handlers, at 10, end the request). It only matters while MxChat
        // deletes from a store other than DuckDB — real Pinecone for the
        // default bot with the takeover off. On the proxy path MxChat's own
        // list + delete calls reach DuckDB directly. These four actions are
        // not every delete MxChat makes (REST DELETE /mxchat/v1/knowledge,
        // media auto-sync, post/product trash, unpublish… have no hook);
        // mirror_pinecone_write() below is what sees all of them.
        add_action('wp_ajax_mxchat_delete_pinecone_prompt', [$this, 'cascade_delete_handler'], 1);
        add_action('admin_post_mxchat_delete_pinecone_prompt', [$this, 'cascade_delete_handler'], 1);
        add_action('wp_ajax_mxchat_delete_chunks_by_url', [$this, 'cascade_delete_chunks_by_url'], 1);
        add_action('wp_ajax_mxchat_bulk_delete_knowledge', [$this, 'cascade_bulk_delete'], 1);

        // In-place rewrites of MySQL KB rows that leave `timestamp` alone.
        // Priority 1 again: MxChat's handlers exit through wp_send_json_*.
        add_action('wp_ajax_mxchat_kb_reimport', [$this, 'observe_kb_reimport'], 1);
        add_action('wp_ajax_mxchat_update_role_restriction', [$this, 'observe_role_change'], 1);
        // `wp mxchat kb-reimport` keeps its state in memory and writes no
        // option, so nothing else marks its end.
        if (defined('WP_CLI') && WP_CLI && is_callable(['WP_CLI', 'add_hook'])) {
            call_user_func(['WP_CLI', 'add_hook'], 'after_invoke:mxchat kb-reimport', [self::class, 'schedule_full_resync']);
        }

        // Every Pinecone write MxChat makes ends in a WP HTTP API call — the
        // only point all of its delete and upsert paths share.
        add_filter('http_response', [$this, 'mirror_pinecone_write'], 10, 3);

        // Reactions to a save of our own settings (fire after the sanitiser).
        add_action('update_option_' . MXCHAT_PLUS_DUCKDB_OPTION_KEY, [MxChat_Plus_DuckDB_Options::class, 'handle_settings_saved'], 10, 2);
        add_action('add_option_' . MXCHAT_PLUS_DUCKDB_OPTION_KEY, [MxChat_Plus_DuckDB_Options::class, 'handle_settings_added'], 10, 2);
    }

    public function incremental_sync_as_action(): void {
        $this->incremental_sync();
    }

    // ─── MySQL pipeline ─────────────────────────────────────────────────

    public function full_sync(?callable $progress = null): int {
        return $this->mysql->full_sync($progress);
    }

    public function incremental_sync(): int {
        return $this->mysql->incremental_sync();
    }

    public function cascade_delete_handler(): void {
        $this->mysql->cascade_delete_handler();
    }

    public function cascade_delete_chunks_by_url(): void {
        $this->mysql->cascade_delete_chunks_by_url();
    }

    public function cascade_bulk_delete(): void {
        $this->mysql->cascade_bulk_delete();
    }

    // ─── Re-sync after in-place rewrites ────────────────────────────────

    /**
     * Schedule one re-sync, debounced: a pending one with the same arguments
     * is pushed back rather than duplicated, so a kb-reimport applied in many
     * AJAX steps ends in a single full sync after its last step.
     *
     * @param list<int> $row_ids empty = full sync
     */
    public static function schedule_resync(array $row_ids = [], int $delay = self::RESYNC_DELAY): void {
        $ids = array_values(array_unique(array_filter(array_map('intval', $row_ids), static fn(int $id): bool => $id > 0)));
        $args = $ids === [] ? [] : [$ids];
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::RESYNC_HOOK, $args);
        }
        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + max(0, $delay), self::RESYNC_HOOK, $args);
        }
    }

    /** Argument-free entry point for WP-CLI's after_invoke hook. */
    public static function schedule_full_resync(): void {
        $opts = MxChat_Plus_DuckDB_Options::get();
        if (empty($opts['enabled'])) return;
        self::schedule_resync();
    }

    /**
     * RESYNC_HOOK worker.
     *
     * @param mixed $row_ids
     */
    public function run_scheduled_resync($row_ids = []): void {
        $opts = MxChat_Plus_DuckDB_Options::get();
        if (empty($opts['enabled'])) return;
        try {
            if (is_array($row_ids) && $row_ids !== []) {
                $this->mysql->resync_rows(array_values(array_map('intval', $row_ids)));
            } else {
                $this->mysql->full_sync();
            }
        } catch (\Throwable $e) {
            error_log('[mxchat-plus] kb resync: ' . $e->getMessage());
            MxChat_Plus_DuckDB_Options::update(['last_error' => 'kb resync: ' . $e->getMessage()]);
        }
    }

    /**
     * Priority-1 listener on MxChat's kb-reimport AJAX action. Its "apply"
     * step rewrites article_content and embedding_vector of WordPress-stored
     * entries with a plain $wpdb->update() that keeps `timestamp`
     * (MxChat_Knowledge_Manager::ajax_mxchat_kb_reimport, MxChat 3.2.24+), so
     * the incremental sync would never pick the repair up.
     */
    public function observe_kb_reimport(): void {
        if (!$this->host_ajax_is_authorized(self::HOST_REIMPORT_NONCE)) return;
        $op = isset($_POST['op']) && is_string($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
        if ($op !== 'apply') return;
        self::schedule_resync();
    }

    /**
     * Priority-1 listener on MxChat's role-change AJAX action. For a
     * WordPress-stored entry it updates role_restriction in place, again
     * without touching `timestamp` (MxChat_Knowledge_Manager::
     * ajax_mxchat_update_role_restriction, MxChat 3.2.25). DuckDB serves that
     * role for MySQL-synced rows, so a stale one would leave restricted text
     * visible. A Pinecone-stored entry needs nothing: its role lives in
     * MxChat's roles table, which DuckDB reads at query time.
     */
    public function observe_role_change(): void {
        if (!$this->host_ajax_is_authorized(self::HOST_ROLE_NONCE)) return;
        $source = isset($_POST['data_source']) && is_string($_POST['data_source'])
            ? sanitize_text_field(wp_unslash($_POST['data_source']))
            : 'wordpress';
        if ($source !== 'wordpress') return;
        $id = isset($_POST['entry_id']) && is_scalar($_POST['entry_id']) ? absint($_POST['entry_id']) : 0;
        if ($id > 0) {
            self::schedule_resync([$id], self::ROW_RESYNC_DELAY);
        }
    }

    /**
     * The checks MxChat's own handler is about to make (its nonce in the
     * `nonce` field, manage_options), plus our module being enabled — so a
     * forged request cannot queue work.
     */
    private function host_ajax_is_authorized(string $nonce_action): bool {
        $opts = MxChat_Plus_DuckDB_Options::get();
        if (empty($opts['enabled'])) return false;
        $nonce = isset($_POST['nonce']) && is_string($_POST['nonce']) ? (string) wp_unslash($_POST['nonce']) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, $nonce_action)) return false;
        return current_user_can('manage_options');
    }

    // ─── Pinecone write mirror ──────────────────────────────────────────

    /**
     * The Pinecone index DuckDB holds a copy of — the host
     * `wp mxchat-plus duckdb migrate-from-pinecone` recorded
     * (Migrator::IMPORTED_MARKER_OPTION) — or '' when there is none. Writes
     * to that index are mirrored. Filterable: return '' to switch the mirror
     * off, or another bare host to mirror that one.
     */
    public static function mirrored_pinecone_host(): string {
        $marker = get_option(MxChat_Plus_DuckDB_Pinecone_Migrator::IMPORTED_MARKER_OPTION, null);
        $host = is_array($marker) && !empty($marker['copied']) ? (string) ($marker['host'] ?? '') : '';
        /**
         * Filter the Pinecone host whose writes are replayed into DuckDB.
         *
         * @param string $host Bare host (no scheme, no trailing slash); '' = off.
         */
        $host = (string) apply_filters('mxchat_plus_duckdb_mirrored_pinecone_host', self::normalise_host($host));
        $host = self::normalise_host($host);
        // Never the proxy itself: its writes already land in DuckDB.
        if ($host === '' || MxChat_Plus_DuckDB_Search_Adapter::is_proxy_host($host)) {
            return '';
        }
        return $host;
    }

    /**
     * `http_response` observer (a filter that always returns $response
     * unchanged). While DuckDB holds a copy of a real Pinecone index — the
     * default bot still on Pinecone, takeover off, data imported to switch
     * later — MxChat keeps writing that index, and none of its delete or
     * upsert paths fires a hook an add-on could use: REST DELETE
     * /mxchat/v1/knowledge, media auto-sync, post/product trash, unpublish,
     * kb-reimport, the chunked re-store's clean-slate delete
     * (MxChat_Utils::submit_chunked_content), MxChat 3.2.25. They all end in
     * one WP HTTP API call to the index, so that call is the one place to see
     * them. Replaying only after a 2xx means DuckDB never deletes what
     * Pinecone kept. Upserts are mirrored as well as deletes, otherwise the
     * clean-slate delete of an edited entry would remove it from DuckDB.
     *
     * Covered: POST /vectors/delete (ids, a source_url filter, deleteAll),
     * /vectors/upsert, and the document-index /namespaces/{ns}/documents/
     * delete (ids, filter, delete_all) and /upsert. Not covered: deletes made
     * outside WordPress (Pinecone console) or before this version — those
     * rows stay until the DuckDB bot is emptied and imported again.
     *
     * Pinecone namespace → DuckDB bot: '' and the default bot's configured
     * namespace are 'default', any other namespace is the bot of that name;
     * an upserted vector's own metadata.bot_id wins, as in the migrator. (A
     * copy imported with --bot-id under another name is not followed.)
     * Mirrored rows are safe from the compactor: it does not prune orphans
     * while the import marker exists
     * (MxChat_Plus_DuckDB_Compactor::orphan_pruning_allowed()).
     *
     * @param mixed $response
     * @param mixed $args
     * @param mixed $url
     * @return mixed
     */
    public function mirror_pinecone_write($response, $args, $url) {
        try {
            $this->replay_pinecone_write($response, $args, $url);
        } catch (\Throwable $e) {
            error_log('[mxchat-plus] pinecone mirror: ' . $e->getMessage());
            MxChat_Plus_DuckDB_Metrics::record('pinecone_mirror_errors');
            MxChat_Plus_DuckDB_Options::update(['last_error' => 'pinecone mirror: ' . $e->getMessage()]);
        }
        return $response;
    }

    /**
     * @param mixed $response
     * @param mixed $args
     * @param mixed $url
     */
    private function replay_pinecone_write($response, $args, $url): void {
        // Cheap rejections first: this runs on every HTTP response of the site.
        if (!is_string($url) || strncasecmp($url, 'https://', 8) !== 0 || !is_array($args)) return;
        if (strtoupper((string) ($args['method'] ?? 'GET')) !== 'POST') return;
        if (!preg_match('#/(?:delete|upsert)(?:\?|$)#', $url)) return;

        $opts = MxChat_Plus_DuckDB_Options::get();
        if (empty($opts['enabled'])) return;
        $host = self::mirrored_pinecone_host();
        if ($host === '') return;

        $write = self::match_pinecone_write($url, $host);
        if ($write === null) return;

        $code = is_array($response) && isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
        if ($code < 200 || $code >= 300) return;

        $body = isset($args['body']) && is_string($args['body']) ? json_decode($args['body'], true) : null;
        if (!is_array($body)) return;

        $namespace = $write['protocol'] === 'document'
            ? $write['namespace']
            : (is_scalar($body['namespace'] ?? null) ? (string) $body['namespace'] : '');
        $bot = self::bot_for_namespace($namespace);

        $store = new MxChat_Plus_DuckDB_Vector_Store();
        if ($write['op'] === 'delete') {
            $this->replay_delete($store, $body, $bot, $write['protocol']);
        } else {
            $this->replay_upsert($store, $body, $bot, $write['protocol']);
        }
    }

    /**
     * Which Pinecone write a URL is, when it targets $host.
     *
     * @return array{op: string, protocol: string, namespace: string}|null
     */
    public static function match_pinecone_write(string $url, string $host): ?array {
        $rest = substr($url, 8);
        $host = self::normalise_host($host);
        if ($host === '' || strncasecmp($rest, $host . '/', strlen($host) + 1) !== 0) return null;
        $path = (string) strtok(substr($rest, strlen($host)), '?');
        if (!preg_match(self::PINECONE_WRITE_PATH, $path, $m)) return null;
        if (!empty($m[1])) {
            return ['op' => $m[1], 'protocol' => 'vector', 'namespace' => ''];
        }
        $segment = rawurldecode($m[2]);
        return [
            'op'        => $m[3],
            'protocol'  => 'document',
            'namespace' => $segment === self::PINECONE_DEFAULT_NAMESPACE_SEGMENT ? '' : $segment,
        ];
    }

    /**
     * DuckDB bot of a Pinecone namespace: '' and the namespace configured for
     * MxChat's default bot are 'default'.
     */
    public static function bot_for_namespace(string $namespace): string {
        if ($namespace === '') return 'default';
        $stored = MxChat_Plus_DuckDB_Search_Adapter::stored_pinecone_options();
        if ((string) ($stored['mxchat_pinecone_namespace'] ?? '') === $namespace) return 'default';
        return $namespace;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function replay_delete(MxChat_Plus_DuckDB_Vector_Store $store, array $body, string $bot, string $protocol): void {
        $all_key = $protocol === 'document' ? 'delete_all' : 'deleteAll';
        if (!empty($body[$all_key]) && empty($body['ids']) && empty($body['filter'])) {
            $this->delete_whole_bot($store, $bot);
            MxChat_Plus_DuckDB_Metrics::record('pinecone_mirror_deletes');
            return;
        }

        $done = false;
        if (isset($body['ids']) && is_array($body['ids'])) {
            $ids = array_values(array_filter(array_map(
                static fn($id): string => is_scalar($id) ? (string) $id : '',
                $body['ids']
            ), static fn(string $id): bool => $id !== ''));
            if ($ids !== []) {
                $store->delete_by_ids($ids, $bot);
                $done = true;
            }
        }
        if (isset($body['filter']) && is_array($body['filter'])) {
            $urls = self::source_urls_of_filter($body['filter']);
            foreach (array_slice($urls, 0, 1000) as $url) {
                $store->delete_by_source_url($url, $bot);
                $done = true;
            }
            if ($urls === [] && defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[mxchat-plus] pinecone mirror: delete filter not replayed (only source_url filters are): ' . wp_json_encode($body['filter']));
            }
        }
        if ($done) {
            MxChat_Plus_DuckDB_Metrics::record('pinecone_mirror_deletes');
        }
    }

    /**
     * The source URLs a Pinecone delete filter targets — the only filter
     * shape MxChat sends: {source_url: {$eq|$in: …}} or the scalar shorthand.
     *
     * @param array<mixed> $filter
     * @return list<string>
     */
    public static function source_urls_of_filter(array $filter): array {
        if (!array_key_exists('source_url', $filter)) return [];
        $clause = $filter['source_url'];
        $values = [];
        if (is_scalar($clause)) {
            $values = [$clause];
        } elseif (is_array($clause)) {
            if (isset($clause['$eq']) && is_scalar($clause['$eq'])) $values[] = $clause['$eq'];
            if (isset($clause['$in']) && is_array($clause['$in'])) {
                foreach ($clause['$in'] as $v) {
                    if (is_scalar($v)) $values[] = $v;
                }
            }
        }
        $urls = [];
        foreach ($values as $v) {
            $v = (string) $v;
            if ($v !== '') $urls[$v] = true;
        }
        return array_map('strval', array_keys($urls));
    }

    /** Replay a namespace-wide delete: page through the bot's ids and drop them. */
    private function delete_whole_bot(MxChat_Plus_DuckDB_Vector_Store $store, string $bot): void {
        $token = '';
        for ($page = 0; $page < self::MIRROR_DELETE_ALL_MAX_PAGES; $page++) {
            $listing = $store->list_ids_page($bot, MxChat_Plus_DuckDB_Vector_Store::LIST_PAGE_MAX, $token);
            if ($listing['ids'] === []) return;
            $store->delete_by_ids($listing['ids'], $bot);
            $token = $listing['next'];
            if ($token === '') return;
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function replay_upsert(MxChat_Plus_DuckDB_Vector_Store $store, array $body, string $bot, string $protocol): void {
        $items = $protocol === 'document' ? ($body['documents'] ?? null) : ($body['vectors'] ?? null);
        if (!is_array($items)) return;

        $dim = $store->dimension();
        $rows = [];
        $skipped = 0;
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            if ($protocol === 'document') {
                $id = is_scalar($item['_id'] ?? null) ? (string) $item['_id'] : '';
                $vector = MxChat_Plus_DuckDB_Pinecone_Migrator::document_to_vector($item);
                $values = $vector['values'];
                $metadata = $vector['metadata'];
            } else {
                $id = is_scalar($item['id'] ?? null) ? (string) $item['id'] : '';
                $values = isset($item['values']) && is_array($item['values']) ? $item['values'] : [];
                $metadata = isset($item['metadata']) && is_array($item['metadata']) ? $item['metadata'] : [];
            }
            if ($id === '' || $values === []) continue;
            if (count($values) !== $dim) {
                $skipped++;
                continue;
            }
            $row_bot = is_scalar($metadata['bot_id'] ?? null) && (string) $metadata['bot_id'] !== ''
                ? (string) $metadata['bot_id']
                : $bot;
            $rows[] = MxChat_Plus_DuckDB_Vector_Store::row_from_pinecone($id, $values, $metadata, $row_bot);
        }
        if ($skipped > 0) {
            error_log(sprintf(
                '[mxchat-plus] pinecone mirror: %d vector(s) not mirrored, their dimension is not the DuckDB table\'s %d',
                $skipped,
                $dim
            ));
        }
        if ($rows !== []) {
            $store->upsert($rows);
            MxChat_Plus_DuckDB_Metrics::record('pinecone_mirror_upserts');
        }
    }

    private static function normalise_host(string $host): string {
        return strtolower(trim((string) preg_replace('#^https?://#i', '', trim($host)), '/'));
    }

    /**
     * Forwards the optional chunk metadata too. Dropping it here (the façade
     * used to take `$row` only) is exactly how the compactor ended up building
     * its alive-set from bare md5(url) ids while the sync pipeline wrote
     * `md5(url)_chunk_N` — any caller that has the KB row's chunk header must
     * be able to reproduce the written id through this façade.
     *
     * @param array{is_chunked:bool, chunk_index:?int, total_chunks:?int, text:string}|null $chunk_meta
     */
    public static function vector_id_for_row($row, ?array $chunk_meta = null): string {
        return MxChat_Plus_DuckDB_Mysql_Sync::vector_id_for_row($row, $chunk_meta);
    }

    /**
     * @return array{is_chunked:bool, chunk_index:?int, total_chunks:?int, text:string}
     */
    public static function parse_chunk_prefix(string $content): array {
        return MxChat_Plus_DuckDB_Mysql_Sync::parse_chunk_prefix($content);
    }

    // ─── Post reprocessor pipeline ──────────────────────────────────────

    public function reprocess_posts(array $post_types, int $batch_size, int $offset, string $bot_id = 'default'): array {
        return $this->posts->reprocess_posts($post_types, $batch_size, $offset, $bot_id);
    }

    public function reprocess_single_post(int $post_id, string $bot_id = 'default'): void {
        $this->posts->reprocess_single_post($post_id, $bot_id);
    }
}

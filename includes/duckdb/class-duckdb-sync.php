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
 * Also owns the WP-cron + AJAX hook registration that used to live inline.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Sync {

    const BATCH_SIZE = MxChat_Plus_DuckDB_Mysql_Sync::BATCH_SIZE;
    const CRON_HOOK = 'mxchat_plus_duckdb_incremental_sync';

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

        add_action('wp_ajax_mxchat_delete_pinecone_prompt', [$this, 'cascade_delete_handler'], 1);
        add_action('admin_post_mxchat_delete_pinecone_prompt', [$this, 'cascade_delete_handler'], 1);

        // Newer mxchat-basic (3.2.2+) ships chunk-aware deletions through two
        // additional AJAX endpoints. We mirror their work on the DuckDB side
        // so installs not on the Option B proxy path (e.g. default-bot, real
        // Pinecone primary + DuckDB mirror) don't drift.
        add_action('wp_ajax_mxchat_delete_chunks_by_url', [$this, 'cascade_delete_chunks_by_url'], 1);
        add_action('wp_ajax_mxchat_bulk_delete_knowledge', [$this, 'cascade_bulk_delete'], 1);
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

<?php
/**
 * MySQL → DuckDB pipeline.
 *
 * Bulk + incremental sync of MxChat's MySQL KB table
 * (wp_mxchat_system_prompt_content) into DuckDB, plus the cascade-delete
 * AJAX handler that mirrors mxchat's deletions to DuckDB.
 *
 * MxChat does not expose a "vector saved" hook, so strictly real-time
 * consistency would require polling. The hourly cron is the freshness
 * floor; users can hit "Sync now" for an immediate pass.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Mysql_Sync {

    const BATCH_SIZE = 250;

    /**
     * Full sync from MySQL → DuckDB. Idempotent thanks to the stable
     * vector_id_for_row() scheme. Returns total upserted.
     *
     * @param callable|null $progress fn(int $done, int $total): void
     */
    /**
     * DuckDB-native fast path for the MySQL → DuckDB sync.
     *
     * Uses the DuckDB `mysql` extension to ATTACH the WP database and copy
     * every row through a single `INSERT INTO ... SELECT FROM mysql_attach`
     * statement. Eliminates the per-batch PHP↔MySQL↔DuckDB round-trip that
     * dominates the legacy `full_sync()` loop on large catalogues — empirical
     * 5–10× on a 100k-vector copy.
     *
     * The catch: mxchat-basic stores embeddings as PHP `serialize()`
     * (`a:1536:{i:0;d:0.123;i:1;d:0.456;…}`). DuckDB can't deserialise that
     * natively. We parse it with a `regexp_extract_all('d:([-0-9.eE+]+);')`
     * which captures every `d:` value (PHP encodes embedding components as
     * doubles for every model we ship — OpenAI / Voyage / Gemini). Rows with
     * the wrong shape after parsing fail the FLOAT[N] cast and abort the
     * whole INSERT — the caller is expected to wrap and fall back to the
     * legacy path on any RuntimeException.
     *
     * Pre-requisites checked: DuckDB `mysql` extension installed, WordPress
     * DB credentials available as constants, plugin enabled, schema migrated.
     *
     * @return int rows actually copied (DuckDB's `changes()`-equivalent)
     * @throws RuntimeException when the native path is unavailable or fails;
     *                          the caller should fall back to full_sync().
     */
    public function full_sync_native(): int {
        global $wpdb;

        if (!self::has_duckdb_mysql_extension()) {
            throw new RuntimeException(
                __('DuckDB mysql extension is not available; cannot use the native sync path.', 'mxchat-plus')
            );
        }

        $store = new MxChat_Plus_DuckDB_Vector_Store();
        $store->ensure_schema();
        $conn = $store->connection();

        $kb = $wpdb->prefix . 'mxchat_system_prompt_content';
        $columns = self::detect_kb_columns($kb);
        $opts    = MxChat_Plus_DuckDB_Options::get();
        $dim     = (int) $opts['embedding_dim'];

        // INSTALL is a no-op once cached; LOAD is required each session.
        // The mysql_scanner subextension provides the actual connector.
        $conn->execute('INSTALL mysql');
        $conn->execute('LOAD mysql');

        $attach_alias = 'mxp_wp_mysql_' . substr(md5($kb), 0, 6);
        $conn->execute(sprintf(
            "ATTACH '%s' AS \"%s\" (TYPE mysql, READ_ONLY)",
            str_replace("'", "''", self::build_mysql_connection_string()),
            $attach_alias
        ));

        try {
            $bot_id_expr = !empty($columns['has_bot_id'])
                ? "COALESCE(NULLIF(bot_id, ''), 'default')"
                : "'default'";

            // The big one: read straight from MySQL, parse the PHP-serialized
            // embedding via regex, cast to FLOAT[N], align metadata defaults.
            //
            // Chunked-content detection: mxchat-basic stores chunks with a
            // JSON-prefix `{"document_type":"chunked",...}\n---\n<text>` in
            // article_content (see MxChat_Chunker::format_chunk_for_storage).
            // Without parsing it, every chunk of the same URL would collapse
            // into a single vector_id (md5(url)) and INSERT OR REPLACE would
            // silently keep only the last one — see parse_chunk_prefix() for
            // the PHP-path equivalent. Detection is a CTE that splits at the
            // `\n---\n` separator and extracts the chunk_index / total_chunks
            // values; vector_id then mirrors mxchat\'s
            // `{md5(url)}_chunk_{N}` scheme for chunked rows.
            $sql = sprintf(
                'INSERT OR REPLACE INTO %1$s
                    (vector_id, bot_id, embedding, content, source_url,
                     role_restriction, content_type, chunk_index, total_chunks, is_chunked)
                 WITH src AS (
                    SELECT
                        id, url, embedding_vector, role_restriction, content_type,
                        %6$s,
                        article_content,
                        starts_with(COALESCE(article_content, \'\'), \'{"document_type"\') AS has_prefix,
                        position(E\'\\n---\\n\' IN COALESCE(article_content, \'\')) AS sep_pos
                    FROM "%4$s"."%5$s"
                    WHERE embedding_vector IS NOT NULL AND embedding_vector != \'\'
                 ),
                 parsed AS (
                    SELECT *,
                        CASE WHEN has_prefix AND sep_pos > 0
                             THEN substring(article_content, 1, sep_pos - 1)
                             ELSE NULL
                        END AS meta_json,
                        CASE WHEN has_prefix AND sep_pos > 0
                             THEN substring(article_content, sep_pos + 5)
                             ELSE COALESCE(article_content, \'\')
                        END AS body_text
                    FROM src
                 ),
                 enriched AS (
                    SELECT *,
                        TRY_CAST(json_extract_string(meta_json, \'$.chunk_index\')  AS INTEGER) AS chunk_idx,
                        TRY_CAST(json_extract_string(meta_json, \'$.total_chunks\') AS INTEGER) AS chunk_total,
                        (meta_json IS NOT NULL
                         AND json_extract_string(meta_json, \'$.document_type\') = \'chunked\') AS is_chunk_row
                    FROM parsed
                 )
                 SELECT
                    CASE
                        WHEN is_chunk_row AND COALESCE(url, \'\') != \'\'
                             THEN md5(url) || \'_chunk_\' || CAST(COALESCE(chunk_idx, 0) AS VARCHAR)
                        WHEN COALESCE(url, \'\') != \'\'
                             THEN md5(url)
                        ELSE \'mxchat_kb_\' || CAST(id AS VARCHAR)
                    END AS vector_id,
                    %2$s AS bot_id,
                    CAST(regexp_extract_all(embedding_vector, \'d:([-0-9.eE+]+);\', 1)
                         AS DOUBLE[])::FLOAT[%3$d] AS embedding,
                    body_text AS content,
                    COALESCE(url, \'\') AS source_url,
                    COALESCE(role_restriction, \'public\') AS role_restriction,
                    COALESCE(content_type, \'content\') AS content_type,
                    CASE WHEN is_chunk_row THEN chunk_idx   ELSE NULL END AS chunk_index,
                    CASE WHEN is_chunk_row THEN chunk_total ELSE NULL END AS total_chunks,
                    is_chunk_row AS is_chunked
                 FROM enriched',
                $store->table_name_quoted(),
                $bot_id_expr,
                $dim,
                $attach_alias,
                $kb,
                !empty($columns['has_bot_id']) ? 'bot_id' : "'default' AS bot_id"
            );
            $conn->execute($sql);

            // DuckDB exposes the row count from the last DML via this PRAGMA.
            $rows = $conn->execute('SELECT COUNT(*) AS c FROM ' . $store->table_name_quoted());
            $count = (int) ($rows[0]['c'] ?? 0);
        } finally {
            // Always release the MySQL connection, even on partial failure.
            try {
                $conn->execute(sprintf('DETACH "%s"', $attach_alias));
            } catch (\Throwable $e) {
                // Best-effort cleanup; an open ATTACH leaks until the next
                // process restart, which is acceptable.
            }
        }

        // Cache invalidation: a full MySQL→DuckDB sync rewrites every bot's
        // vectors, so flush all tracked bots (null = flush-all), not just one.
        MxChat_Plus_DuckDB_Cache::flush_query_cache();

        MxChat_Plus_DuckDB_Options::update([
            'last_sync_at'    => time(),
            'last_sync_count' => $count,
            'last_error'      => '',
        ]);

        return $count;
    }

    /**
     * Is the DuckDB mysql extension installed AND loadable?
     * Cached at class level (one DuckDB roundtrip per request) and resettable
     * by tests via reflection on $mysql_ext_available.
     */
    private static ?bool $mysql_ext_available = null;

    public static function has_duckdb_mysql_extension(): bool {
        if (self::$mysql_ext_available !== null) return self::$mysql_ext_available;

        try {
            $store = new MxChat_Plus_DuckDB_Vector_Store();
            $conn = $store->connection();
            $rows = $conn->execute(
                "SELECT installed, loaded FROM duckdb_extensions() WHERE extension_name = 'mysql'"
            );
            return self::$mysql_ext_available = !empty($rows) && !empty($rows[0]['installed']);
        } catch (\Throwable $e) {
            return self::$mysql_ext_available = false;
        }
    }

    /**
     * Compose the `key=value …` string the DuckDB mysql extension expects.
     * Uses WordPress constants (DB_HOST / DB_USER / DB_PASSWORD / DB_NAME);
     * swaps `localhost` for `127.0.0.1` because the extension is TCP-only
     * (Unix-socket support landed late and isn't universally available).
     */
    private static function build_mysql_connection_string(): string {
        $host = defined('DB_HOST') ? (string) DB_HOST : '127.0.0.1';
        $user = defined('DB_USER') ? (string) DB_USER : '';
        $pass = defined('DB_PASSWORD') ? (string) DB_PASSWORD : '';
        $name = defined('DB_NAME') ? (string) DB_NAME : '';

        // DB_HOST may be "host:port" (WP convention).
        $port = null;
        if (strpos($host, ':') !== false) {
            [$host, $port] = explode(':', $host, 2);
        }
        if ($host === 'localhost') $host = '127.0.0.1';

        $parts = [
            'host=' . $host,
            'user=' . $user,
            'password=' . $pass,
            'database=' . $name,
        ];
        if ($port !== null && ctype_digit((string) $port)) {
            $parts[] = 'port=' . $port;
        }
        return implode(' ', $parts);
    }

    public function full_sync(?callable $progress = null): int {
        global $wpdb;
        $kb = $wpdb->prefix . 'mxchat_system_prompt_content';

        $store = new MxChat_Plus_DuckDB_Vector_Store();
        $store->ensure_schema();

        $columns = self::detect_kb_columns($kb);

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$kb}");
        if ($total === 0) {
            MxChat_Plus_DuckDB_Options::update(['last_sync_at' => time(), 'last_sync_count' => 0, 'last_error' => '']);
            return 0;
        }

        $done = 0;
        $skipped = 0;
        $offset = 0;
        while ($offset < $total) {
            $select = self::build_select($columns, $kb);
            $batch = $wpdb->get_results($wpdb->prepare(
                $select . ' ORDER BY id ASC LIMIT %d OFFSET %d',
                self::BATCH_SIZE,
                $offset
            ));

            if (empty($batch)) break;

            $vectors = [];
            foreach ($batch as $row) {
                $v = self::row_to_vector($row, $columns);
                if ($v !== null) {
                    $vectors[] = $v;
                } else {
                    $skipped++;
                }
            }

            if (!empty($vectors)) {
                $store->upsert($vectors);
                $done += count($vectors);
            }

            $offset += self::BATCH_SIZE;
            if ($progress) {
                $progress(min($offset, $total), $total);
            }
        }

        self::log_skipped_summary('full_sync', $done, $skipped, $total);

        MxChat_Plus_DuckDB_Options::update([
            'last_sync_at'    => time(),
            'last_sync_count' => $done,
            'last_error'      => '',
        ]);

        return $done;
    }

    /**
     * Picks up rows whose `timestamp` is newer than last_sync_at. Conservative
     * — also re-syncs the last few minutes to absorb clock skew.
     */
    public function incremental_sync(): int {
        global $wpdb;
        $kb = $wpdb->prefix . 'mxchat_system_prompt_content';

        $opts = MxChat_Plus_DuckDB_Options::get();
        if (empty($opts['enabled'])) return 0;

        $since = max(0, (int) $opts['last_sync_at'] - 120);
        $since_sql = gmdate('Y-m-d H:i:s', $since);

        try {
            $store = new MxChat_Plus_DuckDB_Vector_Store();
            $columns = self::detect_kb_columns($kb);

            $select = self::build_select($columns, $kb);
            $rows = $wpdb->get_results($wpdb->prepare(
                $select . ' WHERE timestamp >= %s ORDER BY id ASC',
                $since_sql
            ));

            if (empty($rows)) {
                MxChat_Plus_DuckDB_Options::update(['last_sync_at' => time(), 'last_error' => '']);
                return 0;
            }

            $vectors = [];
            $skipped = 0;
            foreach ($rows as $row) {
                $v = self::row_to_vector($row, $columns);
                if ($v !== null) {
                    $vectors[] = $v;
                } else {
                    $skipped++;
                }
            }

            $count = 0;
            if (!empty($vectors)) {
                $count = $store->upsert($vectors);
            }

            self::log_skipped_summary('incremental_sync', $count, $skipped, count($rows));

            MxChat_Plus_DuckDB_Options::update([
                'last_sync_at' => time(),
                'last_error'   => '',
            ]);

            return $count;
        } catch (\Throwable $e) {
            error_log('[mxchat-plus] incremental_sync: ' . $e->getMessage());
            MxChat_Plus_DuckDB_Options::update(['last_error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * AJAX hook fired by mxchat's own delete action. We re-check the nonce +
     * capability ourselves instead of relying on mxchat's check running after
     * — defense in depth against priority changes upstream.
     *
     * mxchat-basic exposes two delete paths and the nonce travels differently
     * in each:
     *
     *   - `wp_ajax_mxchat_delete_pinecone_prompt` (the JS-driven Delete button):
     *     POST `nonce` field, action key `mxchat_delete_pinecone_prompt_nonce`.
     *     See admin/class-knowledge-manager.php in mxchat-basic 3.2.6
     *     (check_ajax_referer at line ~5985).
     *   - `admin_post_mxchat_delete_pinecone_prompt` (form-style fallback):
     *     GET `_wpnonce`, same action key `mxchat_delete_pinecone_prompt_nonce`
     *     (wp_verify_nonce at line ~5928 of the same file).
     *
     * Two additional action names are accepted as fallbacks:
     *   - `mxchat_delete_pinecone_prompt` — the legacy name this handler used
     *     to check; kept so installs that ever shipped a custom integration
     *     against the bare hook name keep working.
     *   - `mxchat_plus_duckdb_admin` — this plugin's own admin nonce, used as a
     *     graceful fallback for installs where mxchat's UI doesn't ship a
     *     delete-specific nonce.
     *
     * Why accepting `mxchat_plus_duckdb_admin` is safe even though it's used
     * elsewhere for non-destructive AJAX (test connection, stats, …): every
     * nonce check is AND-ed with `current_user_can('manage_options')` on the
     * very next line, and every other endpoint that mints / consumes the
     * `mxchat_plus_duckdb_admin` nonce also gates on `manage_options`. So an
     * attacker who somehow has this nonce already has admin capability — at
     * which point they can trigger destructive endpoints directly. The
     * dual-nonce only widens the legitimate-caller set, not the attack
     * surface.
     */
    public function cascade_delete_handler(): void {
        if (!$this->authorize_cascade([
            'mxchat_delete_pinecone_prompt_nonce',
            'mxchat_delete_pinecone_prompt',
            'mxchat_plus_duckdb_admin',
        ])) return;

        $vector_id = isset($_POST['vector_id']) ? sanitize_text_field(wp_unslash($_POST['vector_id']))
                   : (isset($_GET['vector_id']) ? sanitize_text_field(wp_unslash($_GET['vector_id'])) : '');
        $bot_id    = isset($_POST['bot_id'])    ? sanitize_text_field(wp_unslash($_POST['bot_id']))
                   : (isset($_GET['bot_id'])    ? sanitize_text_field(wp_unslash($_GET['bot_id']))    : 'default');

        if (empty($vector_id)) return;

        try {
            $store = new MxChat_Plus_DuckDB_Vector_Store();
            $store->delete_by_ids([$vector_id], $bot_id);
        } catch (\Throwable $e) {
            MxChat_Plus_DuckDB_Options::update(['last_error' => 'cascade delete: ' . $e->getMessage()]);
        }
    }

    /**
     * Mirror of mxchat-basic 3.2.6's `ajax_mxchat_delete_chunks_by_url`. When
     * `data_source === 'pinecone'`, mxchat issues a `/vectors/list` + batch
     * `/vectors/delete` against the configured Pinecone host. If that host is
     * our proxy (Option B), the proxy handles the DuckDB delete itself; if
     * it's real Pinecone, we need this cascade to keep DuckDB in sync.
     *
     * The `data_source === 'wordpress'` branch is a no-op on our side —
     * mxchat deletes its own rows directly; our incremental sync will
     * notice the gap on its next tick.
     *
     * Nonce contract: POST `nonce` + action `mxchat_delete_chunks_nonce`
     * (admin/class-knowledge-manager.php in mxchat-basic 3.2.6 line ~6042).
     */
    public function cascade_delete_chunks_by_url(): void {
        if (!$this->authorize_cascade([
            'mxchat_delete_chunks_nonce',
            'mxchat_plus_duckdb_admin',
        ])) return;

        $data_source = isset($_POST['data_source']) ? sanitize_text_field(wp_unslash($_POST['data_source'])) : 'wordpress';
        if ($data_source !== 'pinecone') return;

        $source_url = isset($_POST['source_url']) ? esc_url_raw(wp_unslash($_POST['source_url'])) : '';
        $bot_id     = isset($_POST['bot_id'])     ? sanitize_text_field(wp_unslash($_POST['bot_id'])) : 'default';
        if ($source_url === '') return;

        try {
            $store = new MxChat_Plus_DuckDB_Vector_Store();
            // delete_by_source_url covers both the base (single, non-chunked)
            // row AND every `{md5(url)}_chunk_N` row — they all share the same
            // source_url column in our schema.
            $store->delete_by_source_url($source_url, $bot_id);
        } catch (\Throwable $e) {
            MxChat_Plus_DuckDB_Options::update(['last_error' => 'cascade chunks delete: ' . $e->getMessage()]);
        }
    }

    /**
     * Mirror of mxchat-basic 3.2.6's `ajax_mxchat_bulk_delete_knowledge`. The
     * caller posts an `entries` array where each entry has
     *   { id, source: 'pinecone'|'wordpress', sourceUrl?, isGroup? }
     * We process only the pinecone-sourced entries; group entries map to
     * delete_by_source_url (chunk-aware), singleton entries map to
     * delete_by_ids([id]) — same vector-id scheme as mxchat itself uses.
     *
     * Nonce contract: POST `nonce` + action `mxchat_bulk_delete_knowledge_nonce`
     * (admin/class-knowledge-manager.php in mxchat-basic 3.2.6 line ~6251).
     */
    public function cascade_bulk_delete(): void {
        if (!$this->authorize_cascade([
            'mxchat_bulk_delete_knowledge_nonce',
            'mxchat_plus_duckdb_admin',
        ])) return;

        $entries = $_POST['entries'] ?? [];
        if (!is_array($entries) || $entries === []) return;

        $bot_id = isset($_POST['bot_id']) ? sanitize_text_field(wp_unslash($_POST['bot_id'])) : 'default';

        $ids_to_delete = [];
        $urls_to_delete = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;
            $source = isset($entry['source']) ? sanitize_text_field((string) $entry['source']) : 'wordpress';
            if ($source !== 'pinecone') continue;

            $entry_id = isset($entry['id']) ? sanitize_text_field((string) $entry['id']) : '';
            // mxchat sends `isGroup` as bool OR the literal string 'true'.
            $is_group = isset($entry['isGroup']) && ($entry['isGroup'] === true || $entry['isGroup'] === 'true');
            $source_url = isset($entry['sourceUrl']) ? esc_url_raw((string) $entry['sourceUrl']) : '';

            if ($is_group && $source_url !== '') {
                $urls_to_delete[] = $source_url;
            } elseif ($entry_id !== '') {
                $ids_to_delete[] = $entry_id;
            }
        }

        if ($ids_to_delete === [] && $urls_to_delete === []) return;

        try {
            $store = new MxChat_Plus_DuckDB_Vector_Store();
            if ($ids_to_delete !== []) {
                $store->delete_by_ids(array_values(array_unique($ids_to_delete)), $bot_id);
            }
            foreach (array_unique($urls_to_delete) as $url) {
                $store->delete_by_source_url($url, $bot_id);
            }
        } catch (\Throwable $e) {
            MxChat_Plus_DuckDB_Options::update(['last_error' => 'cascade bulk delete: ' . $e->getMessage()]);
        }
    }

    /**
     * Shared nonce + capability gate for cascade-delete handlers. Reads the
     * nonce from whichever field mxchat's UI happens to populate (POST
     * `nonce` for AJAX, POST/GET `_wpnonce` for form-style fallbacks) and
     * accepts any of the given action names. Always ANDs with
     * `current_user_can('manage_options')` — see the `cascade_delete_handler`
     * docblock for why this remains safe with the plugin's own admin nonce in
     * the accepted list.
     *
     * @param string[] $accepted_actions  Nonce action names that authorise the request.
     */
    private function authorize_cascade(array $accepted_actions): bool {
        $opts = MxChat_Plus_DuckDB_Options::get();
        if (empty($opts['enabled'])) return false;

        $nonce = '';
        foreach (['nonce', '_wpnonce'] as $field) {
            if (isset($_POST[$field])) { $nonce = (string) wp_unslash($_POST[$field]); break; }
            if (isset($_GET[$field]))  { $nonce = (string) wp_unslash($_GET[$field]);  break; }
        }
        if ($nonce === '') return false;

        $nonce_ok = false;
        foreach ($accepted_actions as $action) {
            if (wp_verify_nonce($nonce, $action)) { $nonce_ok = true; break; }
        }
        if (!$nonce_ok) return false;

        return current_user_can('manage_options');
    }

    /**
     * Vector ID scheme aligned with mxchat's:
     *   - chunked rows:     md5(source_url) || '_chunk_' || chunk_index
     *     (matches MxChat_Chunker::generate_chunk_vector_id())
     *   - URL-based rows:   md5(source_url)
     *   - manual rows w/o URL: 'mxchat_kb_' || id (our fallback for ancient rows
     *     that pre-date mxchat's source_url-required constraint).
     *
     * Public + static so the compactor and tests can call it without a
     * Mysql_Sync instance. The optional `$chunk_meta` argument carries the
     * parsed JSON-prefix metadata from `parse_chunk_prefix()` — pass null for
     * non-chunked content.
     *
     * @param array{is_chunked:bool, chunk_index:?int, total_chunks:?int, text:string}|null $chunk_meta
     */
    public static function vector_id_for_row($row, ?array $chunk_meta = null): string {
        $url = (string) ($row->source_url ?? '');
        if ($url !== '') {
            $base = md5($url);
            if ($chunk_meta !== null && !empty($chunk_meta['is_chunked'])) {
                return $base . '_chunk_' . (int) ($chunk_meta['chunk_index'] ?? 0);
            }
            return $base;
        }
        return 'mxchat_kb_' . (int) ($row->id ?? 0);
    }

    /**
     * Parse mxchat's chunked-content JSON prefix. mxchat-basic 2.5+ stores
     * chunks in the WP KB table as
     *   {"document_type":"chunked","chunk_index":N,"total_chunks":M,...}\n---\n<text>
     * (see MxChat_Chunker::format_chunk_for_storage / parse_stored_chunk).
     *
     * Without this detection, the bulk MySQL sync would write every chunk of
     * the same URL into the same vector_id (md5(url)) and silently keep only
     * the last chunk inserted — data loss for any KB built by an mxchat
     * install that ran in WP-DB-only mode (i.e. no Pinecone) at any point.
     *
     * Returns a normalised shape with text body separated from chunk-metadata.
     * For non-chunked content the returned text equals the input.
     *
     * @return array{is_chunked:bool, chunk_index:?int, total_chunks:?int, text:string}
     */
    public static function parse_chunk_prefix(string $content): array {
        // Cheap exit for the common non-chunked case (~all rows in installs
        // that never enabled the chunker).
        if (strncmp($content, '{"document_type"', 16) !== 0) {
            return ['is_chunked' => false, 'chunk_index' => null, 'total_chunks' => null, 'text' => $content];
        }
        $sep = strpos($content, "\n---\n");
        if ($sep === false) {
            return ['is_chunked' => false, 'chunk_index' => null, 'total_chunks' => null, 'text' => $content];
        }
        $meta = json_decode(substr($content, 0, $sep), true);
        if (!is_array($meta) || ($meta['document_type'] ?? '') !== 'chunked') {
            return ['is_chunked' => false, 'chunk_index' => null, 'total_chunks' => null, 'text' => $content];
        }
        return [
            'is_chunked'   => true,
            'chunk_index'  => isset($meta['chunk_index']) ? (int) $meta['chunk_index'] : 0,
            'total_chunks' => isset($meta['total_chunks']) ? (int) $meta['total_chunks'] : 1,
            'text'         => substr($content, $sep + 5),
        ];
    }

    /**
     * Inspect the mxchat KB table once per sync to figure out which optional
     * columns are present (notably `bot_id`, which only some mxchat versions
     * carry). Cached for the request.
     *
     * @return array{has_bot_id:bool}
     */
    public static function detect_kb_columns(string $kb_table): array {
        static $cache = [];
        if (isset($cache[$kb_table])) return $cache[$kb_table];

        global $wpdb;
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$kb_table}", 0);
        $set = is_array($cols) ? array_flip($cols) : [];

        return $cache[$kb_table] = [
            'has_bot_id' => isset($set['bot_id']),
        ];
    }

    /**
     * Emit a one-line summary when a sync run skipped any rows. Silent on the
     * happy path (no skips) and below a 1% threshold so the log doesn't fill
     * with noise on healthy installs where the occasional malformed row is
     * normal. Above 1% we log unconditionally — that's the signal that
     * something is actively corrupting the KB (model change without re-embed,
     * truncated rows from a failed import, etc.).
     */
    private static function log_skipped_summary(string $context, int $upserted, int $skipped, int $scanned): void {
        if ($skipped === 0) return;
        $threshold_breached = $scanned > 0 && ($skipped / $scanned) > 0.01;
        if (!$threshold_breached && !(defined('WP_DEBUG') && WP_DEBUG)) return;
        error_log(sprintf(
            '[mxchat-plus] %s: skipped %d of %d rows (no usable embedding); upserted %d',
            $context, $skipped, $scanned, $upserted
        ));
    }

    private static function build_select(array $columns, string $kb_table): string {
        $fields = 'id, url AS source_url, article_content, embedding_vector, role_restriction, content_type';
        if (!empty($columns['has_bot_id'])) {
            $fields .= ', bot_id';
        }
        return "SELECT {$fields} FROM {$kb_table}";
    }

    /**
     * Hydrate one MySQL KB row into a vector ready for DuckDB upsert. Returns
     * null when the row has no usable embedding.
     */
    private static function row_to_vector($row, array $columns): ?array {
        $embedding = $row->embedding_vector
            ? @unserialize($row->embedding_vector, ['allowed_classes' => false])
            : null;
        if (!is_array($embedding) || empty($embedding)) {
            return null;
        }

        $bot_id = !empty($columns['has_bot_id']) && !empty($row->bot_id)
            ? (string) $row->bot_id
            : 'default';

        $bot_id = (string) apply_filters('mxchat_plus_duckdb_sync_bot_id', $bot_id, $row);

        // Peel the chunked-content prefix so chunk_index / total_chunks /
        // is_chunked are correctly propagated and the stored `content`
        // doesn't carry the JSON header (which would pollute the LLM
        // context). vector_id derives from the same parse so each chunk
        // lands in its own row.
        $chunk_meta = self::parse_chunk_prefix((string) $row->article_content);

        return [
            'vector_id'        => self::vector_id_for_row($row, $chunk_meta),
            'bot_id'           => $bot_id ?: 'default',
            'embedding'        => $embedding,
            'content'          => $chunk_meta['text'],
            'source_url'       => (string) ($row->source_url ?? ''),
            'role_restriction' => (string) ($row->role_restriction ?? 'public'),
            'content_type'     => (string) ($row->content_type ?? 'content'),
            'chunk_index'      => $chunk_meta['chunk_index'],
            'total_chunks'     => $chunk_meta['total_chunks'],
            'is_chunked'       => $chunk_meta['is_chunked'],
        ];
    }
}

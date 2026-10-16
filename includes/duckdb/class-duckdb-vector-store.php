<?php
/**
 * Vector store on top of a DuckDB connection. Orchestrator that delegates:
 *
 *   - schema lifecycle  → MxChat_Plus_DuckDB_Vector_Store_Schema
 *   - read / top-K path → MxChat_Plus_DuckDB_Vector_Store_Query
 *
 * Owns the write path (upsert, delete), the lifecycle helpers (count,
 * list_ids, fetch_by_ids), the post-cache role resolution and the Parquet
 * I/O. Keeps the public API stable — existing call-sites (sync, REST proxy,
 * admin, CLI, tests) all keep compiling without change.
 *
 * Schema (managed by Vector_Store_Schema, migration target v4):
 *   CREATE TABLE mxchat_vectors (
 *       vector_id         VARCHAR NOT NULL,
 *       bot_id            VARCHAR NOT NULL DEFAULT 'default',
 *       embedding         FLOAT[<dim>] or TINYINT[<dim>] when embedding_storage='int8',
 *       content           TEXT,
 *       source_url        VARCHAR,
 *       role_restriction  VARCHAR DEFAULT 'public',
 *       content_type      VARCHAR DEFAULT 'content',
 *       chunk_index       INTEGER,
 *       total_chunks      INTEGER,
 *       is_chunked        BOOLEAN DEFAULT FALSE,
 *       created_at        TIMESTAMP DEFAULT current_timestamp,
 *       updated_at        TIMESTAMP DEFAULT current_timestamp,
 *       title             VARCHAR,          -- MxChat 3.2.23+ page title
 *       parent_url_hash   VARCHAR,          -- chunk metadata
 *       created_unix      BIGINT,           -- host metadata created_at
 *       last_updated_unix BIGINT,           -- host metadata last_updated
 *       extra_metadata    VARCHAR,          -- JSON of add-on metadata keys
 *       row_key           VARCHAR,          -- bot_id || chr(31) || vector_id
 *       PRIMARY KEY (bot_id, vector_id)
 *   );
 * Plus an HNSW index on the embedding column (VSS extension) when enabled,
 * and an FTS index on content (document id row_key) for hybrid search.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Vector_Store {

    use MxChat_Plus_DuckDB_SQL_Helpers_Trait;

    /**
     * MotherDuck enforces a HTTP body size limit per query; keep upsert
     * batches well under it. ~50 rows × 1536 dims ≈ 1 MB of SQL.
     */
    const UPSERT_CHUNK_ROWS_HTTP = 50;
    const UPSERT_CHUNK_ROWS_LOCAL = 250;

    /** Title column width — mirrors MxChat_Utils::clean_title() (MxChat 3.2.25). */
    const TITLE_MAX_CHARS = 255;

    /** Cap on the JSON kept for add-on metadata keys, per row. */
    const EXTRA_METADATA_MAX_BYTES = 4096;

    /**
     * Metadata keys stored in their own column (or pure aliases of one) and
     * so never copied into extra_metadata. 'codes' is the document-index
     * token field MxChat_Pinecone_Documents adds (MxChat 3.2.25); it is
     * derived from the text and rebuilt on write.
     */
    const KNOWN_METADATA_KEYS = [
        'text', 'content', 'source_url', 'url', 'type', 'content_type',
        'role_restriction', 'chunk_index', 'total_chunks', 'is_chunked',
        'title', 'parent_url_hash', 'created_at', 'last_updated', 'bot_id',
        'embedding', 'codes', '_id', '_score',
    ];

    /**
     * Host literal: MxChat's per-vector role table for Pinecone storage
     * ({prefix}mxchat_pinecone_roles, created by mxchat_create_pinecone_roles_table()
     * in mxchat-basic.php, MxChat 3.2.25). Read-only for us.
     */
    const HOST_ROLES_TABLE = 'mxchat_pinecone_roles';

    /** Ids per `IN (…)` of the roles lookup. */
    const ROLE_LOOKUP_CHUNK = 200;

    /**
     * Above this many matches the roles table is not consulted (only the
     * 'public' claim is stripped, so the host still resolves each id itself).
     * The chat path tops out at 1000 (the host clamps topK); bigger result
     * sets are the host's admin listing sweeps, which re-resolve roles on
     * their own (MxChat_Pinecone_Manager, MxChat 3.2.25).
     */
    const ROLE_RESOLVE_MAX_MATCHES = 1000;

    /** Page-size ceiling of list_ids_page() (Pinecone's own is 100). */
    const LIST_PAGE_MAX = 1000;

    /** Prefix of our opaque /vectors/list pagination tokens. */
    const LIST_TOKEN_PREFIX = 'mxp1.';

    protected MxChat_Plus_DuckDB_Connection $conn;
    protected string $table;
    protected int $dim;
    protected string $metric;
    protected bool $hnsw;
    protected string $storage; // 'float32' | 'int8'

    /**
     * Primary-side schema. When the connection is a Mirrored wrapper,
     * this is bound to the primary (MotherDuck) connection — it owns
     * the schema_meta on the canonical side. When the connection is a
     * plain Embedded / MotherDuck connection, this is the only schema.
     */
    private MxChat_Plus_DuckDB_Vector_Store_Schema $schema;

    /**
     * Local-side schema, only constructed when the connection is a
     * Mirrored wrapper. The mirror needs schema migrations applied to
     * BOTH sides — per-side, because each side answers
     * supports_capability() differently (primary refuses VSS, local
     * supports it), so going through the wrapper's write-through would
     * try to CREATE INDEX … USING HNSW on MotherDuck and throw before
     * ever reaching local. Running the migration twice with separate
     * Schema instances is the clean fix.
     */
    private ?MxChat_Plus_DuckDB_Vector_Store_Schema $local_schema = null;

    private MxChat_Plus_DuckDB_Vector_Store_Query $query;

    /** Per-request cached default instance, returned by current(). */
    private static ?self $current = null;

    /** @var bool|null per-request memo: does the host roles table exist? */
    private static ?bool $roles_table_exists = null;

    /**
     * Per-request cached store keyed off the current plugin options. Mutating
     * options invalidates the cache via reset_current(), which is fired
     * alongside Connection_Factory::reset_cache() from the options sanitiser.
     *
     * When to use which constructor:
     *   - `Vector_Store::current()`  →  hot path (every request fires it).
     *     REST proxy handlers, search adapter, anywhere a chat conversation
     *     can hit the code per user message.
     *   - `new MxChat_Plus_DuckDB_Vector_Store()`  →  occasional / admin path.
     *     Bulk sync, CLI commands, Pinecone migrator, settings introspection,
     *     compactor cron — the option re-read cost is irrelevant compared to
     *     the work the call is about to do, and a fresh instance avoids
     *     coupling unit tests to the singleton.
     *   - `new MxChat_Plus_DuckDB_Vector_Store($conn)`  →  health probe + admin
     *     diagnostics that want to test a specific backend instance instead
     *     of the cached one.
     */
    public static function current(): self {
        return self::$current ??= new self();
    }

    public static function reset_current(): void {
        self::$current = null;
    }

    public function __construct(?MxChat_Plus_DuckDB_Connection $conn = null) {
        $opts = MxChat_Plus_DuckDB_Options::get();
        $this->conn    = $conn ?? MxChat_Plus_DuckDB_Connection_Factory::from_options($opts);
        $this->table   = $opts['table_name'];
        $this->dim     = (int) $opts['embedding_dim'];
        $this->metric  = $opts['distance_metric'];
        $this->hnsw    = !empty($opts['hnsw_enabled']);
        $this->storage = in_array($opts['embedding_storage'] ?? 'float32', ['float32', 'int8'], true)
            ? $opts['embedding_storage']
            : 'float32';

        // Under mirror, the schema migration MUST run per-side so each
        // side answers its own supports_capability() honestly (primary
        // refuses VSS / HNSW, local supports it). Going through the
        // wrapper's write-through would propagate the CREATE INDEX to
        // primary and throw before ever reaching local.
        if ($this->conn instanceof MxChat_Plus_DuckDB_Mirrored_Connection) {
            $this->schema = new MxChat_Plus_DuckDB_Vector_Store_Schema(
                $this->conn->primary_connection(),
                $this->table, $this->dim, $this->metric, $this->hnsw, $this->storage
            );
            $this->local_schema = new MxChat_Plus_DuckDB_Vector_Store_Schema(
                $this->conn->local_connection(),
                $this->table, $this->dim, $this->metric, $this->hnsw, $this->storage
            );
        } else {
            $this->schema = new MxChat_Plus_DuckDB_Vector_Store_Schema(
                $this->conn, $this->table, $this->dim, $this->metric, $this->hnsw, $this->storage
            );
        }
        $this->query = new MxChat_Plus_DuckDB_Vector_Store_Query(
            $this->conn, $this->table, $this->dim, $this->metric, $this->storage
        );
    }

    // ─── Schema delegation ──────────────────────────────────────────────

    public function ensure_schema(): void {
        $this->schema->ensure_schema();
        // Apply the same migration set on the local side independently
        // so each side's capability check is honoured (notably, HNSW
        // gets created on local but skipped on MotherDuck primary).
        $this->local_schema?->ensure_schema();
    }
    public function table_info(): ?array { return $this->schema->table_info(); }

    /**
     * The schema helper bound to the connection this store writes through
     * (the primary side under mirror) — for callers that need the shared
     * copy/introspection SQL builders.
     */
    public function schema(): MxChat_Plus_DuckDB_Vector_Store_Schema { return $this->schema; }

    /**
     * HNSW availability for the read path. Under mirror, reads come
     * from local — so the relevant verdict is local's. Without mirror,
     * the single primary schema is authoritative.
     */
    public function hnsw_available(): ?bool {
        return $this->local_schema?->hnsw_available() ?? $this->schema->hnsw_available();
    }

    /**
     * FTS availability for the hybrid (BM25 + vector) read path. Same
     * rationale as hnsw_available(): read-side is local under mirror.
     */
    public function fts_available(): ?bool {
        return $this->local_schema?->fts_available() ?? $this->schema->fts_available();
    }

    // ─── Query delegation ───────────────────────────────────────────────

    /**
     * Top-K similarity search returning Pinecone-shaped matches.
     * See MxChat_Plus_DuckDB_Vector_Store_Query::run() for the full pipeline.
     *
     * Roles are resolved AFTER the query cache (resolve_host_roles()), so a
     * role an admin changes in MxChat applies to the very next query
     * instead of after query_cache_ttl, and transients cached before this
     * release (which carried role_restriction 'public') are corrected on
     * the way out.
     *
     * @throws InvalidArgumentException on a malformed filter (→ HTTP 400).
     */
    public function query_pinecone_shape(array $embedding, int $top_k, string $bot_id = 'default', array $filter = []): array {
        $this->ensure_schema();
        $matches = $this->query->run($embedding, $top_k, $bot_id, $filter);
        return $this->resolve_host_roles($matches, $bot_id);
    }

    /**
     * Apply MxChat's own per-vector roles to a match list, so a restriction
     * the host stores in {prefix}mxchat_pinecone_roles reaches the access
     * check even for chunk matches.
     *
     * Why: MxChat's get_single_vector_role() (MxChat_Integrator, MxChat
     * 3.2.25) trusts metadata.role_restriction when it is non-empty and only
     * otherwise reads its roles table — and every role writer
     * (apply_role_restriction_to_post, tag rules, the per-page
     * "Knowledge base access" box) keys that table by md5(url) alone,
     * while chunk matches are md5(url)_chunk_N. So:
     *
     *   1. a row for the exact match id wins;
     *   2. else a row for the base id (`_chunk_N` stripped — the host does
     *      the same in its reimport restore) — deliberately stricter than
     *      a real Pinecone index, which leaks chunked pages here;
     *   3. else a non-public role stored on the DuckDB row (MySQL sync);
     *   4. else the key is OMITTED, never set to 'public', so the host still
     *      runs its own lookup.
     *
     * Among several table rows for one id (the table is unique on
     * vector_id + bot_id, but no host writer sets bot_id), the row for this
     * bot wins, then 'default', then any. Read-only; the host table stays the
     * source of truth and is never written.
     *
     * @param array<int, array<string, mixed>> $matches
     * @return array<int, array<string, mixed>>
     */
    public function resolve_host_roles(array $matches, string $bot_id = 'default'): array {
        if (empty($matches)) return $matches;

        $table_roles = count($matches) <= self::ROLE_RESOLVE_MAX_MATCHES
            ? $this->lookup_host_roles($matches, $bot_id)
            : [];

        foreach ($matches as $i => $m) {
            if (!is_array($m)) continue;
            $id   = (string) ($m['id'] ?? '');
            $meta = isset($m['metadata']) && is_array($m['metadata']) ? $m['metadata'] : [];
            $stored = trim((string) ($meta['role_restriction'] ?? ''));
            if (strtolower($stored) === 'public') $stored = '';

            $role = $table_roles[$id] ?? $table_roles[self::base_vector_id($id)] ?? $stored;
            if ($role !== '' && strtolower($role) !== 'public') {
                $meta['role_restriction'] = $role;
            } else {
                unset($meta['role_restriction']);
            }
            $matches[$i]['metadata'] = $meta;
        }
        return $matches;
    }

    /** md5(url)_chunk_N → md5(url); any other id unchanged. */
    public static function base_vector_id(string $vector_id): string {
        return (string) preg_replace('/_chunk_\d+$/', '', $vector_id);
    }

    /**
     * vector_id → role from the host roles table, for every match id and
     * its base id. Empty when the table is absent or unreadable.
     *
     * @param array<int, array<string, mixed>> $matches
     * @return array<string, string>
     */
    private function lookup_host_roles(array $matches, string $bot_id): array {
        // Read through $GLOBALS rather than `global $wpdb` + is_object():
        // this also runs from WP-CLI and the unit suite, where the global
        // may be missing, and an is_object() narrowing would erase the
        // wpdb type PHPStan needs for the calls below.
        /** @var \wpdb|null $wpdb */
        $wpdb = $GLOBALS['wpdb'] ?? null;
        if ($wpdb === null) {
            return [];
        }
        $table = (string) $wpdb->prefix . self::HOST_ROLES_TABLE;
        if (self::$roles_table_exists === null) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            self::$roles_table_exists = ((string) $found === $table);
        }
        if (!self::$roles_table_exists) return [];

        $ids = [];
        foreach ($matches as $m) {
            $id = is_array($m) ? (string) ($m['id'] ?? '') : '';
            if ($id === '') continue;
            $ids[$id] = true;
            $ids[self::base_vector_id($id)] = true;
        }
        if (empty($ids)) return [];

        // Per id: [rank, role] — rank 0 = this bot, 1 = 'default', 2 = other.
        // `SELECT *`: the bot_id column only exists once the host's
        // mxchat_migrate_pinecone_roles_add_bot_id() has run; a row without
        // it ranks as 'default'.
        $best = [];
        foreach (array_chunk(array_keys($ids), self::ROLE_LOOKUP_CHUNK) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE vector_id IN ({$placeholders})",
                $chunk
            ));
            if (!is_array($rows)) continue;
            foreach ($rows as $row) {
                $row = (array) $row;
                $vid  = (string) ($row['vector_id'] ?? '');
                $role = trim((string) ($row['role_restriction'] ?? ''));
                if ($vid === '' || $role === '') continue;
                $row_bot = (string) ($row['bot_id'] ?? 'default');
                $rank = $row_bot === $bot_id ? 0 : ($row_bot === 'default' ? 1 : 2);
                if (!isset($best[$vid]) || $rank < $best[$vid][0]) {
                    $best[$vid] = [$rank, $role];
                }
            }
        }
        $out = [];
        foreach ($best as $vid => $pair) {
            $out[(string) $vid] = $pair[1];
        }
        return $out;
    }

    /** Test hook: forget the per-request "roles table exists" memo. */
    public static function reset_host_roles_cache(): void {
        self::$roles_table_exists = null;
    }

    // ─── Write path ─────────────────────────────────────────────────────

    /**
     * Upsert a batch of vector rows. Each row is an associative array; the
     * required keys (`vector_id`, `embedding`) and the embedding dimension are
     * validated per row in upsert_chunk() at runtime (it throws / skips), so
     * the param is typed loosely — the rows are assembled from DB/REST sources
     * PHPStan can't pin to an exact shape at the call sites.
     *
     * Recognised keys:
     *   vector_id (string, required), embedding (numeric[], required),
     *   bot_id, content, source_url, content_type (strings),
     *   role_restriction (string; null/absent → the column default 'public',
     *     which the read path treats as "no role"),
     *   chunk_index, total_chunks (int|null), is_chunked (bool),
     *   title (string|null — normalised like MxChat_Utils::clean_title()),
     *   parent_url_hash (string|null),
     *   created_at, last_updated (unix int|null — the host's metadata
     *     timestamps; stored in created_unix / last_updated_unix),
     *   extra_metadata (array<string, scalar|list<scalar>>|null — add-on
     *     metadata keys, kept as JSON up to EXTRA_METADATA_MAX_BYTES).
     * row_key and updated_at are always computed here.
     *
     * Rows are keyed on (bot_id, vector_id): the same id under two bots is
     * two rows, as with two Pinecone namespaces. Within one batch the last
     * row for a key wins (DuckDB refuses to upsert one key twice in a
     * statement).
     *
     * @param array<int, array<string, mixed>> $vectors
     */
    public function upsert(array $vectors): int {
        if (empty($vectors)) return 0;

        // max(1, …): a filter override could return ≤0; array_chunk requires ≥1.
        $chunk_size = max(1, $this->upsert_chunk_size());
        $total = 0;
        foreach (array_chunk($vectors, $chunk_size) as $chunk) {
            $total += $this->upsert_chunk($chunk);
        }
        if ($total > 0) {
            // Flush only the bot(s) this batch actually wrote — a sync on one
            // bot must not invalidate another bot's cached top-Ks.
            $bots = [];
            foreach ($vectors as $v) {
                $bots[self::row_bot_id($v)] = true;
            }
            foreach (array_keys($bots) as $bot) {
                MxChat_Plus_DuckDB_Cache::flush_query_cache((string) $bot);
            }
        }
        return $total;
    }

    /**
     * @param array<int, array<string, mixed>> $vectors
     */
    private function upsert_chunk(array $vectors): int {
        $rows = [];
        foreach ($vectors as $v) {
            if (!isset($v['vector_id']) || $v['vector_id'] === '') continue;
            if (!isset($v['embedding']) || !is_array($v['embedding'])) continue;
            if (count($v['embedding']) !== $this->dim) {
                throw new RuntimeException(sprintf(
                    /* translators: 1: vector id, 2: expected dim, 3: actual dim */
                    __('Embedding dimension mismatch on upsert: vector_id=%1$s, expected %2$d, got %3$d.', 'mxchat-plus'),
                    $v['vector_id'],
                    $this->dim,
                    count($v['embedding'])
                ));
            }

            $embedding_for_storage = $this->storage === 'int8'
                ? MxChat_Plus_DuckDB_Quantization::quantize_int8($v['embedding'])
                : $v['embedding'];

            $id_lit  = $this->literal_string((string) $v['vector_id']);
            $bot     = self::row_bot_id($v);
            $bot_lit = $this->literal_string($bot);
            $role    = isset($v['role_restriction']) && trim((string) $v['role_restriction']) !== ''
                ? (string) $v['role_restriction']
                : 'public';
            $title   = self::normalize_title($v['title'] ?? null);
            $parent  = isset($v['parent_url_hash']) ? substr(trim((string) $v['parent_url_hash']), 0, 64) : '';
            $created = self::normalize_unix($v['created_at'] ?? null);
            $updated = self::normalize_unix($v['last_updated'] ?? null);
            $extra   = self::encode_extra_metadata($v['extra_metadata'] ?? null);

            // Keyed by (bot, id): the last row for a key wins.
            $rows[$bot . "\x1F" . (string) $v['vector_id']] = sprintf(
                '(%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, current_timestamp, %s, %s, %s, %s, %s, %s)',
                $id_lit,
                $bot_lit,
                $this->literal_int_or_float_array($embedding_for_storage),
                $this->literal_string((string) ($v['content'] ?? '')),
                $this->literal_string((string) ($v['source_url'] ?? '')),
                $this->literal_string($role),
                $this->literal_string((string) ($v['content_type'] ?? 'content')),
                isset($v['chunk_index']) && is_numeric($v['chunk_index']) ? (int) $v['chunk_index'] : 'NULL',
                isset($v['total_chunks']) && is_numeric($v['total_chunks']) ? (int) $v['total_chunks'] : 'NULL',
                self::truthy($v['is_chunked'] ?? false) ? 'TRUE' : 'FALSE',
                $title === null ? 'NULL' : $this->literal_string($title),
                $parent === '' ? 'NULL' : $this->literal_string($parent),
                $created === null ? 'NULL' : (string) $created,
                $updated === null ? 'NULL' : (string) $updated,
                $extra === null ? 'NULL' : $this->literal_string($extra),
                MxChat_Plus_DuckDB_Vector_Store_Schema::row_key_sql($bot_lit, $id_lit)
            );
        }

        if (empty($rows)) return 0;

        $sql = sprintf(
            'INSERT OR REPLACE INTO %s (vector_id, bot_id, embedding, content, source_url, role_restriction, content_type, chunk_index, total_chunks, is_chunked, updated_at, title, parent_url_hash, created_unix, last_updated_unix, extra_metadata, row_key) VALUES %s',
            $this->quote_ident($this->table),
            implode(",\n", $rows)
        );
        $this->conn->execute($sql);
        return count($rows);
    }

    /** @param array<string, mixed> $v */
    private static function row_bot_id(array $v): string {
        $bot = (string) ($v['bot_id'] ?? '');
        return $bot !== '' ? $bot : 'default';
    }

    /** @param mixed $v */
    private static function truthy($v): bool {
        if (is_string($v)) {
            return filter_var($v, FILTER_VALIDATE_BOOLEAN);
        }
        return !empty($v);
    }

    /**
     * Same normalisation as MxChat_Utils::clean_title() (MxChat 3.2.25):
     * entities decoded, tags stripped, whitespace collapsed, capped at 255
     * characters. Null when nothing is left, so a re-index without a title
     * stores NULL rather than ''.
     *
     * @param mixed $title
     */
    public static function normalize_title($title): ?string {
        if (!is_scalar($title)) return null;
        $t = html_entity_decode((string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($t) : strip_tags($t);
        $t = trim((string) preg_replace('/\s+/u', ' ', $t));
        if ($t === '') return null;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($t, 'UTF-8') > self::TITLE_MAX_CHARS) {
                $t = rtrim(mb_substr($t, 0, self::TITLE_MAX_CHARS, 'UTF-8'));
            }
        } elseif (strlen($t) > self::TITLE_MAX_CHARS) {
            $t = rtrim(substr($t, 0, self::TITLE_MAX_CHARS));
        }
        return $t;
    }

    /**
     * A unix timestamp from host metadata (MxChat sends time() ints; the
     * document index returns floats; anything else is parsed as a date).
     *
     * @param mixed $value
     */
    public static function normalize_unix($value): ?int {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $n = (int) $value;
            return $n > 0 ? $n : null;
        }
        if (is_string($value) && trim($value) !== '') {
            $ts = strtotime($value);
            return $ts !== false && $ts > 0 ? $ts : null;
        }
        return null;
    }

    /**
     * JSON for the add-on metadata keys: string keys only, scalar or
     * list-of-scalar values (Pinecone's own metadata types), known keys
     * excluded, capped at EXTRA_METADATA_MAX_BYTES (keys that would overflow
     * are skipped). Null when nothing is kept.
     *
     * @param mixed $extra
     */
    public static function encode_extra_metadata($extra): ?string {
        if (!is_array($extra) || $extra === []) return null;
        $kept = [];
        $size = 2; // "{}"
        foreach ($extra as $k => $val) {
            if (!is_string($k) || $k === '' || strlen($k) > 64 || in_array($k, self::KNOWN_METADATA_KEYS, true)) continue;
            if (is_array($val)) {
                if (!array_is_list($val)) continue;
                $ok = true;
                foreach ($val as $item) {
                    if (!is_scalar($item)) { $ok = false; break; }
                }
                if (!$ok) continue;
            } elseif (!is_scalar($val)) {
                continue;
            }
            $piece = json_encode([$k => $val], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($piece)) continue;
            $piece_size = strlen($piece) - 2 + ($kept === [] ? 0 : 1);
            if ($size + $piece_size > self::EXTRA_METADATA_MAX_BYTES) continue;
            $kept[$k] = $val;
            $size += $piece_size;
        }
        if ($kept === []) return null;
        $json = json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : null;
    }

    /**
     * Translate one Pinecone vector (id + values + metadata, as MxChat sends
     * it to /vectors/upsert or as a Pinecone index returns it) into an
     * upsert() row. Shared by the REST proxy and the Pinecone migrator so
     * both map the same keys:
     *
     *   text (alias content), source_url (aliases source, url), type (alias
     *   content_type), chunk_index (alias part_index), total_chunks (alias
     *   part_total), is_chunked, title, parent_url_hash, created_at,
     *   last_updated; role_restriction only when the source carries one
     *   (null otherwise — never an invented 'public'); every other key goes
     *   to extra_metadata. MxChat builds the AI-Engine-style aliases
     *   (`source`/`part_index`/`part_total`) in its chunk metadata and passes
     *   them through `mxchat_embedding_chunk_metadata`, but
     *   MxChat_Utils::store_chunk_in_pinecone() never writes them to
     *   Pinecone (MxChat 3.2.25); they are honoured here defensively, as
     *   fallbacks that also round-trip as extra keys.
     *
     * $bot_id is the caller's decision (the proxy uses the token's
     * namespace, never metadata.bot_id, so a token cannot write into another
     * bot).
     *
     * @param array<int|string, mixed> $values
     * @param array<string, mixed>     $metadata
     * @return array<string, mixed>
     */
    public static function row_from_pinecone(string $id, array $values, array $metadata, string $bot_id): array {
        $m = $metadata;
        $pick = function (array $keys) use ($m) {
            foreach ($keys as $k) {
                if (isset($m[$k]) && $m[$k] !== '') return $m[$k];
            }
            return null;
        };
        $chunk_index  = $pick(['chunk_index', 'part_index']);
        $total_chunks = $pick(['total_chunks', 'part_total']);
        $role         = isset($m['role_restriction']) && is_scalar($m['role_restriction']) && trim((string) $m['role_restriction']) !== ''
            ? (string) $m['role_restriction']
            : null;
        $content_type = $pick(['type', 'content_type']);
        $text         = $pick(['text', 'content']);
        $source       = $pick(['source_url', 'source', 'url']);
        $parent       = $pick(['parent_url_hash']);

        $extra = [];
        foreach ($m as $k => $v) {
            if (is_string($k) && !in_array($k, self::KNOWN_METADATA_KEYS, true)) {
                $extra[$k] = $v;
            }
        }

        return [
            'vector_id'        => $id,
            'bot_id'           => $bot_id !== '' ? $bot_id : 'default',
            'embedding'        => array_values($values),
            'content'          => is_scalar($text) ? (string) $text : '',
            'source_url'       => is_scalar($source) ? (string) $source : '',
            'role_restriction' => $role,
            'content_type'     => is_scalar($content_type) ? (string) $content_type : 'content',
            'chunk_index'      => is_numeric($chunk_index) ? (int) $chunk_index : null,
            'total_chunks'     => is_numeric($total_chunks) ? (int) $total_chunks : null,
            'is_chunked'       => isset($m['is_chunked']) && self::truthy($m['is_chunked']),
            'title'            => self::normalize_title($m['title'] ?? null),
            'parent_url_hash'  => is_scalar($parent) ? (string) $parent : null,
            'created_at'       => self::normalize_unix($m['created_at'] ?? null),
            'last_updated'     => self::normalize_unix($m['last_updated'] ?? null),
            'extra_metadata'   => $extra === [] ? null : $extra,
        ];
    }

    private function upsert_chunk_size(): int {
        $opts = MxChat_Plus_DuckDB_Options::get();
        $is_remote = ($opts['mode'] ?? '') === 'motherduck';
        $default = $is_remote ? self::UPSERT_CHUNK_ROWS_HTTP : self::UPSERT_CHUNK_ROWS_LOCAL;
        return (int) apply_filters('mxchat_plus_duckdb_upsert_chunk_size', $default, $is_remote);
    }

    /**
     * Max vector_ids per DELETE statement. The proxy passes mxchat's bulk-delete
     * ID arrays straight through (chunk cleanup, session purge), which can be
     * large; one giant `IN (...)` inflates the SQL string and overruns
     * MotherDuck's HTTP body limit (and the CLI's stdin). The compactor already
     * chunks its orphan deletes at 100 for the same reason — match it here.
     */
    const DELETE_ID_CHUNK = 100;

    /**
     * Delete ids of ONE bot. Scoped on (bot_id, vector_id): the same id
     * under another bot is another row and stays.
     */
    public function delete_by_ids(array $vector_ids, string $bot_id = 'default'): int {
        if (empty($vector_ids)) return 0;

        $ids = array_values(array_unique(array_map('strval', $vector_ids)));
        $table_q = $this->quote_ident($this->table);
        $bot_q   = $this->literal_string($bot_id);

        foreach (array_chunk($ids, self::DELETE_ID_CHUNK) as $batch) {
            $list = implode(',', array_map([$this, 'literal_string'], $batch));
            $this->conn->execute(sprintf(
                'DELETE FROM %s WHERE bot_id = %s AND vector_id IN (%s)',
                $table_q, $bot_q, $list
            ));
        }
        MxChat_Plus_DuckDB_Cache::flush_query_cache($bot_id);
        return count($ids);
    }

    public function delete_by_source_url(string $source_url, string $bot_id = 'default'): int {
        $sql = sprintf(
            'DELETE FROM %s WHERE bot_id = %s AND source_url = %s',
            $this->quote_ident($this->table),
            $this->literal_string($bot_id),
            $this->literal_string($source_url)
        );
        $this->conn->execute($sql);
        MxChat_Plus_DuckDB_Cache::flush_query_cache($bot_id);
        return 1;
    }

    // ─── Lookup / inspection ────────────────────────────────────────────

    /**
     * Distinct bot_ids present in the store, ascending, capped at $limit.
     * Used by the admin "Per-bot overrides" UI to list the bots an operator
     * can tune. Returns [] on any backend error (the UI degrades gracefully).
     *
     * @return string[]
     */
    public function list_bot_ids(int $limit = 200): array {
        $limit = max(1, min(1000, $limit));
        $rows = $this->conn->execute(sprintf(
            'SELECT DISTINCT bot_id FROM %s ORDER BY bot_id LIMIT %d',
            $this->quote_ident($this->table),
            $limit
        ));
        $out = [];
        foreach ($rows as $r) {
            $bid = (string) ($r['bot_id'] ?? '');
            if ($bid !== '') $out[] = $bid;
        }
        return $out;
    }

    public function count(string $bot_id = 'default'): int {
        $rows = $this->conn->execute(sprintf(
            'SELECT COUNT(*) AS c FROM %s WHERE bot_id = %s',
            $this->quote_ident($this->table),
            $this->literal_string($bot_id)
        ));
        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * Vector count per bot, in one GROUP BY — the shape Pinecone's
     * describe_index_stats reports per namespace. Bots with no vectors never
     * appear (as Pinecone omits empty namespaces; MxChat reads a missing
     * namespace as 0). Ascending by bot_id, capped at $limit bots.
     *
     * @return array<string, int> bot_id => vector count
     */
    public function count_by_bot(int $limit = 1000): array {
        $limit = max(1, min(10000, $limit));
        $rows = $this->conn->execute(sprintf(
            'SELECT bot_id, COUNT(*) AS c FROM %s GROUP BY bot_id ORDER BY bot_id LIMIT %d',
            $this->quote_ident($this->table),
            $limit
        ));
        $out = [];
        foreach ($rows as $r) {
            $bot = (string) ($r['bot_id'] ?? '');
            $c   = (int) ($r['c'] ?? 0);
            if ($bot !== '' && $c > 0) $out[$bot] = $c;
        }
        return $out;
    }

    /** Configured embedding dimension (describe_index_stats `dimension`). */
    public function dimension(): int { return $this->dim; }

    /**
     * Offset-paginated vector_id listing, optionally narrowed to IDs starting
     * with a given prefix. Kept for back-compat; prefer list_ids_page(),
     * whose keyset cursor is stable while the host deletes as it pages.
     *
     * Ordered by vector_id ascending — the order a real Pinecone /vectors/list
     * returns, so `{md5}_chunk_0` comes first, and stable for paging (the
     * previous `created_at DESC` order tied every chunk of one upsert,
     * which DuckDB stamps with one transaction timestamp).
     *
     * The prefix is wrapped in `LIKE '<escaped>%' ESCAPE '\'` so the SQL
     * wildcards `%` and `_` inside a caller-supplied prefix are treated
     * literally — md5 hashes contain no wildcards in practice, but the
     * underscore in `_chunk_` is also a SQL LIKE wildcard, so this is
     * required for correctness, not just defence in depth.
     *
     * @return list<string>
     */
    public function list_ids(string $bot_id = 'default', int $limit = 100, int $offset = 0, ?string $prefix = null): array {
        $rows = $this->conn->execute(sprintf(
            'SELECT vector_id FROM %s WHERE %s ORDER BY vector_id LIMIT %d OFFSET %d',
            $this->quote_ident($this->table),
            $this->list_where($bot_id, $prefix),
            max(1, $limit),
            max(0, $offset)
        ));
        return array_values(array_map(fn($r) => (string) ($r['vector_id'] ?? ''), $rows));
    }

    /**
     * One page of a bot's vector ids, ascending, after an opaque cursor —
     * the contract of Pinecone's GET /vectors/list (`paginationToken` in,
     * `pagination.next` out).
     *
     * Keyset, not OFFSET: MxChat deletes while it pages (REST
     * DELETE /knowledge, straggler sweeps), and an offset would skip rows.
     * Fetches $limit + 1 rows and returns a non-empty `next` only when more
     * rows exist — the host's paging loops stop on an empty `next`, so an
     * empty last page is never needed and a token is never echoed back
     * unhonoured. An undecodable token restarts from the beginning.
     *
     * @return array{ids: list<string>, next: string}
     */
    public function list_ids_page(string $bot_id = 'default', int $limit = 100, string $pagination_token = '', ?string $prefix = null): array {
        $limit = max(1, min(self::LIST_PAGE_MAX, $limit));
        $where = $this->list_where($bot_id, $prefix);
        $after = $pagination_token !== '' ? self::decode_list_token($pagination_token) : null;
        if ($after !== null) {
            $where .= sprintf(' AND vector_id > %s', $this->literal_string($after));
        }
        $rows = $this->conn->execute(sprintf(
            'SELECT vector_id FROM %s WHERE %s ORDER BY vector_id LIMIT %d',
            $this->quote_ident($this->table),
            $where,
            $limit + 1
        ));
        $ids = [];
        foreach ($rows as $r) {
            $id = (string) ($r['vector_id'] ?? '');
            if ($id !== '') $ids[] = $id;
        }
        $next = '';
        if (count($ids) > $limit) {
            $ids  = array_slice($ids, 0, $limit);
            $next = self::encode_list_token((string) end($ids));
        }
        return ['ids' => $ids, 'next' => $next];
    }

    /** Opaque pagination token for list_ids_page(): the last id returned. */
    public static function encode_list_token(string $last_vector_id): string {
        return self::LIST_TOKEN_PREFIX . rtrim(strtr(base64_encode($last_vector_id), '+/', '-_'), '=');
    }

    /** Inverse of encode_list_token(); null for anything it did not produce. */
    public static function decode_list_token(string $token): ?string {
        if (!str_starts_with($token, self::LIST_TOKEN_PREFIX)) return null;
        $body = substr($token, strlen(self::LIST_TOKEN_PREFIX));
        if ($body === '' || preg_match('/[^A-Za-z0-9_-]/', $body) === 1) return null;
        $raw = base64_decode(strtr($body, '-_', '+/'), true);
        if (!is_string($raw) || $raw === '' || strlen($raw) > 512) return null;
        return $raw;
    }

    private function list_where(string $bot_id, ?string $prefix): string {
        $where = sprintf('bot_id = %s', $this->literal_string($bot_id));
        if ($prefix !== null && $prefix !== '') {
            $escaped = strtr($prefix, [
                '\\' => '\\\\',
                '%'  => '\\%',
                '_'  => '\\_',
            ]);
            $where .= sprintf(" AND vector_id LIKE %s ESCAPE '\\'", $this->literal_string($escaped . '%'));
        }
        return $where;
    }

    /**
     * Pinecone /vectors/fetch shape: id => {id, metadata[, values]}. The
     * metadata comes from Vector_Store_Query::metadata_from_row(), so it
     * carries title, timestamps, bot_id, parent_url_hash and add-on keys,
     * and role_restriction only for a stored non-public role. $with_values
     * adds the (dequantised) embedding, as real Pinecone returns it.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fetch_by_ids(array $vector_ids, string $bot_id = 'default', bool $with_values = false): array {
        if (empty($vector_ids)) return [];
        $ids = array_values(array_unique(array_map('strval', $vector_ids)));
        $out = [];
        foreach (array_chunk($ids, self::DELETE_ID_CHUNK) as $batch) {
            $list = implode(',', array_map([$this, 'literal_string'], $batch));
            $rows = $this->conn->execute(sprintf(
                'SELECT vector_id AS id, %s%s
                 FROM %s WHERE bot_id = %s AND vector_id IN (%s)',
                MxChat_Plus_DuckDB_Vector_Store_Query::MATCH_COLUMNS_SQL,
                $with_values ? ', ' . $this->embedding_as_float_sql() . ' AS embedding_values' : '',
                $this->quote_ident($this->table),
                $this->literal_string($bot_id),
                $list
            ));
            foreach ($rows as $r) {
                $id = (string) ($r['id'] ?? '');
                if ($id === '') continue;
                $entry = [
                    'id'       => $id,
                    'metadata' => MxChat_Plus_DuckDB_Vector_Store_Query::metadata_from_row($r),
                ];
                if ($with_values && isset($r['embedding_values']) && is_array($r['embedding_values'])) {
                    $entry['values'] = array_map('floatval', array_values($r['embedding_values']));
                }
                $out[$id] = $entry;
            }
        }
        return $out;
    }

    // ─── Parquet I/O ────────────────────────────────────────────────────

    /**
     * Dump the entire table to a Parquet file on the DuckDB-side filesystem.
     * @throws RuntimeException on SQL error.
     */
    public function export_parquet(string $path): int {
        $this->ensure_schema();
        $safe_path = str_replace("'", "''", $path);
        $this->conn->execute(sprintf(
            "COPY %s TO '%s' (FORMAT PARQUET, COMPRESSION ZSTD)",
            $this->quote_ident($this->table),
            $safe_path
        ));
        $rows = $this->conn->execute(sprintf(
            'SELECT COUNT(*) AS c FROM %s',
            $this->quote_ident($this->table)
        ));
        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * Restore rows from a Parquet file produced by export_parquet() — of
     * this or any earlier schema version. Existing vectors with the same
     * (bot_id, vector_id) are replaced.
     *
     * Columns are matched BY NAME through an explicit list
     * (Vector_Store_Schema::copy_rows_sql()), not `SELECT *`: a pre-v4
     * backup has fewer columns than the v4 table, and a positional copy
     * would fail or shift values. Each statement reads the file directly
     * (no temp view), because the CLI fallback runs every statement in a
     * fresh process where a TEMP VIEW would already be gone.
     */
    public function import_parquet(string $path): int {
        $this->ensure_schema();
        $source = sprintf('read_parquet(%s)', $this->literal_string($path));

        $count_rows = $this->conn->execute(sprintf('SELECT COUNT(*) AS c FROM %s', $source));
        $expected = (int) ($count_rows[0]['c'] ?? 0);
        if ($expected === 0) return 0;

        $columns = $this->schema->describe_columns($source);
        if ($columns === []) {
            // Backend could not describe the file: assume a pre-v4 export
            // (the columns every schema version has had).
            $columns = ['vector_id', 'bot_id', 'embedding', 'content', 'source_url', 'role_restriction',
                        'content_type', 'chunk_index', 'total_chunks', 'is_chunked', 'created_at'];
        }
        $this->conn->execute($this->schema->copy_rows_sql('INSERT OR REPLACE', $source, $columns));

        // Every bot the file touched, plus every tracked one.
        MxChat_Plus_DuckDB_Cache::flush_query_cache();
        try {
            $bot_rows = $this->conn->execute(sprintf(
                'SELECT DISTINCT %s AS bot_id FROM %s LIMIT 1000',
                MxChat_Plus_DuckDB_Vector_Store_Schema::source_bot_sql($columns),
                $source
            ));
            foreach ($bot_rows as $r) {
                $b = (string) ($r['bot_id'] ?? '');
                if ($b !== '') MxChat_Plus_DuckDB_Cache::bump_cache_generation($b);
            }
        } catch (\Throwable $ignore) {
            // The global flush above already covered every tracked bot.
        }
        return $expected;
    }

    /**
     * Approximate footprint of the vectors table for the admin diagnostics
     * panel. Returns bytes_estimate + row_count + dim.
     */
    public function storage_estimate(): array {
        try {
            $bytes_per_value = $this->storage === 'int8' ? 1 : 4;
            $row_bytes = $bytes_per_value * $this->dim + 200; // metadata fudge factor
            $rows = $this->conn->execute(sprintf(
                'SELECT COUNT(*) AS c FROM %s',
                $this->quote_ident($this->table)
            ));
            $count = (int) ($rows[0]['c'] ?? 0);
            return [
                'rows'           => $count,
                'bytes_estimate' => $count * $row_bytes,
                'dim'            => $this->dim,
                'storage'        => $this->storage,
            ];
        } catch (\Throwable $e) {
            return ['rows' => 0, 'bytes_estimate' => 0, 'dim' => $this->dim, 'storage' => $this->storage];
        }
    }

    // ─── Accessors ──────────────────────────────────────────────────────

    public function connection(): MxChat_Plus_DuckDB_Connection { return $this->conn; }
    public function table_name(): string { return $this->table; }
    public function table_name_quoted(): string { return $this->quote_ident($this->table); }
}

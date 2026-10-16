<?php
/**
 * Options storage for MxChat DuckDB.
 *
 * Single WP option (mxchat_plus_duckdb_options) holding all settings. Mirrors the
 * structure of mxchat_pinecone_addon_options for symmetry.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Options {

    public static function defaults(): array {
        return [
            'enabled'             => false,
            'mode'                => 'motherduck',         // 'motherduck' | 'embedded'
            'motherduck_token'    => '',
            'motherduck_database' => 'my_db',
            'embedded_path'       => '',                   // resolved at runtime if empty
            'embedded_binary'     => '',                   // path to duckdb CLI, empty = autodetect
            // Local mirror for MotherDuck installs: shadows the cloud
            // database to a local `.duckdb` file with HNSW so reads run
            // fast. Only meaningful when `mode === 'motherduck'`.
            'motherduck_mirror_enabled' => false,
            'motherduck_mirror_path'    => '',             // resolved to uploads/.../mirror.duckdb when empty
            // Option B reach: MxChat reads its Pinecone config for the default
            // bot straight from `mxchat_pinecone_addon_options`, never through
            // the `mxchat_get_bot_pinecone_config` filter
            // (MxChat_Integrator::get_bot_pinecone_config,
            // MxChat_Utils::is_pinecone_enabled_for_bot,
            // MxChat_Pinecone_Documents::config — MxChat 3.2.25). Set this to
            // true to answer reads of that option with our proxy config, so
            // the default bot is served by DuckDB on stock MxChat. Explicit
            // opt-in: sites already using real Pinecone keep it. The stored
            // row keeps the real credentials — see
            // Search_Adapter::restore_real_pinecone_fields().
            'takeover_default_bot_pinecone' => false,
            // Multi-Bot routing (opt-in, like the default-bot takeover above).
            // With MxChat's Multi-Bot add-on loaded, MxChat asks
            // `mxchat_get_bot_pinecone_config` for every other bot, and
            // answering it moves that bot to the DuckDB proxy. Only the bots
            // opted in here are answered: all of them
            // (`takeover_multibot_bots`), or the listed ids
            // (`takeover_bot_ids`). Any other bot keeps the config the add-on
            // gave it — its own Pinecone index, or MxChat's shared WordPress
            // database. An install from before this setting has no
            // `takeover_bot_ids` key at all; bot_routing() keeps routing the
            // bots it was already serving.
            'takeover_multibot_bots' => false,
            'takeover_bot_ids'       => [],
            'table_name'          => 'mxchat_vectors',
            'embedding_dim'       => 1536,
            'distance_metric'     => 'cosine',
            'hnsw_enabled'        => true,
            'top_k'               => 50,
            'embedding_storage'   => 'float32',            // 'float32' | 'int8' (experimental)
            'hybrid_enabled'      => false,                // enable BM25 + vector hybrid scoring
            // Also blend BM25 on proxy queries while MxChat's own "Hybrid
            // keyword boost" (host option mxchat_hybrid_keyword_toggle) is
            // on. Off by default: MxChat documents that switch as having no
            // effect on a classic Pinecone index, which is what the proxy
            // is. Read through the proxy's
            // `mxchat_plus_duckdb_proxy_follow_host_hybrid_toggle` filter
            // (follow_host_hybrid_toggle_setting()).
            'hybrid_follow_host_toggle' => false,
            'hybrid_alpha'        => 0.7,                  // weight on vector score (1.0 = pure vector)
            'query_cache_enabled' => true,                 // cache top-K results per (embedding, filter)
            'query_cache_ttl'     => 300,                  // seconds
            'dedup_per_source'    => false,                // collapse multiple chunks from the same URL
            'slow_query_ms'       => 500,                  // log queries slower than this
            // Per-bot retrieval overrides (v0.13.0). Map of
            // bot_id => { <overridable key> => value } — see
            // overridable_bot_keys() + get_for_bot(). Empty = every bot uses
            // the global retrieval settings above.
            'bot_overrides'       => [],
            'last_sync_at'        => 0,
            'last_sync_count'     => 0,
            // Highest MySQL KB row id a sync has read: the incremental sync
            // also picks up every newer id, whatever timezone its
            // `timestamp` was written in.
            'last_sync_max_id'    => 0,
            'last_compact_at'     => 0,
            'last_error'          => '',
        ];
    }

    /**
     * Bot ids MxChat gives a meaning of its own (MxChat 3.2.25): 'default' is
     * the site-wide bot, served through the default-bot takeover, and the
     * admin Testing tab's 'testing' resolves to it
     * (MxChat_Integrator::get_bot_pinecone_config). Multi-Bot routing never
     * answers for them.
     */
    const RESERVED_BOT_IDS = ['default', 'testing'];

    /** Seconds between two attempts to record an older install's routing. */
    const ROUTING_MIGRATION_RETRY = 300;
    const ROUTING_MIGRATION_TRANSIENT = 'mxchat_plus_duckdb_routing_migration_wait';

    /** Most bot ids `takeover_bot_ids` holds (normalize_bot_ids()). */
    const MAX_ROUTED_BOT_IDS = 200;

    /**
     * Rows the routing upgrade lists (Vector_Store::list_bot_ids() clamps
     * to this). A listing that comes back full may have been cut short.
     */
    const ROUTING_MIGRATION_LIST_LIMIT = 1000;

    /**
     * Form-only fields of the Multi-Bot routing row, never stored. The
     * marker is rendered while an older install's routing is still not
     * recorded; the checkbox lets the owner keep routing every bot on
     * purpose. See sanitize_routing().
     */
    const ROUTING_LEGACY_FORM_FIELD = 'takeover_routing_legacy';
    const ROUTING_KEEP_ALL_FIELD    = 'takeover_routing_keep_all';

    /**
     * Settings that change which rows a query returns, or their order. The
     * query-cache key carries none of them, so a change flushes the cache
     * (handle_settings_saved()).
     */
    const RANKING_KEYS = ['hybrid_enabled', 'hybrid_alpha', 'hybrid_follow_host_toggle', 'dedup_per_source', 'distance_metric', 'bot_overrides'];

    /**
     * Settings that name the physical store. Changing one presents MxChat
     * with another store — usually an empty one.
     */
    const STORE_IDENTITY_KEYS = ['mode', 'motherduck_database', 'embedded_path', 'table_name'];

    public static function get(): array {
        $stored = get_option(MXCHAT_PLUS_DUCKDB_OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return array_merge(self::defaults(), $stored);
    }

    /**
     * Retrieval-quality settings that may be overridden per bot. Deliberately
     * excludes storage-level settings (backend mode, table, dimension, metric,
     * HNSW, storage layout) — all bots share one physical store — and `top_k`,
     * which mxchat supplies per request rather than reading from options. Only
     * the keys below are actually consulted per-bot by Vector_Store_Query::run().
     *
     * @return string[]
     */
    public static function overridable_bot_keys(): array {
        return [
            'hybrid_enabled',
            'hybrid_alpha',
            'dedup_per_source',
            'query_cache_enabled',
            'query_cache_ttl',
            'slow_query_ms',
        ];
    }

    /**
     * Global config with any per-bot overrides for $bot_id applied on top (only
     * the keys in overridable_bot_keys()), then passed through the
     * `mxchat_plus_duckdb_bot_config` filter so multi-bot installs can drive
     * per-bot retrieval from code instead of (or alongside) the admin UI.
     *
     * @return array<string,mixed>
     */
    public static function get_for_bot(string $bot_id): array {
        $base = self::get();
        $map  = is_array($base['bot_overrides'] ?? null) ? $base['bot_overrides'] : [];
        $ov   = $map[$bot_id] ?? null;
        if (is_array($ov)) {
            foreach (self::overridable_bot_keys() as $k) {
                if (array_key_exists($k, $ov)) {
                    $base[$k] = $ov[$k];
                }
            }
        }
        /**
         * Filter the resolved per-bot config. Receives the merged config array
         * and the bot id; return a modified array to override retrieval
         * behaviour for that bot programmatically.
         *
         * @param array<string,mixed> $base
         * @param string              $bot_id
         */
        $filtered = apply_filters('mxchat_plus_duckdb_bot_config', $base, $bot_id);
        return is_array($filtered) ? $filtered : $base;
    }

    /**
     * Merge $patch into the stored row. Merged onto what is STORED, not onto
     * defaults(): a telemetry write must not stamp keys the row never had.
     * bot_routing() reads a missing `takeover_bot_ids` as "install from
     * before Multi-Bot routing was opt-in", and a default [] written here by
     * the hourly sync would silently stop routing bots that were working.
     * Readers are unaffected: get() still merges the defaults in.
     */
    public static function update(array $patch): void {
        $stored = get_option(MXCHAT_PLUS_DUCKDB_OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        update_option(MXCHAT_PLUS_DUCKDB_OPTION_KEY, array_merge($stored, $patch), false);
    }

    // ─── Multi-Bot routing ──────────────────────────────────────────────────

    /**
     * Which non-default bots the search adapter routes to DuckDB.
     *
     * An install whose stored row predates the setting (no `takeover_bot_ids`
     * key) is reported as routing every bot, which is what the module did
     * then: `migrated` is false until maybe_migrate_bot_routing() has
     * recorded the bots that really were served.
     *
     * @return array{all: bool, bots: list<string>, migrated: bool}
     */
    public static function bot_routing(): array {
        $stored = get_option(MXCHAT_PLUS_DUCKDB_OPTION_KEY, null);
        if (is_array($stored) && !array_key_exists('takeover_bot_ids', $stored)) {
            return ['all' => true, 'bots' => [], 'migrated' => false];
        }
        $opts = self::get();
        return [
            'all'      => !empty($opts['takeover_multibot_bots']),
            'bots'     => self::normalize_bot_ids($opts['takeover_bot_ids'] ?? []),
            'migrated' => true,
        ];
    }

    /**
     * Is $bot_id opted in to Multi-Bot routing? Never true for 'default' or
     * 'testing' (the default-bot takeover covers those).
     */
    public static function is_bot_routed(string $bot_id): bool {
        if ($bot_id === '' || in_array($bot_id, self::RESERVED_BOT_IDS, true)) {
            return false;
        }
        $routing = self::bot_routing();
        if (!$routing['migrated'] && self::maybe_migrate_bot_routing()) {
            $routing = self::bot_routing();
        }
        return $routing['all'] || in_array($bot_id, $routing['bots'], true);
    }

    /**
     * One-time upgrade of an install from before Multi-Bot routing was
     * opt-in. Back then every non-default bot was routed to DuckDB as soon
     * as the module was enabled. The bots that already have vectors in
     * DuckDB were working, so they stay routed. The others were answering
     * from an empty namespace, and they go back to the config the Multi-Bot
     * add-on gives them. A disabled module routed nothing, so nothing is
     * kept.
     *
     * Returns true once the routing is recorded. While the store cannot be
     * listed it returns false (retried at most every
     * ROUTING_MIGRATION_RETRY seconds), and bot_routing() keeps reporting
     * the old route-everything behaviour, so nothing moves in the meantime.
     *
     * Two ways the list could come out short, and what is done instead:
     *   - With the MotherDuck local mirror on, reads are served by the local
     *     copy whatever the state of its bootstrap
     *     (MxChat_Plus_DuckDB_Mirrored_Connection::execute_read()), and an
     *     empty local table answers without an error — any chat query
     *     creates one through ensure_schema(). The listing therefore goes to
     *     the primary (MotherDuck) side. It runs once per install, so the
     *     round trip does not matter.
     *   - The stored list holds at most MAX_ROUTED_BOT_IDS ids, and the
     *     listing stops at ROUTING_MIGRATION_LIST_LIMIT rows. More bots than
     *     the list can hold, or a listing that came back full, records
     *     "route every bot" — exactly what the install did — rather than a
     *     truncated list that would silently unroute the rest. (Recording
     *     nothing and retrying would re-list the store on every chat
     *     request of a non-default bot, forever.)
     */
    public static function maybe_migrate_bot_routing(): bool {
        $stored = get_option(MXCHAT_PLUS_DUCKDB_OPTION_KEY, null);
        if (!is_array($stored) || array_key_exists('takeover_bot_ids', $stored)) {
            return true;
        }
        $route_all = false;
        $bots = [];
        if (!empty($stored['enabled'])) {
            if (get_transient(self::ROUTING_MIGRATION_TRANSIENT)) {
                return false;
            }
            try {
                $conn = MxChat_Plus_DuckDB_Connection_Factory::current();
                if ($conn instanceof MxChat_Plus_DuckDB_Mirrored_Connection) {
                    $conn = $conn->primary_connection();
                }
                $listed = (new MxChat_Plus_DuckDB_Vector_Store($conn))->list_bot_ids(self::ROUTING_MIGRATION_LIST_LIMIT);
            } catch (\Throwable $e) {
                set_transient(self::ROUTING_MIGRATION_TRANSIENT, 1, self::ROUTING_MIGRATION_RETRY);
                return false;
            }
            $bots = self::clean_bot_ids($listed, PHP_INT_MAX);
            if (count($listed) >= self::ROUTING_MIGRATION_LIST_LIMIT || count($bots) > self::MAX_ROUTED_BOT_IDS) {
                $route_all = true;
                $bots = [];
            }
        }
        $stored['takeover_multibot_bots'] = $route_all;
        $stored['takeover_bot_ids']       = self::normalize_bot_ids($bots);
        update_option(MXCHAT_PLUS_DUCKDB_OPTION_KEY, $stored, false);
        return true;
    }

    /**
     * Bot ids from the settings form (comma- or whitespace-separated text) or
     * from storage (a list): trimmed, deduplicated, sorted, reserved ids
     * dropped, at most MAX_ROUTED_BOT_IDS ids of at most 190 characters.
     *
     * @param mixed $raw
     * @return list<string>
     */
    public static function normalize_bot_ids($raw): array {
        return self::clean_bot_ids($raw, self::MAX_ROUTED_BOT_IDS);
    }

    /**
     * normalize_bot_ids() with the cap as a parameter.
     *
     * @param mixed $raw
     * @return list<string>
     */
    private static function clean_bot_ids($raw, int $cap): array {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $seen = [];
        foreach ($raw as $id) {
            if (!is_scalar($id)) continue;
            $id = sanitize_text_field((string) $id);
            if ($id === '' || strlen($id) > 190 || in_array($id, self::RESERVED_BOT_IDS, true)) continue;
            $seen[$id] = true;
            if (count($seen) >= $cap) break;
        }
        $ids = array_map('strval', array_keys($seen));
        sort($ids, SORT_STRING);
        return $ids;
    }

    // ─── Reactions to a settings change ─────────────────────────────────────

    /**
     * `add_option_` / `update_option_` listener on our settings row (the
     * hooks fire after the save, so this sees the sanitised values).
     *
     * Whenever the store MxChat's default bot reads changes underneath it,
     * MxChat's media auto-sync signatures still vouch for entries of the
     * previous store. MxChat compares an edited attachment with its
     * signature and skips it when nothing changed
     * (MxChat_Knowledge_Manager::mxchat_handle_attachment_change, MxChat
     * 3.2.25), so a library file missing from the new store would never come
     * back. That happens when:
     *   - DuckDB starts serving the default bot (takeover switched on, or the
     *     module enabled with it on);
     *   - DuckDB stops serving it: MxChat is back on its own store, which
     *     never received what was indexed into DuckDB meanwhile;
     *   - while it serves, the store is swapped (backend, database, file or
     *     table) or reset — the dimension and storage layout can only change
     *     on an empty table (sanitize_for_save() refuses otherwise).
     * MxChat clears the signatures itself after its own deletes
     * (MxChat_Utils::delete_chunks_for_url); this is the same call for a
     * change it cannot see. The cost is one embedding the next time each
     * library file is edited; MxChat's own Media import ignores signatures.
     * (Not covered: switching the whole DuckDB module off under MxChat Plus →
     * Modules, which does not save this row.)
     *
     * A change of a ranking setting (RANKING_KEYS) also flushes the query
     * cache: its key carries none of them, so a cached top-K would otherwise
     * be served under the old ranking until it expires.
     *
     * Deliberately NOT done here: a MySQL → DuckDB sync at switch-on. On a
     * site that moved to Pinecone, the WordPress table can still hold rows
     * from before, and copying them would bring retired text back.
     *
     * @param mixed $old
     * @param mixed $new
     */
    public static function handle_settings_saved($old, $new): void {
        $old = array_merge(self::defaults(), is_array($old) ? $old : []);
        $new = array_merge(self::defaults(), is_array($new) ? $new : []);

        if (self::ranking_changed($old, $new) && class_exists('MxChat_Plus_DuckDB_Cache')) {
            MxChat_Plus_DuckDB_Cache::flush_query_cache();
        }

        $was_serving = !empty($old['enabled']) && !empty($old['takeover_default_bot_pinecone']);
        $serving     = !empty($new['enabled']) && !empty($new['takeover_default_bot_pinecone']);
        if ($was_serving !== $serving
            || ($serving && (self::store_identity_changed($old, $new) || self::store_was_reset($old, $new)))) {
            self::forget_host_attachment_signatures();
        }
    }

    /**
     * `add_option_{option}` passes ($option, $value): the row was just
     * created, so there is no previous state.
     *
     * @param mixed $option
     * @param mixed $value
     */
    public static function handle_settings_added($option, $value): void {
        self::handle_settings_saved([], $value);
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    public static function store_identity_changed(array $old, array $new): bool {
        foreach (self::STORE_IDENTITY_KEYS as $key) {
            if ((string) ($old[$key] ?? '') !== (string) ($new[$key] ?? '')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Did the save change the vector layout? sanitize_for_save() accepts that
     * only when the table is empty (or cannot be read), so the store MxChat
     * sees has been wiped.
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    public static function store_was_reset(array $old, array $new): bool {
        return (int) ($old['embedding_dim'] ?? 0) !== (int) ($new['embedding_dim'] ?? 0)
            || (string) ($old['embedding_storage'] ?? '') !== (string) ($new['embedding_storage'] ?? '');
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    public static function ranking_changed(array $old, array $new): bool {
        foreach (self::RANKING_KEYS as $key) {
            // Loose on purpose: a stored '1' and a sanitised true are the same setting.
            if (($old[$key] ?? null) != ($new[$key] ?? null)) {
                return true;
            }
        }
        return false;
    }

    /**
     * `mxchat_plus_duckdb_proxy_follow_host_hybrid_toggle` (priority 5, so a
     * site's own callback at the default priority still has the last word):
     * the proxy follows MxChat's "Hybrid keyword boost" when the owner ticked
     * `hybrid_follow_host_toggle`. The filter is the proxy's opt-in seam
     * (MxChat_Plus_DuckDB_Pinecone_Proxy::follow_host_hybrid_toggle()); this
     * is its settings-page switch.
     *
     * @param mixed $follow
     * @param mixed $bot_id
     */
    public static function follow_host_hybrid_toggle_setting($follow, $bot_id = 'default'): bool {
        $opts = self::get();
        if (!empty($opts['hybrid_follow_host_toggle'])) {
            return true;
        }
        return (bool) $follow;
    }

    /**
     * MxChat_Utils::forget_all_attachment_signatures() exists from MxChat
     * 3.2.24; older hosts keep no signature to clear.
     */
    private static function forget_host_attachment_signatures(): void {
        if (is_callable(['MxChat_Utils', 'forget_all_attachment_signatures'])) {
            MxChat_Utils::forget_all_attachment_signatures('default');
        }
    }

    public static function install_defaults(): void {
        if (get_option(MXCHAT_PLUS_DUCKDB_OPTION_KEY, null) === null) {
            update_option(MXCHAT_PLUS_DUCKDB_OPTION_KEY, self::defaults(), false);
        }
    }

    // ─── Scheduled work: single source of truth ─────────────────────────────

    /**
     * Every class that schedules recurring work, mapped to the constants that
     * name its hook and (for Action Scheduler jobs) its group. Add a row here
     * — and only here — when a new scheduled job is introduced.
     *
     * Shape: [class, hook constant, group constant|null]. A null group means
     * the job runs on WP-cron, not Action Scheduler.
     *
     * @var list<array{0:string,1:string,2:?string}>
     */
    private const SCHEDULED_JOB_CLASSES = [
        ['MxChat_Plus_DuckDB_Sync',               'CRON_HOOK',   null],
        ['MxChat_Plus_DuckDB_Sync',               'RESYNC_HOOK', null],
        ['MxChat_Plus_DuckDB_Compactor',          'CRON_HOOK',   null],
        ['MxChat_Plus_DuckDB_Async_Reprocess',    'ACTION_HOOK', 'GROUP'],
        ['MxChat_Plus_DuckDB_Mirror_Bootstrap',   'ACTION_HOOK', 'ACTION_GROUP'],
        ['MxChat_Plus_DuckDB_Mirror_Drain',       'ACTION_HOOK', 'ACTION_GROUP'],
        ['MxChat_Plus_DuckDB_Mirror_Drift_Check', 'ACTION_HOOK', 'ACTION_GROUP'],
    ];

    /**
     * Class file for each scheduling class, so uninstall (which runs with the
     * plugin unloaded) can read the constants instead of re-hard-coding the
     * hook names. Ordered: dependencies first (the trait is used by the mirror
     * classes, Sync's BATCH_SIZE constant is aliased from Mysql_Sync).
     *
     * @var array<string,string>
     */
    private const SCHEDULED_JOB_FILES = [
        'MxChat_Plus_DuckDB_Mysql_Sync'         => 'class-duckdb-mysql-sync.php',
        'MxChat_Plus_DuckDB_Sync'               => 'class-duckdb-sync.php',
        'MxChat_Plus_DuckDB_Compactor'          => 'class-duckdb-compactor.php',
        'MxChat_Plus_DuckDB_Async_Reprocess'    => 'class-duckdb-async-reprocess.php',
        'MxChat_Plus_DuckDB_Mirror_Bootstrap'   => 'class-duckdb-mirror-bootstrap.php',
        'MxChat_Plus_DuckDB_Mirror_Drain'       => 'class-duckdb-mirror-drain.php',
        'MxChat_Plus_DuckDB_Mirror_Drift_Check' => 'class-duckdb-mirror-drift-check.php',
    ];

    /**
     * Every recurring job this module schedules: hook name => Action Scheduler
     * group (empty string for WP-cron jobs, which have no group).
     *
     * THE single source of truth consumed by deactivation and by uninstall.
     * Both used to keep their own hard-coded list, which is how deactivation
     * ended up leaving `mxchat_plus_duckdb_reprocess_post` actions queued in
     * group 'mxchat-plus' with no worker left to run them.
     *
     * @return array<string, string>
     */
    public static function scheduled_hooks(): array {
        self::load_scheduling_classes();

        $hooks = [];
        foreach (self::SCHEDULED_JOB_CLASSES as [$class, $hook_const, $group_const]) {
            // Defensive: uninstall must never fatal on a missing class file.
            if (!class_exists($class) || !defined($class . '::' . $hook_const)) {
                continue;
            }
            $hook = (string) constant($class . '::' . $hook_const);
            $hooks[$hook] = ($group_const !== null && defined($class . '::' . $group_const))
                ? (string) constant($class . '::' . $group_const)
                : '';
        }
        return $hooks;
    }

    /**
     * Cancel every scheduled job in scheduled_hooks(). Idempotent; safe to call
     * on deactivation (plugin loaded) and on uninstall (plugin unloaded).
     * Action Scheduler calls are guarded — AS may not be available at all.
     */
    public static function unschedule_all(): void {
        foreach (self::scheduled_hooks() as $hook => $group) {
            if (function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook($hook);
            }
            // Empty args ([]) cancel every scheduled instance of the hook,
            // whatever arguments it was enqueued with.
            if ($group !== '' && function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions($hook, [], $group);
            }
        }
    }

    /**
     * Pull in the scheduling classes when they aren't already loaded — the
     * uninstall path runs without the plugin bootstrapped but still has to
     * read their constants.
     */
    private static function load_scheduling_classes(): void {
        foreach (self::SCHEDULED_JOB_FILES as $class => $file) {
            if (class_exists($class)) continue;
            if (!trait_exists('MxChat_Plus_DuckDB_SQL_Helpers_Trait')
                && is_readable(__DIR__ . '/trait-duckdb-sql-helpers.php')) {
                require_once __DIR__ . '/trait-duckdb-sql-helpers.php';
            }
            $path = __DIR__ . '/' . $file;
            if (is_readable($path)) {
                require_once $path;
            }
        }
    }

    /**
     * Resolve the MotherDuck token, preferring a `MXCHAT_PLUS_MOTHERDUCK_TOKEN`
     * constant defined in `wp-config.php` over the value persisted in
     * wp_options. The constant path exists for installs whose compliance rules
     * forbid storing secrets in the database — set it once in wp-config.php
     * and the admin UI will detect the override and disable its own token
     * field. Returns the empty string when neither source has a value.
     *
     * @return string
     */
    public static function resolved_motherduck_token(): string {
        if (defined('MXCHAT_PLUS_MOTHERDUCK_TOKEN')) {
            $constant_token = (string) constant('MXCHAT_PLUS_MOTHERDUCK_TOKEN');
            if ($constant_token !== '') return $constant_token;
        }
        $opts = self::get();
        return (string) ($opts['motherduck_token'] ?? '');
    }

    /**
     * True iff the active MotherDuck token comes from the wp-config constant
     * rather than the option row. Admin UI checks this to mute its own token
     * field — editing it would have no effect while the constant override is
     * in place.
     */
    public static function motherduck_token_is_from_constant(): bool {
        if (!defined('MXCHAT_PLUS_MOTHERDUCK_TOKEN')) return false;
        return ((string) constant('MXCHAT_PLUS_MOTHERDUCK_TOKEN')) !== '';
    }

    public static function default_embedded_path(): string {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . 'mxchat-plus-private/store.duckdb';
    }

    public static function resolved_embedded_path(): string {
        $opts = self::get();
        $path = !empty($opts['embedded_path']) ? $opts['embedded_path'] : self::default_embedded_path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        self::write_directory_blockers($dir);
        return $path;
    }

    public static function default_mirror_path(): string {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . 'mxchat-plus-private/mirror.duckdb';
    }

    /**
     * Resolves the local-mirror `.duckdb` path the same way as
     * `resolved_embedded_path()` — uses the configured value when set,
     * falls back to `<uploads>/mxchat-plus-private/mirror.duckdb`,
     * ensures the directory exists, and writes the HTTP blockers so
     * the shadow database isn't web-reachable.
     *
     * Note the default sits next to the embedded path: this means an
     * install that toggles between modes won't accumulate orphan
     * directories. Site owners on shared hosts who keep the default
     * pay one directory for everything we persist.
     */
    public static function resolved_mirror_path(): string {
        $opts = self::get();
        $path = !empty($opts['motherduck_mirror_path']) ? $opts['motherduck_mirror_path'] : self::default_mirror_path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        self::write_directory_blockers($dir);
        return $path;
    }

    /**
     * Drop .htaccess + index.php + web.config in the data directory so the
     * .duckdb file (and any companion files) cannot be fetched over HTTP.
     * Idempotent. Failed writes are logged so a misconfigured docroot doesn't
     * silently expose the vector database — the surfacing path goes through
     * PHP's error_log so site owners running uploads-not-writable setups see
     * the warning without us touching last_error (reserved for runtime errors).
     */
    public static function write_directory_blockers(string $dir): void {
        if (!is_dir($dir)) return;
        if (!is_writable($dir)) {
            error_log('[mxchat-plus] data directory not writable, cannot place HTTP blockers: ' . $dir);
            return;
        }

        $blockers = [
            '.htaccess' => "# Generated by mxchat-plus — do not edit.\n"
                . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n",
            'index.php' => "<?php // Silence is golden.\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                . "<configuration><system.webServer><authorization>"
                . "<deny users=\"*\" /></authorization></system.webServer></configuration>\n",
        ];

        foreach ($blockers as $name => $contents) {
            $path = trailingslashit($dir) . $name;
            if (file_exists($path)) continue;
            if (@file_put_contents($path, $contents) === false) {
                error_log('[mxchat-plus] failed to write HTTP blocker ' . $name . ' in ' . $dir);
            }
        }
    }

    /**
     * The Multi-Bot routing a save records, or null to keep an older
     * install's routing unrecorded (bot_routing() then keeps routing every
     * bot, and maybe_migrate_bot_routing() still narrows the list once DuckDB
     * answers).
     *
     * The form always posts the id list (possibly empty), so its presence
     * says the routing fields were on the page. When they were not — a
     * programmatic update_option(), which WordPress also runs through this
     * sanitiser — what is stored is kept as it is.
     *
     * While an older install's upgrade is pending, the page renders the
     * routing as it works today (every bot routed) plus a hidden marker
     * (ROUTING_LEGACY_FORM_FIELD). A save of that rendering left as it is —
     * typically a save made to fix the MotherDuck token, not to choose
     * routing — keeps the upgrade pending instead of freezing "every bot"
     * forever. Unticking the box, typing ids, or ticking
     * ROUTING_KEEP_ALL_FIELD records the choice.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     * @return array{all: bool, bots: list<string>}|null
     */
    private static function sanitize_routing(array $input, array $current): ?array {
        $stored = get_option(MXCHAT_PLUS_DUCKDB_OPTION_KEY, null);
        $pending = is_array($stored) && !array_key_exists('takeover_bot_ids', $stored);

        if (!array_key_exists('takeover_bot_ids', $input)) {
            if ($pending) return null;
            return [
                'all'  => !empty($current['takeover_multibot_bots']),
                'bots' => self::normalize_bot_ids($current['takeover_bot_ids'] ?? []),
            ];
        }

        $all  = !empty($input['takeover_multibot_bots']);
        $bots = self::normalize_bot_ids($input['takeover_bot_ids']);
        if ($pending
            && !empty($input[self::ROUTING_LEGACY_FORM_FIELD])
            && empty($input[self::ROUTING_KEEP_ALL_FIELD])
            && $all && $bots === []) {
            return null;
        }
        if (!empty($input[self::ROUTING_KEEP_ALL_FIELD])) {
            $all = true;
        }
        return ['all' => $all, 'bots' => $bots];
    }

    public static function sanitize_for_save(array $input): array {
        $current = self::get();
        $out = self::defaults();

        $out['enabled']             = !empty($input['enabled']);
        $out['mode']                = in_array($input['mode'] ?? '', ['motherduck', 'embedded'], true) ? $input['mode'] : 'motherduck';
        $out['motherduck_token']    = isset($input['motherduck_token']) ? trim((string) $input['motherduck_token']) : '';
        $out['motherduck_database'] = isset($input['motherduck_database'])
            ? preg_replace('/[^a-zA-Z0-9_]/', '', (string) $input['motherduck_database'])
            : 'my_db';
        if (empty($out['motherduck_database'])) $out['motherduck_database'] = 'my_db';
        $out['embedded_path']       = isset($input['embedded_path']) ? sanitize_text_field($input['embedded_path']) : '';
        $out['embedded_binary']     = isset($input['embedded_binary']) ? sanitize_text_field($input['embedded_binary']) : '';

        // Mirror toggle — only meaningful when mode === 'motherduck'.
        // We accept the input and then silently drop the toggle when
        // it would have no effect, with a settings error so the admin
        // understands why their checkbox didn't stick. The mirror path
        // is sanitised regardless; switching back to MotherDuck later
        // will pick up the existing value.
        $out['motherduck_mirror_path']    = isset($input['motherduck_mirror_path']) ? sanitize_text_field($input['motherduck_mirror_path']) : '';
        // Opt-in shortcircuit of mxchat_pinecone_addon_options reads so
        // Option B works on stock mxchat without the upstream patch and
        // without multi-bot. Pure boolean — no shape to validate.
        $out['takeover_default_bot_pinecone'] = !empty($input['takeover_default_bot_pinecone']);

        $routing = self::sanitize_routing($input, $current);
        if ($routing === null) {
            unset($out['takeover_multibot_bots'], $out['takeover_bot_ids']);
        } else {
            $out['takeover_multibot_bots'] = $routing['all'];
            $out['takeover_bot_ids']       = $routing['bots'];
        }
        $mirror_requested = !empty($input['motherduck_mirror_enabled']);
        if ($mirror_requested && $out['mode'] !== 'motherduck') {
            add_settings_error(
                MXCHAT_PLUS_DUCKDB_OPTION_KEY,
                'mirror_requires_motherduck_mode',
                __('The MotherDuck local mirror only applies when the backend is set to MotherDuck. Toggle ignored.', 'mxchat-plus'),
                'warning'
            );
            $out['motherduck_mirror_enabled'] = false;
        } else {
            $out['motherduck_mirror_enabled'] = $mirror_requested;
        }

        // If the admin set a custom DuckDB CLI path, probe it. A non-duckdb
        // binary still gets saved (so a typo isn't blocked) but we surface a
        // warning so they don't discover the mistake via cryptic runtime errors.
        if (!empty($out['embedded_binary']) && class_exists('MxChat_Plus_DuckDB_Embedded_Connection')) {
            if (!MxChat_Plus_DuckDB_Embedded_Connection::looks_like_duckdb_binary($out['embedded_binary'])) {
                add_settings_error(
                    MXCHAT_PLUS_DUCKDB_OPTION_KEY,
                    'embedded_binary_suspect',
                    sprintf(
                        /* translators: %s = configured binary path */
                        __('The configured DuckDB CLI path (%s) did not respond to a probe query. Double-check that it points to the duckdb binary.', 'mxchat-plus'),
                        $out['embedded_binary']
                    ),
                    'warning'
                );
            }
        }

        $out['table_name']          = isset($input['table_name']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $input['table_name']) : 'mxchat_vectors';
        if (empty($out['table_name'])) $out['table_name'] = 'mxchat_vectors';
        $requested_dim              = max(1, (int) ($input['embedding_dim'] ?? 1536));
        $out['distance_metric']     = in_array($input['distance_metric'] ?? '', ['cosine', 'l2sq', 'ip'], true) ? $input['distance_metric'] : 'cosine';
        $out['hnsw_enabled']        = !empty($input['hnsw_enabled']);
        $out['top_k']               = max(1, min(1000, (int) ($input['top_k'] ?? 50)));
        $out['embedding_storage']   = in_array($input['embedding_storage'] ?? '', ['float32', 'int8'], true)
            ? $input['embedding_storage']
            : 'float32';
        // Same guard as embedding_dim: changing storage layout silently corrupts
        // existing vectors. Block when the table already has rows.
        if ($out['embedding_storage'] !== ($current['embedding_storage'] ?? 'float32') && $current['enabled']) {
            try {
                $store_check = new MxChat_Plus_DuckDB_Vector_Store();
                $info = $store_check->table_info();
                if ($info && $info['count'] > 0) {
                    add_settings_error(
                        MXCHAT_PLUS_DUCKDB_OPTION_KEY,
                        'storage_change_blocked',
                        sprintf(
                            /* translators: %d = row count */
                            __('Cannot change embedding storage: the table already contains %d vectors. Export, wipe, and re-import to switch storage layout.', 'mxchat-plus'),
                            (int) $info['count']
                        ),
                        'error'
                    );
                    $out['embedding_storage'] = $current['embedding_storage'] ?? 'float32';
                }
            } catch (\Throwable $e) {
                // Brand-new install, no table yet — accept the change.
            }
        }
        $out['hybrid_enabled']      = !empty($input['hybrid_enabled']);
        $out['hybrid_follow_host_toggle'] = !empty($input['hybrid_follow_host_toggle']);
        $alpha                       = (float) ($input['hybrid_alpha'] ?? 0.7);
        $out['hybrid_alpha']        = max(0.0, min(1.0, $alpha));
        $out['query_cache_enabled'] = !empty($input['query_cache_enabled']);
        $out['query_cache_ttl']     = max(0, min(3600, (int) ($input['query_cache_ttl'] ?? 300)));
        $out['dedup_per_source']    = !empty($input['dedup_per_source']);
        $out['slow_query_ms']       = max(0, (int) ($input['slow_query_ms'] ?? 500));

        // ── Per-bot retrieval overrides (v0.13.0) ───────────────────────────
        // Form shape: bot_overrides[<bot_id>][_enabled] + the overridable keys.
        // Only bots flagged _enabled keep an entry (the rest inherit global at
        // read time via get_for_bot()); each kept entry is clamped with the
        // same bounds as the global retrieval settings. When the section isn't
        // posted at all (e.g. backend not yet configured, so the UI hid it),
        // preserve whatever was stored rather than silently wiping it.
        if (isset($input['bot_overrides']) && is_array($input['bot_overrides'])) {
            $out['bot_overrides'] = [];
            $kept = 0;
            foreach ($input['bot_overrides'] as $bot_id => $ov) {
                if ($kept >= 200) break; // bound the map
                if (!is_array($ov) || empty($ov['_enabled'])) continue;
                $bid = sanitize_text_field((string) $bot_id);
                if ($bid === '' || strlen($bid) > 190) continue;
                $out['bot_overrides'][$bid] = [
                    'hybrid_enabled'      => !empty($ov['hybrid_enabled']),
                    'hybrid_alpha'        => max(0.0, min(1.0, (float) ($ov['hybrid_alpha'] ?? 0.7))),
                    'dedup_per_source'    => !empty($ov['dedup_per_source']),
                    'query_cache_enabled' => !empty($ov['query_cache_enabled']),
                    'query_cache_ttl'     => max(0, min(3600, (int) ($ov['query_cache_ttl'] ?? 300))),
                    'slow_query_ms'       => max(0, (int) ($ov['slow_query_ms'] ?? 500)),
                ];
                $kept++;
            }
        } else {
            $out['bot_overrides'] = is_array($current['bot_overrides'] ?? null)
                ? $current['bot_overrides']
                : [];
        }

        // Block dimension change when the table already contains vectors —
        // the column type FLOAT[<dim>] is fixed at CREATE time and a mismatch
        // breaks every subsequent insert/search. User must wipe + re-sync.
        if ($requested_dim !== (int) $current['embedding_dim'] && $current['enabled']) {
            $blocked = false;
            try {
                $store = new MxChat_Plus_DuckDB_Vector_Store();
                $info = $store->table_info();
                if ($info && $info['count'] > 0) {
                    $blocked = true;
                    add_settings_error(
                        MXCHAT_PLUS_DUCKDB_OPTION_KEY,
                        'dim_change_blocked',
                        sprintf(
                            /* translators: 1: row count, 2: current dim, 3: requested dim */
                            __('Cannot change the embedding dimension: the table already contains %1$d vectors at dimension %2$d. Wipe the table and re-sync to adopt %3$d.', 'mxchat-plus'),
                            (int) $info['count'],
                            (int) $current['embedding_dim'],
                            (int) $requested_dim
                        ),
                        'error'
                    );
                }
            } catch (\Throwable $e) {
                // If we can't even introspect, allow the change — it's likely
                // a brand-new install where the table doesn't exist yet.
            }
            $out['embedding_dim'] = $blocked ? (int) $current['embedding_dim'] : $requested_dim;
        } else {
            $out['embedding_dim'] = $requested_dim;
        }

        // Runtime telemetry. The settings form never posts these, so a form
        // save keeps the stored values. They are present when the input is
        // a programmatic write: register_setting() hooks this sanitiser on
        // `sanitize_option_{option}`, so every update_option() of the row in
        // a request where admin_init ran (admin pages, admin-ajax) comes
        // through here — and Options::update() from "Sync now" used to have
        // its fresh last_sync_at replaced by the stale stored one.
        $out['last_sync_at']     = isset($input['last_sync_at']) ? (int) $input['last_sync_at'] : (int) $current['last_sync_at'];
        $out['last_sync_count']  = isset($input['last_sync_count']) ? (int) $input['last_sync_count'] : (int) $current['last_sync_count'];
        $out['last_sync_max_id'] = isset($input['last_sync_max_id']) ? (int) $input['last_sync_max_id'] : (int) ($current['last_sync_max_id'] ?? 0);
        $out['last_compact_at']  = isset($input['last_compact_at']) ? (int) $input['last_compact_at'] : (int) ($current['last_compact_at'] ?? 0);
        $out['last_error']       = isset($input['last_error']) ? (string) $input['last_error'] : (string) $current['last_error'];

        // Drop the cached connection + Vector_Store so next request picks up
        // the new config without a process reload.
        if (class_exists('MxChat_Plus_DuckDB_Connection_Factory')) {
            MxChat_Plus_DuckDB_Connection_Factory::reset_cache();
        }
        if (class_exists('MxChat_Plus_DuckDB_Vector_Store')) {
            MxChat_Plus_DuckDB_Vector_Store::reset_current();
        }

        return $out;
    }

    /**
     * Option holding the empirically-probed output dimension of a Custom /
     * Azure OpenAI embedding provider, whose dimension can't be derived from
     * mxchat-basic's static registry. Shaped:
     *   ['model' => 'custom:<id>', 'dim' => <int>, 'at' => <unix ts>, 'key' => <md5>]
     * `key` identifies base URL + model the way MxChat's own cache does
     * (custom_dimension_key()); entries written before it existed carry only
     * `model`. Written by the "Detect dimension" admin probe
     * (ajax_detect_dimension).
     */
    const CUSTOM_DIM_OPTION = 'mxchat_plus_duckdb_custom_embedding_dim';

    /**
     * Host literal: the option where MxChat caches a Custom Provider model's
     * dimension after its own probe, shaped ['key', 'model', 'dimension']
     * (MxChat_Utils::custom_embedding_dimension, MxChat 3.2.24+). Read only.
     */
    const HOST_CUSTOM_DIM_OPTION = 'mxchat_custom_embedding_dimension';

    /** Placeholder shown for a custom model nobody has probed yet. */
    const CUSTOM_DIM_PLACEHOLDER = 1536;

    /**
     * True when MxChat is producing embeddings through the Custom (OpenAI-
     * compatible) / Azure OpenAI provider rather than a built-in model. Since
     * mxchat-basic v3.2.8 this path works end-to-end for both indexing and
     * queries, and the active model is stamped as `custom:<id>` — a value the
     * static dimension registry knows nothing about. Detection treats such
     * models specially (see detect_embedding_dim).
     */
    public static function is_custom_embedding_model(string $model): bool {
        return strpos($model, 'custom:') === 0;
    }

    /** @return array<string, mixed> MxChat's own settings (mxchat_options). */
    private static function mxchat_options(): array {
        $mxopts = get_option('mxchat_options', []);
        return is_array($mxopts) ? $mxopts : [];
    }

    /**
     * Is MxChat's next embed going through the Custom Provider? The same test
     * MxChat_Utils::generate_embedding() makes first (MxChat 3.2.25).
     *
     * @param array<string, mixed>|null $mxopts
     */
    public static function custom_embeddings_enabled(?array $mxopts = null): bool {
        $mxopts = $mxopts ?? self::mxchat_options();
        return ($mxopts['custom_provider_for_embeddings'] ?? '') === 'on';
    }

    /**
     * The model name the Custom Provider is sent. Delegates to
     * MxChat_Utils::resolve_custom_embedding_model() (MxChat 3.2.21+); the
     * fallback is the same chain: embedding model, else chat model, else
     * 'default'.
     *
     * @param array<string, mixed> $mxopts
     */
    private static function custom_model_name(array $mxopts): string {
        if (is_callable(['MxChat_Utils', 'resolve_custom_embedding_model'])) {
            return (string) MxChat_Utils::resolve_custom_embedding_model($mxopts);
        }
        foreach (['custom_provider_embedding_model', 'custom_provider_model'] as $k) {
            if (isset($mxopts[$k]) && trim((string) $mxopts[$k]) !== '') {
                return trim((string) $mxopts[$k]);
            }
        }
        return 'default';
    }

    /**
     * The model MxChat's NEXT embed will use — the selection, as opposed to
     * the stamp of the vectors already stored (active_embedding_model()).
     * Delegates to MxChat_Utils::get_selected_embedding_model() (MxChat
     * 3.2.21+), which reports a Custom Provider model as `custom:<model>`.
     */
    public static function selected_embedding_model(): string {
        $mxopts = self::mxchat_options();
        if (is_callable(['MxChat_Utils', 'get_selected_embedding_model'])) {
            $model = (string) MxChat_Utils::get_selected_embedding_model($mxopts);
            if ($model !== '') return $model;
        }
        if (self::custom_embeddings_enabled($mxopts)) {
            return 'custom:' . self::custom_model_name($mxopts);
        }
        return (string) ($mxopts['embedding_model'] ?? 'text-embedding-ada-002');
    }

    /**
     * The embedding model MxChat is actually writing vectors with. Prefers the
     * `mxchat_active_embedding_model` stamp (set right after a successful
     * embedding, so it reflects the real model — including `custom:<id>`),
     * falling back to the selected model on a never-embedded install.
     */
    public static function active_embedding_model(): string {
        $active = (string) get_option('mxchat_active_embedding_model', '');
        if ($active !== '') return $active;
        return self::selected_embedding_model();
    }

    /**
     * Identity of a Custom Provider model, in MxChat's own key format:
     * md5(lowercased base URL without trailing slash . '|' . model)
     * (MxChat_Utils::custom_embedding_dimension, MxChat 3.2.24+). Copied, not
     * called: the host function probes the endpoint when its cache is empty,
     * a blocking request this plugin must not make while rendering a page.
     * Keying on the base URL as well as the model means a probe measured
     * against another endpoint is never reused.
     *
     * @param array<string, mixed>|null $mxopts
     * @param string|null               $model  `custom:<name>` or bare name; null = the selected one.
     */
    public static function custom_dimension_key(?array $mxopts = null, ?string $model = null): string {
        $mxopts = $mxopts ?? self::mxchat_options();
        $name = $model === null ? self::custom_model_name($mxopts) : $model;
        if (self::is_custom_embedding_model($name)) {
            $name = substr($name, 7);
        }
        $base_url = isset($mxopts['custom_provider_base_url']) ? rtrim(trim((string) $mxopts['custom_provider_base_url']), '/') : '';
        return md5(strtolower($base_url) . '|' . $name);
    }

    /**
     * Persist an empirically-probed custom-provider dimension, keyed to the
     * model and endpoint it was measured against so a later switch of either
     * invalidates it.
     */
    public static function store_probed_custom_dim(string $model, int $dim): void {
        update_option(self::CUSTOM_DIM_OPTION, [
            'model' => $model,
            'dim'   => $dim,
            'at'    => time(),
            'key'   => self::custom_dimension_key(null, $model),
        ], false);
    }

    /**
     * The probed custom dimension iff it was measured against $model (and,
     * for a probe that recorded it, the current endpoint), else 0.
     */
    public static function probed_custom_dim(string $model): int {
        $probe = get_option(self::CUSTOM_DIM_OPTION, []);
        if (!is_array($probe) || ($probe['model'] ?? '') !== $model || (int) ($probe['dim'] ?? 0) <= 0) {
            return 0;
        }
        if (isset($probe['key']) && $probe['key'] !== self::custom_dimension_key(null, $model)) {
            return 0;
        }
        return (int) $probe['dim'];
    }

    /**
     * The dimension MxChat itself recorded for this custom model, read from
     * its cache without ever triggering its probe. 0 when unknown.
     */
    public static function host_cached_custom_dim(string $model): int {
        $known = get_option(self::HOST_CUSTOM_DIM_OPTION, []);
        if (is_array($known)
            && ($known['key'] ?? '') === self::custom_dimension_key(null, $model)
            && (int) ($known['dimension'] ?? 0) > 0) {
            return (int) $known['dimension'];
        }
        return 0;
    }

    /**
     * Dimension of a `custom:<model>`: our own probe, else MxChat's cached
     * one, else 0.
     */
    private static function custom_model_dim(string $model): int {
        $dim = self::probed_custom_dim($model);
        return $dim > 0 ? $dim : self::host_cached_custom_dim($model);
    }

    /**
     * Returns the active embedding model's output dimension. Prefers
     * mxchat-basic's own MxChat_Utils::embedding_model_dimensions() when the
     * companion plugin is installed (single source of truth, won't drift as
     * mxchat adds models or tweaks dimensions). Falls back to a hard-coded
     * mirror of the registry at mxchat-basic v3.x for graceful degradation
     * when the function isn't available (e.g. unit tests).
     *
     * Custom / Azure OpenAI providers (model `custom:<id>`, mxchat-basic
     * >= 3.2.8) are not in any registry — their dimension is whatever the
     * endpoint returns (768/1024/3072/…). For those we use our probe, else
     * the dimension MxChat cached from its own probe (3.2.24+); otherwise we
     * surface the 1536 default purely as a placeholder (the operator must run
     * "Detect dimension" or set it by hand, and the insert-time guard in
     * Vector_Store catches any mismatch).
     *
     * When the stamped model is the one selected for the next embed, a
     * standard model's size comes from MxChat_Utils::standard_embedding_dimension()
     * (MxChat 3.2.24+), which also honours the Voyage / Gemini output-size
     * settings.
     *
     * Fallback default is 1536 (ada-002 / TE3 Small).
     */
    public static function detect_embedding_dim(): int {
        return self::dimension_of(self::active_embedding_model());
    }

    /**
     * Dimension of the model MxChat's next embed will use, or 0 for a custom
     * model nobody has probed. Shown beside detect_embedding_dim() when the
     * two models differ — never swapped in for it: the stamp describes the
     * vectors already stored, the selection the ones to come.
     */
    public static function selected_embedding_dim(): int {
        $selected = self::selected_embedding_model();
        if (self::is_custom_embedding_model($selected)) {
            return self::custom_model_dim($selected);
        }
        return self::dimension_of($selected);
    }

    private static function dimension_of(string $model): int {
        // Custom / Azure providers: dimension is endpoint-defined, not
        // registry-derived.
        if (self::is_custom_embedding_model($model)) {
            $dim = self::custom_model_dim($model);
            return $dim > 0 ? $dim : self::CUSTOM_DIM_PLACEHOLDER;
        }

        if ($model === self::selected_embedding_model() && is_callable(['MxChat_Utils', 'standard_embedding_dimension'])) {
            $dim = (int) MxChat_Utils::standard_embedding_dimension(self::mxchat_options());
            if ($dim > 0) return $dim;
        }

        // Single source of truth lives in mxchat-basic. Use it when possible.
        // (is_callable rather than method_exists so PHPStan + the WP stubs
        // don't narrow the second check away in static analysis.)
        if (is_callable(['MxChat_Utils', 'embedding_model_dimensions'])) {
            $dim = (int) MxChat_Utils::embedding_model_dimensions($model);
            if ($dim > 0) return $dim;
        }

        // Fallback mirror of mxchat-basic's registry (class-mxchat-utils.php).
        // Voyage-3-Large explicitly requests output_dimension=2048 in mxchat's
        // request body, so the stored dim is 2048 (not Voyage's API default).
        $known = [
            'text-embedding-ada-002'     => 1536,
            'text-embedding-3-small'     => 1536,
            'text-embedding-3-large'     => 3072,
            'voyage-3-large'             => 2048,
            'gemini-embedding-001'       => 1536,
            // Legacy / auto-migrated entries kept for installs that haven't
            // yet been upgraded by mxchat-basic's option migration:
            'voyage-3'                   => 1024,
            'voyage-3-lite'              => 512,
            'gemini-embedding-exp-03-07' => 1536, // auto-migrated to gemini-embedding-001
        ];

        return $known[$model] ?? 1536;
    }
}

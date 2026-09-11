<?php
/**
 * Admin UI: settings page + AJAX endpoints for connection test and sync.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Admin {

    private static ?self $instance = null;

    const MENU_SLUG = 'mxchat-plus';
    const NONCE_ACTION = 'mxchat_plus_duckdb_admin';

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function register_hooks(): void {
        // No admin_menu hook here: since the merge into mxchat-plus the menu
        // entry is owned by MxChat_Plus_Admin, which renders this module as a
        // tab and calls render_page() directly. register_menu() is kept for
        // the case where the core page is unavailable.
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_notices', [$this, 'render_capability_notices']);

        add_action('wp_ajax_mxchat_plus_duckdb_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_mxchat_plus_duckdb_sync_now', [$this, 'ajax_sync_now']);
        add_action('wp_ajax_mxchat_plus_duckdb_stats', [$this, 'ajax_stats']);
        add_action('wp_ajax_mxchat_plus_duckdb_reprocess_batch', [$this, 'ajax_reprocess_batch']);
        add_action('wp_ajax_mxchat_plus_duckdb_detect_dimension', [$this, 'ajax_detect_dimension']);
    }

    /**
     * Surfaces backend-capability mismatches + mirror lifecycle events
     * as admin notices on the plugin's own settings page. Scoped to
     * the settings screen so we don't pollute every WP admin page.
     *
     * Notices currently surfaced:
     *   1. HNSW enabled + MotherDuck mode without the mirror — queries
     *      fall back to brute-force; recommend enabling the mirror.
     *   2. STATUS_DRIFTED — daily check found primary/local divergence;
     *      recommend `wp mxchat-plus mirror-bootstrap --reset`.
     *   3. STATUS_ERROR on bootstrap with a recent failure.
     *   4. Quarantined entries in mirror_pending (stuck local writes).
     */
    public function render_capability_notices(): void {
        if (!function_exists('get_current_screen')) return;
        $screen = get_current_screen();
        if (!$screen || strpos((string) $screen->id, self::MENU_SLUG) === false) return;

        if (!current_user_can('manage_options')) return;

        $opts = MxChat_Plus_DuckDB_Options::get();
        if (empty($opts['enabled'])) return;

        // ── Notice 1: HNSW + MotherDuck without mirror ──────────────────
        if (($opts['mode'] ?? '') === 'motherduck'
            && !empty($opts['hnsw_enabled'])
            && empty($opts['motherduck_mirror_enabled'])) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>MxChat DuckDB</strong>: ';
            echo wp_kses_post(__(
                'HNSW indexing is enabled but you are running in MotherDuck mode without the local mirror. MotherDuck cloud does not support the VSS extension, so queries fall back to brute-force scans (fine under ~100k vectors, noticeably slower beyond that). <strong>Enable the local mirror</strong> to keep MotherDuck as the canonical store while getting HNSW acceleration locally, or switch to "Embedded" mode.',
                'mxchat-plus'
            ));
            echo '</p></div>';
        }

        // ── Notices 2-4 are mirror-specific ─────────────────────────────
        if (!class_exists('MxChat_Plus_DuckDB_Mirror_Bootstrap')) return;
        if (empty($opts['motherduck_mirror_enabled'])) return;

        $status = MxChat_Plus_DuckDB_Mirror_Bootstrap::get_status();

        // Notice 2: drift detected by the daily check.
        if ($status === MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_DRIFTED) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>MxChat DuckDB</strong>: ';
            echo wp_kses_post(__(
                'Mirror drift detected: MotherDuck and the local shadow disagree. Run <code>wp mxchat-plus mirror-drift-check</code> to see which bot_ids diverged, then <code>wp mxchat-plus mirror-bootstrap --reset</code> to re-converge.',
                'mxchat-plus'
            ));
            echo '</p></div>';
        }

        // Notice 3: bootstrap stuck in error.
        if ($status === MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_ERROR) {
            $state = MxChat_Plus_DuckDB_Mirror_Bootstrap::get_state();
            $last_error = (string) ($state['last_error'] ?? '');
            echo '<div class="notice notice-error"><p>';
            echo '<strong>MxChat DuckDB</strong>: ';
            echo esc_html__('Mirror bootstrap failed. Last error:', 'mxchat-plus');
            echo ' <code>' . esc_html($last_error !== '' ? $last_error : '(none recorded)') . '</code>. ';
            echo wp_kses_post(__(
                'The Action Scheduler will retry automatically. To restart from scratch, run <code>wp mxchat-plus mirror-bootstrap --reset</code>.',
                'mxchat-plus'
            ));
            echo '</p></div>';
        }

        // Notice 4: quarantined entries surfaced (stuck local writes).
        if (class_exists('MxChat_Plus_DuckDB_Mirrored_Connection')) {
            $q = MxChat_Plus_DuckDB_Mirrored_Connection::quarantine_count();
            if ($q > 0) {
                echo '<div class="notice notice-warning"><p>';
                echo '<strong>MxChat DuckDB</strong>: ';
                echo wp_kses_post(sprintf(
                    /* translators: %d = number of quarantined mirror entries */
                    __(
                        '%d local mirror writes have failed %d+ times and were moved to quarantine. Inspect via <code>wp mxchat-plus mirror-drain --status</code>; usual root causes are disk-full or file-permission errors on the mirror path.',
                        'mxchat-plus'
                    ),
                    $q,
                    (int) MxChat_Plus_DuckDB_Mirrored_Connection::PENDING_RETRY_LIMIT
                ));
                echo '</p></div>';
            }
        }
    }

    public function register_menu(): void {
        // Add as a submenu under the main mxchat menu if it exists, else top-level.
        $parent = $this->detect_mxchat_parent_slug();
        if ($parent) {
            add_submenu_page(
                $parent,
                __('DuckDB / MotherDuck', 'mxchat-plus'),
                __('DuckDB / MotherDuck', 'mxchat-plus'),
                'manage_options',
                self::MENU_SLUG,
                [$this, 'render_page']
            );
        } else {
            add_menu_page(
                __('MxChat DuckDB', 'mxchat-plus'),
                __('MxChat DuckDB', 'mxchat-plus'),
                'manage_options',
                self::MENU_SLUG,
                [$this, 'render_page'],
                'dashicons-database'
            );
        }
    }

    public function register_settings(): void {
        register_setting(self::MENU_SLUG, MXCHAT_PLUS_DUCKDB_OPTION_KEY, [
            'sanitize_callback' => [MxChat_Plus_DuckDB_Options::class, 'sanitize_for_save'],
        ]);
    }

    public function enqueue_assets(string $hook): void {
        if (strpos($hook, self::MENU_SLUG) === false) return;

        wp_enqueue_script(
            'mxchat-plus-admin',
            MXCHAT_PLUS_URL . 'assets/js/admin-duckdb.js',
            ['jquery'],
            MXCHAT_PLUS_VERSION,
            true
        );

        wp_localize_script('mxchat-plus-admin', 'mxchatPlusDuckDB', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'i18n'    => [
                'testing'           => __('Testing…', 'mxchat-plus'),
                'syncing'           => __('Syncing…', 'mxchat-plus'),
                'ok'                => __('OK', 'mxchat-plus'),
                'error'             => __('Error', 'mxchat-plus'),
                'syncComplete'      => __('Sync complete', 'mxchat-plus'),
                /* translators: short suffix shown after a vector count in admin status messages */
                'vectorsSuffix'     => __('vectors', 'mxchat-plus'),
                /* translators: 1: done count, 2: total count */
                'reprocessing'      => __('Reprocessing %1$d / %2$d…', 'mxchat-plus'),
                /* translators: 1: processed count, 2: failed count */
                'reprocessComplete' => __('Reprocess complete: %1$d processed, %2$d failed.', 'mxchat-plus'),
                'confirmReprocess'  => __('This will call the embedding API for every post (potential cost). Continue?', 'mxchat-plus'),
                'detecting'         => __('Probing custom endpoint…', 'mxchat-plus'),
                /* translators: %d = detected vector dimension */
                'detectedDim'       => __('Detected dimension: %d (filled in below — Save to apply)', 'mxchat-plus'),
            ],
        ]);
    }

    public function render_page(): void {
        $opts = MxChat_Plus_DuckDB_Options::get();
        $proxy_token = MxChat_Plus_DuckDB_Pinecone_Proxy::get_or_create_token();
        $proxy_host = MxChat_Plus_DuckDB_Pinecone_Proxy::pinecone_host();
        $detected_dim = MxChat_Plus_DuckDB_Options::detect_embedding_dim();
        $active_embedding_model = MxChat_Plus_DuckDB_Options::active_embedding_model();
        $embedding_model_is_custom = MxChat_Plus_DuckDB_Options::is_custom_embedding_model($active_embedding_model);

        // Discover bots for the per-bot overrides UI. Best-effort: a disabled
        // or erroring backend just yields an empty list (the section degrades
        // to an explanatory note + filter pointer). Union with any bot that
        // already has a stored override but no current data, so it stays
        // editable/removable.
        $known_bots = [];
        if (!empty($opts['enabled'])) {
            try {
                $known_bots = (new MxChat_Plus_DuckDB_Vector_Store())->list_bot_ids();
            } catch (\Throwable $e) {
                $known_bots = [];
            }
        }
        $override_bots = array_keys(is_array($opts['bot_overrides'] ?? null) ? $opts['bot_overrides'] : []);
        $known_bots = array_values(array_unique(array_merge($known_bots, $override_bots)));
        sort($known_bots);

        $view = MXCHAT_PLUS_DIR . 'admin/views/duckdb/settings.php';
        if (file_exists($view)) {
            include $view;
        }
    }

    public function ajax_test_connection(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'mxchat-plus')], 403);
        }

        try {
            $conn = MxChat_Plus_DuckDB_Connection_Factory::current();
            $ok = $conn->ping();
            if (!$ok) {
                wp_send_json_error(['message' => __('Ping failed.', 'mxchat-plus')]);
            }

            $store = new MxChat_Plus_DuckDB_Vector_Store($conn);
            $store->ensure_schema();
            $count = $store->count();

            wp_send_json_success([
                'backend' => $conn->identifier(),
                'count'   => $count,
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajax_sync_now(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'mxchat-plus')], 403);
        }

        try {
            $count = MxChat_Plus_DuckDB_Sync::instance()->full_sync();
            wp_send_json_success([
                'synced' => $count,
                'at'     => time(),
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajax_reprocess_batch(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'mxchat-plus')], 403);
        }

        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
        $batch_size = isset($_POST['batch_size']) ? max(1, min(50, (int) $_POST['batch_size'])) : 10;
        $post_types_raw = isset($_POST['post_types']) ? (string) wp_unslash($_POST['post_types']) : 'post,page';
        $post_types = array_filter(array_map('sanitize_key', array_map('trim', explode(',', $post_types_raw))));
        if (empty($post_types)) $post_types = ['post', 'page'];

        try {
            $result = MxChat_Plus_DuckDB_Sync::instance()->reprocess_posts($post_types, $batch_size, $offset);
            wp_send_json_success($result);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Empirically probe a Custom / Azure OpenAI embedding provider for the
     * vector dimension it returns, then cache it keyed to the active model.
     *
     * Built-in models carry their dimension in mxchat-basic's registry, so
     * detect_embedding_dim() resolves them without help. Custom providers
     * (mxchat-basic >= 3.2.8, active model `custom:<id>`) don't — the only way
     * to know the dimension is to ask the endpoint. We embed a tiny probe
     * string through mxchat's own custom-embedding routine and measure the
     * returned vector, so we honour the exact Base URL / auth / model the
     * operator configured.
     */
    public function ajax_detect_dimension(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'mxchat-plus')], 403);
        }

        $mxopts = get_option('mxchat_options', []);
        if (!is_array($mxopts) || (($mxopts['custom_provider_for_embeddings'] ?? '') !== 'on')) {
            wp_send_json_error(['message' => __('Custom-provider embeddings are not enabled in MxChat. Built-in models already report their dimension automatically.', 'mxchat-plus')]);
        }
        if (!is_callable(['MxChat_Utils', 'generate_embedding_custom'])) {
            wp_send_json_error(['message' => __('MxChat is not available or is too old (custom-provider embeddings require mxchat-basic 3.2.8+).', 'mxchat-plus')]);
        }

        $result = MxChat_Utils::generate_embedding_custom('dimension probe', $mxopts);
        if (!is_array($result) || empty($result)) {
            // generate_embedding_custom returns a human-readable string on error.
            $msg = is_string($result) && $result !== ''
                ? $result
                : __('The custom endpoint did not return a valid embedding vector.', 'mxchat-plus');
            wp_send_json_error(['message' => $msg]);
        }

        $dim = count($result);
        // generate_embedding_custom stamps mxchat_active_embedding_model as
        // `custom:<id>` on success, so read it back to key the cache correctly.
        $active = MxChat_Plus_DuckDB_Options::active_embedding_model();
        MxChat_Plus_DuckDB_Options::store_probed_custom_dim($active, $dim);

        wp_send_json_success(['dim' => $dim, 'model' => $active]);
    }

    public function ajax_stats(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'mxchat-plus')], 403);
        }

        try {
            $store = new MxChat_Plus_DuckDB_Vector_Store();
            $count = $store->count();
            $opts = MxChat_Plus_DuckDB_Options::get();
            wp_send_json_success([
                'count'         => $count,
                'last_sync_at'  => $opts['last_sync_at'],
                'last_error'    => $opts['last_error'],
                'embedding_dim' => $opts['embedding_dim'],
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private function detect_mxchat_parent_slug(): ?string {
        // mxchat-basic registers its top-level page under "mxchat" or similar.
        // Detection is best-effort — we look at $GLOBALS['admin_page_hooks'].
        global $admin_page_hooks;
        if (is_array($admin_page_hooks)) {
            foreach ($admin_page_hooks as $slug => $hook) {
                if (stripos($slug, 'mxchat') === 0) {
                    return $slug;
                }
            }
        }
        return null;
    }
}

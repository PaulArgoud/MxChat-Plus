<?php
/**
 * The single settings screen, one tab per module.
 *
 * Both merged plugins had their own admin surface — one a settings page, the
 * other only a dashboard widget. A single page under MxChat's own menu keeps
 * the host's navigation coherent and gives the prompt-cache module the settings
 * screen it never had.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_Admin {

    private static ?self $instance = null;

    const MENU_SLUG    = 'mxchat-plus';
    const NONCE_ACTION = 'mxchat_plus_modules';

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function register_hooks(): void {
        // Priority 20: the host registers its own top-level menu on admin_menu
        // at the default priority, so $admin_page_hooks is only populated for
        // us afterwards.
        add_action('admin_menu', [$this, 'register_menu'], 20);
        add_action('admin_post_mxchat_plus_save_modules', [$this, 'handle_save_modules']);
    }

    public function register_menu(): void {
        $parent = $this->detect_host_menu_slug();

        if ($parent !== null) {
            add_submenu_page(
                $parent,
                __('MxChat Plus', 'mxchat-plus'),
                __('MxChat Plus', 'mxchat-plus'),
                'manage_options',
                self::MENU_SLUG,
                [$this, 'render_page']
            );
            return;
        }

        // The host is active but its menu could not be found (a filter removed
        // it, or it renamed its slug). A top-level entry beats an unreachable
        // settings screen.
        add_menu_page(
            __('MxChat Plus', 'mxchat-plus'),
            __('MxChat Plus', 'mxchat-plus'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_page'],
            'dashicons-database'
        );
    }

    /** @return array<string,string> tab key => label */
    private function tabs(): array {
        $tabs = ['modules' => __('Modules', 'mxchat-plus')];
        if (MxChat_Plus_Modules::is_enabled(MxChat_Plus_Modules::DUCKDB)) {
            $tabs['duckdb'] = __('Vector store', 'mxchat-plus');
        }
        if (MxChat_Plus_Modules::is_enabled(MxChat_Plus_Modules::PROMPTCACHE)) {
            $tabs['promptcache'] = __('Prompt cache', 'mxchat-plus');
        }
        if (MxChat_Plus_Modules::is_enabled(MxChat_Plus_Modules::TRACKING)) {
            $tabs['tracking'] = __('Click tracking', 'mxchat-plus');
        }
        return $tabs;
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'mxchat-plus'));
        }

        $tabs = $this->tabs();
        $current = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'modules';
        if (!isset($tabs[$current])) {
            $current = 'modules';
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('MxChat Plus', 'mxchat-plus') . '</h1>';

        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url(add_query_arg(['page' => self::MENU_SLUG, 'tab' => $key], admin_url('admin.php'))),
                $key === $current ? ' nav-tab-active' : '',
                esc_html($label)
            );
        }
        echo '</h2>';

        // sanitize_for_save() reports backend problems through
        // add_settings_error(); outside options.php nothing prints them.
        settings_errors();

        switch ($current) {
            case 'duckdb':
                MxChat_Plus_DuckDB_Admin::instance()->render_page();
                break;
            case 'promptcache':
                MxChat_Plus_PromptCache_Admin::render_tab();
                break;
            case 'tracking':
                MxChat_Plus_Tracking_Admin::render_tab();
                break;
            default:
                $this->render_modules_tab();
        }

        echo '</div>';
    }

    private function render_modules_tab(): void {
        $modules = MxChat_Plus_Modules::all();
        $labels  = MxChat_Plus_Modules::labels();

        echo '<p>' . esc_html__(
            'Each module works on its own. Turning one off stops it from loading entirely — its settings and data are left untouched.',
            'mxchat-plus'
        ) . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="mxchat_plus_save_modules">';
        echo '<table class="form-table" role="presentation"><tbody>';

        foreach ($labels as $key => $label) {
            printf(
                '<tr><th scope="row">%s</th><td><label><input type="checkbox" name="modules[%s]" value="1"%s> %s</label></td></tr>',
                esc_html($label),
                esc_attr($key),
                checked(!empty($modules[$key]), true, false),
                esc_html__('Enabled', 'mxchat-plus')
            );
        }

        echo '</tbody></table>';
        submit_button();
        echo '</form>';

        $this->render_environment_notes();
    }

    /**
     * The two silent-failure conditions that generate most support questions.
     * Neither is an error we can fix from here, so we state them plainly.
     */
    private function render_environment_notes(): void {
        echo '<h2>' . esc_html__('Environment', 'mxchat-plus') . '</h2>';
        echo '<table class="widefat striped" style="max-width:60em"><tbody>';

        $host_version = MxChat_Plus_Host::version();
        printf(
            '<tr><td>%s</td><td>%s</td></tr>',
            esc_html__('MxChat version', 'mxchat-plus'),
            $host_version !== '' ? esc_html($host_version) : esc_html__('unknown', 'mxchat-plus')
        );

        if (MxChat_Plus_Modules::is_enabled(MxChat_Plus_Modules::PROMPTCACHE)) {
            $streaming = MxChat_Plus_Host::streaming_enabled();
            printf(
                '<tr><td>%s</td><td>%s</td></tr>',
                esc_html__('MxChat streaming', 'mxchat-plus'),
                $streaming
                    ? '<strong>' . esc_html__('On — prompt caching cannot apply to the main chat.', 'mxchat-plus') . '</strong> '
                      . esc_html__('Streaming responses are sent with curl_exec, which bypasses the WordPress HTTP API, so no filter can reach them. Turn streaming off in MxChat settings to cache the main chat.', 'mxchat-plus')
                    : esc_html__('Off — the main chat goes through the WordPress HTTP API and can be cached.', 'mxchat-plus')
            );

            if (MxChat_Plus_Host::has_native_prompt_cache()) {
                printf(
                    '<tr><td>%s</td><td>%s</td></tr>',
                    esc_html__('Native prompt cache', 'mxchat-plus'),
                    esc_html__('MxChat already marks its system prompt for caching. This module does not duplicate that breakpoint — it adds the ones MxChat leaves out (tool definitions, conversation history) and the 1-hour TTL.', 'mxchat-plus')
                );
            }
        }

        echo '</tbody></table>';
    }

    public function handle_save_modules(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'mxchat-plus'));
        }
        check_admin_referer(self::NONCE_ACTION);

        $submitted = isset($_POST['modules']) && is_array($_POST['modules'])
            ? wp_unslash($_POST['modules'])
            : [];
        MxChat_Plus_Modules::save(is_array($submitted) ? $submitted : []);

        wp_safe_redirect(add_query_arg(
            ['page' => self::MENU_SLUG, 'tab' => 'modules', 'updated' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * The host's top-level menu slug. Detection is deliberate rather than
     * hardcoded: mxchat-basic is third-party and may rename its slug between
     * releases — we would rather land on a top-level menu than on a broken
     * parent that hides the page entirely.
     */
    private function detect_host_menu_slug(): ?string {
        global $admin_page_hooks;
        if (is_array($admin_page_hooks)) {
            foreach (array_keys($admin_page_hooks) as $slug) {
                if (stripos((string) $slug, 'mxchat') === 0) {
                    return (string) $slug;
                }
            }
        }
        return null;
    }
}

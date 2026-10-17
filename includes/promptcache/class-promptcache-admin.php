<?php
/**
 * Prompt Cache module — surface d'administration.
 *
 * Deux points d'entrée, un seul rendu :
 *   - le widget du tableau de bord WordPress (visibilité « passive » des
 *     économies, sans passer par WP-CLI) ;
 *   - render_tab(), appelé par l'onglet « Prompt Cache » de la page de réglages
 *     unifiée de mxchat-plus.
 *
 * Tout le HTML produit par summary_html() est échappé à la source : les appelants
 * peuvent l'echo tel quel.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_PromptCache_Admin {

    /** Id du widget de tableau de bord (clé de $wp_meta_boxes). */
    const DASHBOARD_WIDGET_ID = 'mxchat_plus_promptcache_dashboard';

    /** Accroche la surface admin du module. */
    public static function register_hooks(): void {
        add_action('wp_dashboard_setup', [self::class, 'register_dashboard_widget']);
    }

    /** Enregistre le widget (admin uniquement, capacité manage_options). */
    public static function register_dashboard_widget(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        wp_add_dashboard_widget(
            self::DASHBOARD_WIDGET_ID,
            __('MxChat Plus — Prompt Cache', 'mxchat-plus'),
            [self::class, 'render_dashboard_widget']
        );
    }

    /** Rend le widget : résumé 24 h glissantes + cumulatif. */
    public static function render_dashboard_widget(): void {
        self::render_summaries();
    }

    /**
     * Rend l'onglet « Prompt Cache » de la page de réglages unifiée.
     * Mêmes statistiques que le widget, dans un conteneur adapté à la page.
     */
    public static function render_tab(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="mxchat-plus-promptcache-tab">';
        echo '<h2>' . esc_html__('Prompt Cache', 'mxchat-plus') . '</h2>';
        self::render_summaries();
        echo '</div>';
    }

    /**
     * Avertissement « streaming activé sans AI Tools » : le chat principal
     * n'est alors ni mis en cache ni mesuré, quel que soit le nombre d'échanges.
     * `mxchat-settings` est le slug de la page de réglages de l'HÔTE
     * (MxChat_Admin, MxChat 3.2.25) : ne pas le renommer.
     */
    private static function streaming_notice_html(bool $no_data): string {
        $settings = admin_url('admin.php?page=mxchat-settings');
        $lead = $no_data
            ? __('No data yet, and none will come from the chat: MxChat streaming is on.', 'mxchat-plus')
            : __('Chat replies are not included: MxChat streaming is on.', 'mxchat-plus');
        return '<div class="notice notice-warning inline"><p><strong>' . esc_html($lead) . '</strong> '
            . esc_html__('Streamed replies are sent with curl_exec, outside the WordPress HTTP API, so this module can neither cache nor measure them, however many messages are sent.', 'mxchat-plus')
            . (MxChat_Plus_Host::has_native_prompt_cache()
                ? ' ' . esc_html__('MxChat itself still caches its system prompt on them (5-minute TTL), which this dashboard cannot see; what is lost is the caching of the conversation history and the 1-hour TTL. Your Anthropic console shows the cache reads.', 'mxchat-plus')
                : '')
            . '</p><ul style="list-style:disc;margin-left:20px;"><li>'
            . sprintf(
                /* translators: %s: link to MxChat's settings screen. */
                esc_html__('Turn off Enable Streaming in %s and save (MxChat clears the page cache on save). Replies then arrive in one block instead of word by word.', 'mxchat-plus'),
                '<a href="' . esc_url($settings) . '">' . esc_html__('MxChat → Settings', 'mxchat-plus') . '</a>'
            )
            . '</li><li>'
            . esc_html__('Or, if your chatbot needs them anyway, turn on AI Tools under MxChat → Actions (MxChat 3.2.22 or later, with a Claude model). AI Tools are actions the chatbot may take during a conversation, the AI deciding when to use each one. While at least one tool is active, MxChat sends every reply as one complete message, through a path this module caches — so this also ends the word-by-word display.', 'mxchat-plus')
            . '</li></ul><p>'
            . esc_html__('Then send one message: a single reply records the first sample.', 'mxchat-plus')
            . '</p></div>';
    }

    /**
     * Rendu partagé widget / onglet : bloc 24 h, bloc cumulatif, pointeur CLI.
     * Aucun `return` de HTML : les deux appelants écrivent dans le flux de sortie.
     */
    private static function render_summaries(): void {
        $store = MxChat_Plus_PromptCache_Stats::get_store();
        // Le diagnostic streaming passe AVANT les chiffres : un « pas encore de
        // données » qui ne viendront jamais (chat streamé par curl_exec, hors
        // de l'API HTTP WordPress) ferait attendre l'admin indéfiniment.
        $streamed = MxChat_Plus_Host::streaming_enabled();
        $ai_tools = $streamed && MxChat_Plus_Host::ai_tools_route_claude();
        if ($streamed && !$ai_tools) {
            echo self::streaming_notice_html($store === null); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        if ($store === null) {
            if ($ai_tools) {
                $text = __('No data yet. AI Tools are active (MxChat → Actions) with a Claude model, so MxChat sends replies as one complete message, through a path this module caches: the first reply records the first sample.', 'mxchat-plus');
            } elseif ($streamed) {
                return;
            } else {
                $text = __('No data yet. Send one chat message: the first Claude reply records the first sample, and cache reads show from the second turn.', 'mxchat-plus');
            }
            echo '<p>' . esc_html($text) . '</p>';
            return;
        }

        $window = MxChat_Plus_PromptCache_Stats::aggregate_window($store);
        if ((int) ($window['requests'] ?? 0) > 0) {
            // summary_html() échappe déjà chaque valeur.
            echo self::summary_html(__('Last 24 hours', 'mxchat-plus'), $window); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        if (isset($store['total']) && is_array($store['total']) && (int) ($store['total']['requests'] ?? 0) > 0) {
            echo self::summary_html(__('Since installation', 'mxchat-plus'), $store['total']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        echo '<p style="margin-top:8px;color:#646970;">'
            . esc_html__('Per-model breakdown: wp mxchat-plus promptcache stats --by-model', 'mxchat-plus')
            . '</p>';
    }

    /**
     * Construit un bloc résumé HTML (entièrement échappé).
     *
     * @param string $label Titre du bloc.
     * @param mixed  $b     Bucket de stats.
     */
    public static function summary_html(string $label, mixed $b): string {
        if (!is_array($b)) {
            $b = [];
        }
        $cached      = (int) ($b['cache_read_tokens'] ?? 0);
        $creation    = (int) ($b['cache_creation_tokens'] ?? 0);
        $input       = (int) ($b['input_tokens'] ?? 0);
        $requests    = (int) ($b['requests'] ?? 0);
        $total_input = $input + $cached + $creation;
        $hit         = $total_input > 0 ? ($cached / $total_input) * 100 : 0.0;

        // Libellés sources en anglais (CLAUDE.md) : les anciens libellés français
        // s'affichaient tels quels sur un site en anglais.
        $rows = [
            __('Cache hit rate', 'mxchat-plus')         => number_format_i18n($hit, 1) . ' %',
            __('Requests', 'mxchat-plus')               => number_format_i18n($requests),
            __('Tokens read from cache', 'mxchat-plus') => number_format_i18n($cached),
            __('Uncached input tokens', 'mxchat-plus')  => number_format_i18n($input),
        ];

        $html  = '<h3 style="margin:.5em 0 .25em;">' . esc_html($label) . '</h3>';
        $html .= '<table class="widefat striped" style="margin-bottom:8px;"><tbody>';
        foreach ($rows as $k => $v) {
            $html .= '<tr><td>' . esc_html($k) . '</td><td style="text-align:right;"><strong>'
                . esc_html($v) . '</strong></td></tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }
}

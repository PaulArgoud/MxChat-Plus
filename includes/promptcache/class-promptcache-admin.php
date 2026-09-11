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
     * Rendu partagé widget / onglet : bloc 24 h, bloc cumulatif, pointeur CLI.
     * Aucun `return` de HTML : les deux appelants écrivent dans le flux de sortie.
     */
    private static function render_summaries(): void {
        $store = MxChat_Plus_PromptCache_Stats::get_store();
        if ($store === null) {
            echo '<p>' . esc_html__('No data yet — statistics start filling in with the first API responses.', 'mxchat-plus') . '</p>';
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

        $rows = [
            __('Taux de hit (cache)', 'mxchat-plus') => number_format_i18n($hit, 1) . ' %',
            __('Requêtes', 'mxchat-plus')            => number_format_i18n($requests),
            __('Tokens lus du cache', 'mxchat-plus') => number_format_i18n($cached),
            __('Tokens entrée bruts', 'mxchat-plus') => number_format_i18n($input),
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

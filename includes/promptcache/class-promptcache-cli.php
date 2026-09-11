<?php
/**
 * Prompt Cache module — surface WP-CLI.
 *
 *   wp mxchat-plus promptcache stats [--by-model] [--total]
 *   wp mxchat-plus promptcache reset [--total]
 *   wp mxchat-plus promptcache debug
 *
 * Ce fichier DÉCLARE la classe et rien d'autre : aucun WP_CLI::add_command() ici.
 * L'enregistrement de la commande appartient au noyau de mxchat-plus, qui décide
 * du nom et de l'ordre de chargement (même contrat que class-duckdb-cli.php).
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

class MxChat_Plus_PromptCache_CLI {

    /**
     * Affiche les statistiques de prompt caching (tous providers mesurés).
     *
     * ## OPTIONS
     *
     * [--by-model]
     * : Ajoute un récapitulatif par modèle (24 h, et cumulatif si --total).
     *
     * [--total]
     * : Affiche également les statistiques cumulatives depuis l'installation.
     *
     * @param array<int,string>        $args
     * @param array<string,string|bool> $assoc_args
     */
    public function stats(array $args, array $assoc_args): void {
        $show_total    = !empty($assoc_args['total']);
        $show_by_model = !empty($assoc_args['by-model']);

        $store = MxChat_Plus_PromptCache_Stats::get_store();

        if ($show_total) {
            $total = ($store !== null && isset($store['total']) && is_array($store['total'])) ? $store['total'] : null;
            if ($total === null) {
                \WP_CLI::log(__('Aucune statistique cumulative enregistrée.', 'mxchat-plus'));
            } else {
                $this->print_block(__('Cumulatif (depuis installation)', 'mxchat-plus'), $total, true);
                if ($show_by_model && !empty($total['per_model'])) {
                    $this->print_per_model(__('Détail par modèle (cumulatif)', 'mxchat-plus'), $total['per_model']);
                }
            }
        }

        $window = $store !== null ? MxChat_Plus_PromptCache_Stats::aggregate_window($store) : null;
        if ($window === null || (int) ($window['requests'] ?? 0) === 0) {
            \WP_CLI::log(__('Aucune requête dans les dernières 24 h.', 'mxchat-plus'));
            return;
        }
        if (isset($window['window_start'])) {
            $window['since'] = $window['window_start'];
        }
        $this->print_block(__('Dernières 24 h (glissant)', 'mxchat-plus'), $window, true);

        if ($show_by_model && !empty($window['per_model'])) {
            $this->print_per_model(__('Détail par modèle (24 h)', 'mxchat-plus'), $window['per_model']);
        }
    }

    /**
     * Réinitialise les statistiques 24 h et le debug.
     *
     * ## OPTIONS
     *
     * [--total]
     * : Réinitialise également les statistiques cumulatives depuis l'installation.
     *
     * @param array<int,string>        $args
     * @param array<string,string|bool> $assoc_args
     */
    public function reset(array $args, array $assoc_args): void {
        MxChat_Plus_PromptCache_Stats::delete_debug();
        if (!empty($assoc_args['total'])) {
            MxChat_Plus_PromptCache_Stats::delete_store();
            \WP_CLI::success(__('Statistiques (24 h + cumulatives) et debug réinitialisés.', 'mxchat-plus'));
        } else {
            // Vide seulement la fenêtre 24 h (buckets), conserve le cumulatif.
            MxChat_Plus_PromptCache_Stats::clear_window();
            \WP_CLI::success(__('Statistiques 24 h et debug réinitialisés (cumulatif conservé).', 'mxchat-plus'));
        }
    }

    /** Affiche les détails de la dernière requête interceptée (transient 1 h). */
    public function debug(): void {
        $debug = MxChat_Plus_PromptCache_Stats::get_debug();
        if ($debug === null) {
            \WP_CLI::log(__('Aucune donnée de debug (transient expiré ou aucune requête récente).', 'mxchat-plus'));
            return;
        }
        $yes = __('oui', 'mxchat-plus');
        $no  = __('non', 'mxchat-plus');
        \WP_CLI::log(sprintf(
            __(
                "Dernière requête  : %s\n" .
                "Modèle (request)  : %s\n" .
                "Modèle (réponse)  : %s\n" .
                "Seuil min (chars) : %d\n" .
                "Breakpoints ajoutés :\n" .
                "  - tools     : %s\n" .
                "  - system    : %s\n" .
                "  - prev_user : %s\n" .
                "  - last_user : %s\n" .
                "Usage (tokens) :\n" .
                "  - input                 : %d\n" .
                "  - cache_creation_input  : %d\n" .
                "  - cache_read_input      : %d",
                'mxchat-plus'
            ),
            wp_date('Y-m-d H:i:s', (int) ($debug['time'] ?? 0)),
            $debug['model'] ?? '—',
            $debug['response_model'] ?? '—',
            (int) ($debug['min_chars'] ?? 0),
            !empty($debug['breakpoints']['tools'])     ? $yes : $no,
            !empty($debug['breakpoints']['system'])    ? $yes : $no,
            !empty($debug['breakpoints']['prev_user']) ? $yes : $no,
            !empty($debug['breakpoints']['last_user']) ? $yes : $no,
            (int) ($debug['usage']['input_tokens'] ?? 0),
            (int) ($debug['usage']['cache_creation_input_tokens'] ?? 0),
            (int) ($debug['usage']['cache_read_input_tokens'] ?? 0)
        ));
    }

    /** @param array<string,mixed> $per_model */
    private function print_per_model(string $title, array $per_model): void {
        \WP_CLI::log("\n--- " . $title . ' ---');
        foreach ($per_model as $model => $m) {
            $this->print_block((string) $model, $m, false);
        }
    }

    /** @param mixed $s Bucket de stats. */
    private function print_block(string $label, mixed $s, bool $with_since): void {
        if (!is_array($s)) {
            $s = [];
        }
        $cached      = (int) ($s['cache_read_tokens'] ?? 0);
        $creation    = (int) ($s['cache_creation_tokens'] ?? 0);
        $input       = (int) ($s['input_tokens'] ?? 0);
        $requests    = (int) ($s['requests'] ?? 0);
        $total_input = $input + $cached + $creation;
        $hit_rate    = $total_input > 0 ? ($cached / $total_input) * 100 : 0.0;

        $lines = [
            sprintf("\n[%s]", $label),
            sprintf(__('  Requêtes              : %d', 'mxchat-plus'), $requests),
            sprintf(__('  Tokens lus du cache   : %d', 'mxchat-plus'), $cached),
            sprintf(__('  Tokens écrits cache   : %d', 'mxchat-plus'), $creation),
            sprintf(__('  Tokens entrée bruts   : %d', 'mxchat-plus'), $input),
            sprintf(__('  Taux de hit (cache)   : %.1f %%', 'mxchat-plus'), $hit_rate),
        ];
        if ($with_since && isset($s['since'])) {
            $lines[] = sprintf(
                __('  Depuis                : %s', 'mxchat-plus'),
                wp_date('Y-m-d H:i:s', (int) $s['since'])
            );
        }
        $hint = MxChat_Plus_PromptCache_Models::caching_hint($s);
        if ($hint !== '') {
            $lines[] = $hint;
        }
        \WP_CLI::log(implode("\n", $lines));
    }
}

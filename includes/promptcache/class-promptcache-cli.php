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
 *
 * Chaînes sources en anglais (CLAUDE.md) ; la traduction française vit dans
 * languages/mxchat-plus-fr_FR.po.
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
                \WP_CLI::log(__('No cumulative statistics recorded.', 'mxchat-plus'));
            } else {
                $this->print_block(__('Cumulative (since installation)', 'mxchat-plus'), $total, true);
                if ($show_by_model && !empty($total['per_model'])) {
                    $this->print_per_model(__('Per-model breakdown (cumulative)', 'mxchat-plus'), $total['per_model']);
                }
            }
        }

        $window = $store !== null ? MxChat_Plus_PromptCache_Stats::aggregate_window($store) : null;
        if ($window === null || (int) ($window['requests'] ?? 0) === 0) {
            \WP_CLI::log(__('No requests in the last 24 hours.', 'mxchat-plus'));
            return;
        }
        if (isset($window['window_start'])) {
            $window['since'] = $window['window_start'];
        }
        $this->print_block(__('Last 24 hours (sliding)', 'mxchat-plus'), $window, true);

        if ($show_by_model && !empty($window['per_model'])) {
            $this->print_per_model(__('Per-model breakdown (24 hours)', 'mxchat-plus'), $window['per_model']);
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
            \WP_CLI::success(__('Statistics (24 hours and cumulative) and debug data reset.', 'mxchat-plus'));
        } else {
            // Vide seulement la fenêtre 24 h (buckets), conserve le cumulatif.
            MxChat_Plus_PromptCache_Stats::clear_window();
            \WP_CLI::success(__('24-hour statistics and debug data reset (cumulative kept).', 'mxchat-plus'));
        }
    }

    /** Affiche les détails de la dernière requête interceptée (transient 1 h). */
    public function debug(): void {
        $debug = MxChat_Plus_PromptCache_Stats::get_debug();
        if ($debug === null) {
            \WP_CLI::log(__('No debug data (transient expired or no recent request).', 'mxchat-plus'));
            return;
        }
        $yes = __('yes', 'mxchat-plus');
        $no  = __('no', 'mxchat-plus');
        $bp  = isset($debug['breakpoints']) && is_array($debug['breakpoints']) ? $debug['breakpoints'] : [];

        $volatile = !empty($debug['volatile_system']) ? $yes : $no;
        $reason   = self::volatile_reason_label(is_string($debug['volatile_reason'] ?? null) ? $debug['volatile_reason'] : '');
        if ($reason !== '') {
            $volatile .= ' (' . $reason . ')';
        }

        \WP_CLI::log(sprintf(
            /* translators: 1: date and time, 2: requested model, 3: model in the response, 4: minimum size in characters, 5: payload shape identifier, 6: yes/no plus the reason, 7: count of lengthened TTLs, 8: count of repaired tool inputs, 9-13: yes/no per breakpoint, 14-16: token counts. */
            __(
                "Last request       : %1\$s\n" .
                "Model (request)    : %2\$s\n" .
                "Model (response)   : %3\$s\n" .
                "Min size (chars)   : %4\$d\n" .
                "Payload shape      : %5\$s\n" .
                "Volatile system    : %6\$s\n" .
                "TTLs lengthened    : %7\$d\n" .
                "Tool inputs fixed  : %8\$d\n" .
                "Breakpoints added  :\n" .
                "  - tools          : %9\$s\n" .
                "  - system         : %10\$s\n" .
                "  - prev_user      : %11\$s\n" .
                "  - last_user      : %12\$s\n" .
                "  - context_block  : %13\$s\n" .
                "Usage (tokens):\n" .
                "  - input                 : %14\$d\n" .
                "  - cache_creation_input  : %15\$d\n" .
                "  - cache_read_input      : %16\$d",
                'mxchat-plus'
            ),
            wp_date('Y-m-d H:i:s', (int) ($debug['time'] ?? 0)),
            is_string($debug['model'] ?? null) ? $debug['model'] : '—',
            is_string($debug['response_model'] ?? null) ? $debug['response_model'] : '—',
            (int) ($debug['min_chars'] ?? 0),
            is_string($debug['shape'] ?? null) ? $debug['shape'] : '—',
            $volatile,
            (int) ($debug['ttl_upgraded'] ?? 0),
            (int) ($debug['tool_inputs_repaired'] ?? 0),
            !empty($bp['tools'])         ? $yes : $no,
            !empty($bp['system'])        ? $yes : $no,
            !empty($bp['prev_user'])     ? $yes : $no,
            !empty($bp['last_user'])     ? $yes : $no,
            !empty($bp['context_block']) ? $yes : $no,
            (int) ($debug['usage']['input_tokens'] ?? 0),
            (int) ($debug['usage']['cache_creation_input_tokens'] ?? 0),
            (int) ($debug['usage']['cache_read_input_tokens'] ?? 0)
        ));
    }

    /** Libellé lisible d'une raison de system volatile ('' si aucune). */
    public static function volatile_reason_label(string $reason): string {
        return match ($reason) {
            MxChat_Plus_PromptCache_Models::VOLATILE_KB_BLOCK         => __('knowledge-base block in the system prompt ({context})', 'mxchat-plus'),
            MxChat_Plus_PromptCache_Models::VOLATILE_CURRENT_DATETIME => __('{current_datetime} placeholder, changes every minute', 'mxchat-plus'),
            MxChat_Plus_PromptCache_Models::VOLATILE_FILTER           => __('set by the mxchat_plus_promptcache_volatile_system filter', 'mxchat-plus'),
            default                                                   => '',
        };
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
            /* translators: %d: number of requests. */
            sprintf(__('  Requests                : %d', 'mxchat-plus'), $requests),
            /* translators: %d: number of tokens. */
            sprintf(__('  Tokens read from cache  : %d', 'mxchat-plus'), $cached),
            /* translators: %d: number of tokens. */
            sprintf(__('  Tokens written to cache : %d', 'mxchat-plus'), $creation),
            /* translators: %d: number of tokens. */
            sprintf(__('  Uncached input tokens   : %d', 'mxchat-plus'), $input),
            /* translators: %.1f: percentage. */
            sprintf(__('  Cache hit rate          : %.1f %%', 'mxchat-plus'), $hit_rate),
        ];
        if ($with_since && isset($s['since'])) {
            $lines[] = sprintf(
                /* translators: %s: date and time. */
                __('  Since                   : %s', 'mxchat-plus'),
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

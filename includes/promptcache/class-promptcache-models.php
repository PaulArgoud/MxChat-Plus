<?php
/**
 * Prompt Cache module — connaissances « modèle » et helpers de mesure.
 *
 * Tout ce qui décide *si* un bloc mérite un breakpoint de cache :
 *   - la table des seuils minimum par modèle (mouvante côté Anthropic) ;
 *   - la mesure en caractères (mb-safe) du system / des tools ;
 *   - la fabrication de la structure cache_control (TTL 1 h vs 5 min).
 *
 * Classe 100 % statique et sans état : chaque méthode est pure (hors filtres WP),
 * ce qui la rend testable sans WordPress.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_PromptCache_Models {

    /**
     * Seuil minimum en caractères pour qu'Anthropic mette réellement en cache le préfixe.
     * Le minimum dépend du modèle ET de sa version (voir docs Anthropic « prompt caching »).
     *
     * ⚠ L'ORDRE des clauses est PORTEUR DE COMPORTEMENT et verrouillé par
     * PromptCacheFunctionsTest::test_min_chars_table_is_locked() :
     *   - `mythos-preview` doit être testé AVANT `fable`/`mythos` (préfixe commun) ;
     *   - `haiku-4` doit être testé AVANT `haiku` (sinon Haiku 4.5 tomberait sur 2048).
     * Une régression sur cet ordre est déjà survenue (corrigée en 0.7.0). Ne pas
     * réordonner, ne pas « factoriser » en table associative triée.
     *
     * @param mixed $model Nom de modèle tel qu'envoyé dans le payload.
     */
    public static function min_chars_for_model(mixed $model): int {
        if (!is_string($model) || $model === '') {
            return MXCHAT_PLUS_PC_MIN_CHARS_DEFAULT;
        }
        $m = strtolower($model);

        // Fable 5 / Mythos 5 : 512. (Mythos Preview est distinct : 2048 → testé avant.)
        if (str_contains($m, 'mythos-preview')) {
            return MXCHAT_PLUS_PC_MIN_CHARS_2048;
        }
        if (str_contains($m, 'fable') || str_contains($m, 'mythos')) {
            return MXCHAT_PLUS_PC_MIN_CHARS_512;
        }

        // Opus : le minimum varie par version.
        if (str_contains($m, 'opus')) {
            if (str_contains($m, 'opus-4-8')
                || str_contains($m, 'opus-4-1')
                || str_contains($m, 'opus-4-0')) {
                return MXCHAT_PLUS_PC_MIN_CHARS_1024;      // Opus 4.8 / 4.1 / 4.0 : 1024
            }
            if (str_contains($m, 'opus-4-7')) {
                return MXCHAT_PLUS_PC_MIN_CHARS_2048;      // Opus 4.7 : 2048
            }
            return MXCHAT_PLUS_PC_MIN_CHARS_4096;          // Opus 4.6 / 4.5 (et Opus inconnu) : 4096
        }

        // Haiku.
        if (str_contains($m, 'haiku-4')) {
            return MXCHAT_PLUS_PC_MIN_CHARS_4096;          // Haiku 4.5 : 4096
        }
        if (str_contains($m, 'haiku')) {
            return MXCHAT_PLUS_PC_MIN_CHARS_2048;          // Haiku 3.x (ex. claude-3-5-haiku) : 2048
        }

        // Sonnet : 4.6 / 4.5 / 4 / 3.x → 1024.
        if (str_contains($m, 'sonnet')) {
            return MXCHAT_PLUS_PC_MIN_CHARS_1024;
        }

        return MXCHAT_PLUS_PC_MIN_CHARS_DEFAULT;
    }

    /**
     * Longueur en CARACTÈRES (mb-safe), pas en octets.
     * Les seuils sont calibrés ~4 caractères/token ; en UTF-8 multioctet (arabe,
     * hébreu RTL, CJK…) strlen() compterait les octets et fausserait l'estimation.
     */
    public static function strlen(mixed $s): int {
        if (!is_string($s)) {
            return 0;
        }
        return function_exists('mb_strlen') ? mb_strlen($s) : \strlen($s);
    }

    /** Taille texte cumulée du bloc system (chaîne ou array de blocs). */
    public static function system_size(mixed $system): int {
        if (is_string($system)) {
            return self::strlen($system);
        }
        if (!is_array($system)) {
            return 0;
        }
        $total = 0;
        foreach ($system as $block) {
            if (is_array($block) && isset($block['text']) && is_string($block['text'])) {
                $total += self::strlen($block['text']);
            }
        }
        return $total;
    }

    /**
     * Taille cumulée des définitions tools (name + description + input_schema sérialisé).
     * Le paramètre $stop_at court-circuite la mesure dès que le seuil est atteint.
     */
    public static function tools_size(mixed $tools, int $stop_at = PHP_INT_MAX): int {
        if (!is_array($tools)) {
            return 0;
        }
        $total = 0;
        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            if (isset($tool['name']) && is_string($tool['name'])) {
                $total += self::strlen($tool['name']);
            }
            if (isset($tool['description']) && is_string($tool['description'])) {
                $total += self::strlen($tool['description']);
            }
            if (isset($tool['input_schema'])) {
                $encoded = wp_json_encode($tool['input_schema']);
                if (is_string($encoded)) {
                    $total += self::strlen($encoded);
                }
            }
            if ($total >= $stop_at) {
                return $total;
            }
        }
        return $total;
    }

    /** True si au moins un bloc porte déjà un cache_control. */
    public static function blocks_have_cache_control(mixed $blocks): bool {
        if (!is_array($blocks)) {
            return false;
        }
        foreach ($blocks as $block) {
            if (is_array($block) && !empty($block['cache_control'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Nombre de breakpoints `cache_control` DÉJÀ présents dans le payload,
     * tous emplacements confondus (tools, system, contenu des messages).
     *
     * Indispensable depuis MxChat 3.2.21, qui pose lui-même un breakpoint sur
     * son bloc system (MxChat_Integrator::mxchat_anthropic_system_blocks).
     * Anthropic en plafonne le nombre à 4 par requête et rejette la requête
     * au-delà : nos propres breakpoints doivent donc se compter EN PLUS de
     * ceux de l'hôte, et non repartir de zéro. Ne pas le faire fonctionne
     * aujourd'hui par coïncidence arithmétique (3 + 1 = 4) et casserait dès
     * que l'hôte en ajouterait un second.
     */
    public static function count_existing_breakpoints(mixed $payload): int {
        if (!is_array($payload)) {
            return 0;
        }

        $count = 0;

        foreach (['tools', 'system'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                foreach ($payload[$key] as $block) {
                    if (is_array($block) && !empty($block['cache_control'])) {
                        $count++;
                    }
                }
            }
        }

        if (isset($payload['messages']) && is_array($payload['messages'])) {
            foreach ($payload['messages'] as $msg) {
                if (is_array($msg) && isset($msg['content']) && is_array($msg['content'])) {
                    foreach ($msg['content'] as $block) {
                        if (is_array($block) && !empty($block['cache_control'])) {
                            $count++;
                        }
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Indexes des N derniers messages avec role=user (plus récent en premier).
     *
     * @return list<int>
     */
    public static function last_user_indexes(mixed $messages, int $limit = 2): array {
        $indexes = [];
        if (!is_array($messages)) {
            return $indexes;
        }
        for ($i = count($messages) - 1; $i >= 0 && count($indexes) < $limit; $i--) {
            if (isset($messages[$i]['role']) && $messages[$i]['role'] === 'user') {
                $indexes[] = $i;
            }
        }
        return $indexes;
    }

    /** Ajoute cache_control au dernier bloc d'un content (string ou array). Renvoie true si muté. */
    public static function add_cache_control_to_content(mixed &$content): bool {
        if (is_string($content)) {
            $content = [[
                'type'          => 'text',
                'text'          => $content,
                'cache_control' => self::ephemeral_control(),
            ]];
            return true;
        }
        if (is_array($content) && count($content) > 0) {
            $last = count($content) - 1;
            if (is_array($content[$last])) {
                $content[$last]['cache_control'] = self::ephemeral_control();
                return true;
            }
        }
        return false;
    }

    /**
     * TTL 1 h par défaut, fallback 5 min si opt-out via constante. Filtrable.
     *
     * Économie : prime d'écriture ×2 (1 h) vs ×1,25 (5 min). La 1 h protège les
     * conversations avec pauses > 5 min (évite une réécriture complète de
     * l'historique au retour de l'utilisateur). Pour du chat très rapide
     * (tours < 5 min), la 5 min suffit et coûte moins cher :
     *   define('MXCHAT_PLUS_PC_EXTENDED_TTL', false);  // ou via le filtre ci-dessous.
     *
     * @return array{type:string, ttl?:string}
     */
    public static function ephemeral_control(): array {
        if (defined('MXCHAT_PLUS_PC_EXTENDED_TTL') && MXCHAT_PLUS_PC_EXTENDED_TTL === false) {
            $control = ['type' => 'ephemeral'];
        } else {
            $control = ['type' => 'ephemeral', 'ttl' => '1h'];
        }
        /**
         * Filtre la structure cache_control envoyée à Anthropic.
         *
         * @param array{type:string, ttl?:string} $control Tableau {type, ttl?}.
         */
        $filtered = apply_filters('mxchat_plus_promptcache_ephemeral_control', $control);

        // Un filtre tiers peut renvoyer n'importe quoi ; sans `type` la clé
        // cache_control est invalide côté Anthropic, donc on retombe sur la
        // valeur calculée plutôt que d'émettre une requête rejetée.
        if (!is_array($filtered) || !isset($filtered['type']) || !is_string($filtered['type'])) {
            return $control;
        }

        $out = ['type' => $filtered['type']];
        if (isset($filtered['ttl']) && is_string($filtered['ttl'])) {
            $out['ttl'] = $filtered['ttl'];
        }

        return $out;
    }

    /** True si la TTL retenue est la TTL étendue 1 h (⇒ header beta requis). */
    public static function using_extended_ttl(): bool {
        $control = self::ephemeral_control();
        return isset($control['ttl']) && $control['ttl'] === '1h';
    }

    /**
     * Avertissement diagnostic : cache écrit mais peu/pas relu.
     *
     * Ne se déclenche que sur l'injection Anthropic (cache_creation > 0). Pour les
     * providers à cache automatique, cache_creation vaut toujours 0 → pas de bruit.
     * Symptôme classique : préfixe instable (shortcode/donnée dynamique dans le
     * system prompt via `mxchat_system_instructions` ou `do_shortcode`), historique
     * reformaté entre les tours, ou simplement trop peu de trafic répété.
     *
     * @param mixed $s Bucket de stats.
     * @return string Message traduit, ou '' si aucun avertissement.
     */
    public static function caching_hint(mixed $s): string {
        if (!is_array($s)) {
            return '';
        }
        $creation = (int) ($s['cache_creation_tokens'] ?? 0);
        $read     = (int) ($s['cache_read_tokens'] ?? 0);
        $requests = (int) ($s['requests'] ?? 0);
        if ($creation > 0 && $requests >= 5 && $read < $creation) {
            return __(
                "  ⚠ Écritures de cache > lectures : préfixe probablement instable "
                . "(shortcode/donnée dynamique dans le system prompt, historique reformaté entre les tours) "
                . "ou trafic trop faible pour rentabiliser le cache. Voir « Limites connues » du README.",
                'mxchat-plus'
            );
        }
        return '';
    }
}

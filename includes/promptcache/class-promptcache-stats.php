<?php
/**
 * Prompt Cache module — mesure, agrégation et persistance des statistiques.
 *
 * Stockage UNIQUE (option non-autoloadée MXCHAT_PLUS_PC_STORE_KEY) :
 *   { buckets: { <heure epoch/3600> => bucket }, total: bucket + since }
 * soit UNE lecture + UNE écriture par réponse API mesurée (au lieu d'un
 * transient 24 h + une option cumulative séparés).
 *
 * NB concurrence : le read-modify-write n'est pas atomique → compteurs
 * « best-effort » sous forte charge (léger sous-comptage possible, donnée
 * d'observabilité uniquement). Un object cache persistant garde l'option en
 * mémoire et limite la casse.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_PromptCache_Stats {

    /** Nombre de buckets horaires conservés (heure courante + 23 précédentes). */
    const WINDOW_HOURS = 24;

    // ──────────────────────────────────────────────────────────────────
    //  Reconnaissance d'endpoint + lecture d'usage
    // ──────────────────────────────────────────────────────────────────

    /**
     * Identifie l'endpoint d'un provider supporté pour la MESURE du cache.
     *
     * Distinct de MxChat_Plus_PromptCache::is_anthropic_messages_url() (qui gate
     * l'INJECTION, réservée à Anthropic). Ici on reconnaît aussi les endpoints
     * OpenAI-compatibles (OpenAI / OpenRouter / xAI / DeepSeek) et Gemini, dont le
     * cache est AUTOMATIQUE côté provider : on n'injecte rien, mais on lit les
     * tokens cachés retournés pour offrir un tableau de bord d'économies unifié.
     *
     * Note : ces réponses ne sont vues que pour les appels NON-STREAMÉS
     * (wp_remote_post). Le streaming MXChat passe par cURL direct → ni intercepté
     * ni mesuré ici.
     *
     * @return array{provider:string, style:string, model?:string}|null
     */
    public static function identify_endpoint(mixed $url): ?array {
        if (!is_string($url)) {
            return null;
        }
        $parsed = wp_parse_url($url);
        if (!is_array($parsed)) {
            return null;
        }
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '';

        switch ($host) {
            case 'api.anthropic.com':
                if ($path === '/v1/messages') {
                    return ['provider' => 'anthropic', 'style' => 'anthropic'];
                }
                break;
            case 'api.openai.com':
                if ($path === '/v1/chat/completions') {
                    return ['provider' => 'openai', 'style' => 'openai'];
                }
                break;
            case 'openrouter.ai':
                if ($path === '/api/v1/chat/completions') {
                    return ['provider' => 'openrouter', 'style' => 'openai'];
                }
                break;
            case 'api.x.ai':
                if ($path === '/v1/chat/completions') {
                    return ['provider' => 'xai', 'style' => 'openai'];
                }
                break;
            case 'api.deepseek.com':
                if ($path === '/chat/completions' || $path === '/v1/chat/completions') {
                    return ['provider' => 'deepseek', 'style' => 'openai'];
                }
                break;
            case 'generativelanguage.googleapis.com':
                // Chemin non-streamé :generateContent uniquement
                // (exclut :streamGenerateContent et :embedContent).
                if (str_contains($path, ':generateContent')) {
                    $model = '';
                    if (preg_match('#/models/([^:/]+):generateContent#', $path, $m)) {
                        $model = $m[1];
                    }
                    return ['provider' => 'gemini', 'style' => 'gemini', 'model' => $model];
                }
                break;
        }
        return null;
    }

    /**
     * Normalise l'usage tokens d'une réponse vers le schéma interne
     * {model, input, cache_creation, cache_read}.
     *
     * - anthropic         : usage.{input_tokens, cache_creation_input_tokens, cache_read_input_tokens}
     * - openai-compatible : usage.prompt_tokens + usage.prompt_tokens_details.cached_tokens
     *                       (cache automatique → pas d'écriture explicite, cache_creation = 0)
     * - gemini            : usageMetadata.{promptTokenCount, cachedContentTokenCount}
     *
     * @return array{model:string, input:int, cache_creation:int, cache_read:int}|null
     */
    public static function parse_usage(mixed $endpoint, mixed $data): ?array {
        if (!is_array($endpoint) || !is_array($data)) {
            return null;
        }
        $style = $endpoint['style'] ?? '';

        if ($style === 'anthropic') {
            if (!isset($data['usage']) || !is_array($data['usage'])) {
                return null;
            }
            $u = $data['usage'];
            return [
                'model'          => (isset($data['model']) && is_string($data['model'])) ? $data['model'] : 'unknown',
                'input'          => (int) ($u['input_tokens'] ?? 0),
                'cache_creation' => (int) ($u['cache_creation_input_tokens'] ?? 0),
                'cache_read'     => (int) ($u['cache_read_input_tokens'] ?? 0),
            ];
        }

        if ($style === 'openai') {
            if (!isset($data['usage']) || !is_array($data['usage'])) {
                return null;
            }
            $u      = $data['usage'];
            $prompt = (int) ($u['prompt_tokens'] ?? 0);
            $cached = isset($u['prompt_tokens_details']['cached_tokens'])
                ? (int) $u['prompt_tokens_details']['cached_tokens']
                : 0;
            return [
                'model'          => (isset($data['model']) && is_string($data['model'])) ? $data['model'] : 'unknown',
                'input'          => max(0, $prompt - $cached), // reste non-caché, sémantique alignée sur Anthropic
                'cache_creation' => 0,
                'cache_read'     => $cached,
            ];
        }

        if ($style === 'gemini') {
            if (!isset($data['usageMetadata']) || !is_array($data['usageMetadata'])) {
                return null;
            }
            $u      = $data['usageMetadata'];
            $prompt = (int) ($u['promptTokenCount'] ?? 0);
            $cached = (int) ($u['cachedContentTokenCount'] ?? 0);
            $model  = (isset($data['modelVersion']) && is_string($data['modelVersion']) && $data['modelVersion'] !== '')
                ? $data['modelVersion']
                : (string) ($endpoint['model'] ?? 'unknown');
            return [
                'model'          => $model !== '' ? $model : 'unknown',
                'input'          => max(0, $prompt - $cached),
                'cache_creation' => 0,
                'cache_read'     => $cached,
            ];
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Buckets
    // ──────────────────────────────────────────────────────────────────

    /**
     * Bucket de stats vide (une heure de la fenêtre, ou l'accumulateur cumulatif).
     *
     * @return array{requests:int,cache_creation_tokens:int,cache_read_tokens:int,input_tokens:int,per_model:array<string,array<string,int>>}
     */
    public static function empty_bucket(): array {
        return [
            'requests'              => 0,
            'cache_creation_tokens' => 0,
            'cache_read_tokens'     => 0,
            'input_tokens'          => 0,
            'per_model'             => [],
        ];
    }

    /**
     * Incrémente un bucket : compteurs globaux + ventilation par modèle.
     *
     * @param array<string,mixed> $bucket
     */
    public static function bucket_add(array &$bucket, string $model, int $input, int $creation, int $read): void {
        $bucket['requests']              = (int) ($bucket['requests'] ?? 0) + 1;
        $bucket['cache_creation_tokens'] = (int) ($bucket['cache_creation_tokens'] ?? 0) + $creation;
        $bucket['cache_read_tokens']     = (int) ($bucket['cache_read_tokens'] ?? 0) + $read;
        $bucket['input_tokens']          = (int) ($bucket['input_tokens'] ?? 0) + $input;

        if (!isset($bucket['per_model']) || !is_array($bucket['per_model'])) {
            $bucket['per_model'] = [];
        }
        if (!isset($bucket['per_model'][$model])) {
            $bucket['per_model'][$model] = [
                'requests'              => 0,
                'cache_creation_tokens' => 0,
                'cache_read_tokens'     => 0,
                'input_tokens'          => 0,
            ];
        }
        $bucket['per_model'][$model]['requests']++;
        $bucket['per_model'][$model]['cache_creation_tokens'] += $creation;
        $bucket['per_model'][$model]['cache_read_tokens']     += $read;
        $bucket['per_model'][$model]['input_tokens']          += $input;
    }

    /**
     * Agrège les buckets horaires de la fenêtre glissante (dernières N heures).
     * Renvoie un bloc {requests, *_tokens, per_model, window_start?}.
     */
    /** @return array<string,mixed> */
    public static function aggregate_window(mixed $stats, int $window_hours = self::WINDOW_HOURS): array {
        $agg    = self::empty_bucket();
        $hour   = (int) floor(time() / HOUR_IN_SECONDS);
        $cutoff = $hour - ($window_hours - 1);
        $oldest = null;

        if (isset($stats['buckets']) && is_array($stats['buckets'])) {
            foreach ($stats['buckets'] as $h => $b) {
                if ((int) $h < $cutoff || !is_array($b)) {
                    continue;
                }
                if ($oldest === null || (int) $h < $oldest) {
                    $oldest = (int) $h;
                }
                $agg['requests']              += (int) ($b['requests'] ?? 0);
                $agg['cache_creation_tokens'] += (int) ($b['cache_creation_tokens'] ?? 0);
                $agg['cache_read_tokens']     += (int) ($b['cache_read_tokens'] ?? 0);
                $agg['input_tokens']          += (int) ($b['input_tokens'] ?? 0);
                if (isset($b['per_model']) && is_array($b['per_model'])) {
                    foreach ($b['per_model'] as $model => $m) {
                        if (!isset($agg['per_model'][$model])) {
                            $agg['per_model'][$model] = [
                                'requests'              => 0,
                                'cache_creation_tokens' => 0,
                                'cache_read_tokens'     => 0,
                                'input_tokens'          => 0,
                            ];
                        }
                        $agg['per_model'][$model]['requests']              += (int) ($m['requests'] ?? 0);
                        $agg['per_model'][$model]['cache_creation_tokens'] += (int) ($m['cache_creation_tokens'] ?? 0);
                        $agg['per_model'][$model]['cache_read_tokens']     += (int) ($m['cache_read_tokens'] ?? 0);
                        $agg['per_model'][$model]['input_tokens']          += (int) ($m['input_tokens'] ?? 0);
                    }
                }
            }
        }
        if ($oldest !== null) {
            $agg['window_start'] = $oldest * HOUR_IN_SECONDS;
        }
        return $agg;
    }

    /**
     * Charge/normalise le store de stats : { buckets:{h=>bucket}, total:{bucket,since} }.
     *
     * @return array<string,mixed>
     */
    public static function normalize_store(mixed $store): array {
        if (!is_array($store)) {
            $store = [];
        }
        if (!isset($store['buckets']) || !is_array($store['buckets'])) {
            $store['buckets'] = [];
        }
        if (!isset($store['total']) || !is_array($store['total'])) {
            $store['total'] = self::empty_bucket();
            $store['total']['since'] = time();
        }
        return $store;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Persistance (option + transient de debug)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Store brut tel que persisté, ou null si absent/corrompu.
     *
     * @return array<string,mixed>|null
     */
    public static function get_store(): ?array {
        $store = get_option(MXCHAT_PLUS_PC_STORE_KEY, null);
        return is_array($store) ? $store : null;
    }

    /**
     * Écrit le store (jamais autoloadé : il grossit et n'est lu qu'à la demande).
     *
     * @param array<string,mixed> $store
     */
    public static function save_store(array $store): void {
        update_option(MXCHAT_PLUS_PC_STORE_KEY, $store, false);
    }

    /** Supprime intégralement le store (fenêtre 24 h + cumulatif). */
    public static function delete_store(): void {
        delete_option(MXCHAT_PLUS_PC_STORE_KEY);
    }

    /** Vide seulement la fenêtre glissante (buckets), conserve le cumulatif. */
    public static function clear_window(): void {
        $store = self::get_store();
        if ($store === null) {
            return;
        }
        $store['buckets'] = [];
        self::save_store($store);
    }

    /**
     * Enregistre une réponse mesurée : bucket de l'heure courante + cumulatif,
     * en UNE lecture + UNE écriture. Purge au passage les buckets hors fenêtre.
     */
    public static function record_sample(string $model, int $input, int $creation, int $read): void {
        $hour   = (int) floor(time() / HOUR_IN_SECONDS);
        $cutoff = $hour - (self::WINDOW_HOURS - 1); // 24 buckets : heure courante + 23 précédentes

        $store = self::normalize_store(get_option(MXCHAT_PLUS_PC_STORE_KEY, null));

        foreach ($store['buckets'] as $h => $_b) {
            if ((int) $h < $cutoff) {
                unset($store['buckets'][$h]);
            }
        }
        if (!isset($store['buckets'][$hour])) {
            $store['buckets'][$hour] = self::empty_bucket();
        }
        self::bucket_add($store['buckets'][$hour], $model, $input, $creation, $read);
        self::bucket_add($store['total'], $model, $input, $creation, $read);

        self::save_store($store);
    }

    /**
     * Détail de la dernière requête interceptée, ou null.
     *
     * @return array<string,mixed>|null
     */
    public static function get_debug(): ?array {
        $debug = get_transient(MXCHAT_PLUS_PC_DEBUG_KEY);
        return is_array($debug) ? $debug : null;
    }

    /**
     * Persiste le détail de la dernière requête (transient 1 h).
     *
     * @param array<string,mixed> $debug
     */
    public static function set_debug(array $debug): void {
        set_transient(MXCHAT_PLUS_PC_DEBUG_KEY, $debug, HOUR_IN_SECONDS);
    }

    /** Supprime le transient de debug. */
    public static function delete_debug(): void {
        delete_transient(MXCHAT_PLUS_PC_DEBUG_KEY);
    }
}

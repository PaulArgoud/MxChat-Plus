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
     * OpenAI-compatibles (OpenAI / OpenRouter / xAI / DeepSeek, compatibilité
     * OpenAI de Gemini, fournisseur personnalisé de MxChat), la Responses API
     * d'OpenAI et Gemini natif, dont le cache est AUTOMATIQUE côté provider : on
     * n'injecte rien, mais on lit les tokens cachés retournés pour offrir un
     * tableau de bord d'économies unifié.
     *
     * Note : ces réponses ne sont vues que pour les appels NON-STREAMÉS
     * (wp_remote_post). Le streaming MXChat passe par cURL direct → ni intercepté
     * ni mesuré ici.
     *
     * `strict_usage` : l'échantillon n'est enregistré que si la réponse publie
     * explicitement ses tokens cachés. Posé sur les endpoints dont on ignore
     * s'ils le font (compatibilité OpenAI de Gemini, fournisseur personnalisé :
     * Ollama, LM Studio, vLLM… n'en renvoient en général aucun) — sans quoi
     * chaque requête y passerait pour un 0 % de hit au lieu d'une absence de
     * mesure.
     *
     * @return array{provider:string, style:string, model?:string, strict_usage?:bool}|null
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
                // Responses API : réponse web search non streamée de MxChat
                // (mxchat_web_search_non_streaming_response) et récupération
                // Vector Store (find_relevant_content_openai_vectorstore,
                // file_search), MxChat 3.2.21 → 3.2.25. Cf. request_label().
                if ($path === '/v1/responses') {
                    return ['provider' => 'openai', 'style' => 'openai_responses'];
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
                // Endpoint OpenAI-compatible de Google, atteint par le
                // « fournisseur personnalisé » de MxChat (base .../v1beta/openai).
                // Que Google y publie prompt_tokens_details.cached_tokens n'est
                // pas vérifiable ici : mesure stricte.
                if (str_ends_with(rtrim($path, '/'), '/openai/chat/completions')) {
                    return ['provider' => 'gemini', 'style' => 'openai', 'strict_usage' => true];
                }
                break;
        }

        if (self::is_custom_provider_url($parsed)) {
            return ['provider' => 'custom', 'style' => 'openai', 'strict_usage' => true];
        }
        return null;
    }

    /**
     * L'URL est-elle celle du « fournisseur personnalisé » (OpenAI-compatible)
     * configuré dans MxChat ?
     *
     * Lit, sans jamais l'écrire, la clé `custom_provider_base_url` de l'option
     * `mxchat_options` — deux littéraux de l'HÔTE, à ne pas renommer. MxChat
     * bâtit l'URL de deux façons (MxChat 3.2.25) :
     *   - mxchat_resolve_custom_provider() (chat) : base + '/chat/completions',
     *     plus `?api-version=` pour Azure ;
     *   - mxchat_fc_resolve_provider() (AI Tools) : la base telle quelle si elle
     *     contient déjà 'chat/completions', sinon base + '/chat/completions'.
     * On compare donc schéma + hôte + port + chemin (la requête est ignorée),
     * et on accepte les deux formes.
     *
     * Limite : seule la configuration globale est lue ; un bot Multi-Bot qui
     * pointe ailleurs n'est pas reconnu.
     *
     * @param array<mixed> $parsed Résultat de wp_parse_url() sur l'URL appelée.
     */
    public static function is_custom_provider_url(array $parsed): bool {
        $options = get_option('mxchat_options', []);
        if (!is_array($options) || !isset($options['custom_provider_base_url']) || !is_string($options['custom_provider_base_url'])) {
            return false;
        }
        $base = rtrim(trim($options['custom_provider_base_url']), '/');
        if ($base === '') {
            return false;
        }
        $base_parsed = wp_parse_url($base);
        if (!is_array($base_parsed) || empty($base_parsed['host'])) {
            return false;
        }

        $same_origin = strtolower((string) ($parsed['scheme'] ?? '')) === strtolower((string) ($base_parsed['scheme'] ?? ''))
            && strtolower((string) ($parsed['host'] ?? '')) === strtolower((string) $base_parsed['host'])
            && (int) ($parsed['port'] ?? 0) === (int) ($base_parsed['port'] ?? 0);
        if (!$same_origin) {
            return false;
        }

        $path      = rtrim((string) ($parsed['path'] ?? ''), '/');
        $base_path = rtrim((string) ($base_parsed['path'] ?? ''), '/');
        return $path === $base_path . '/chat/completions'
            || (str_contains($base_path, 'chat/completions') && $path === $base_path);
    }

    /**
     * Suffixe ajouté au nom de modèle pour distinguer un appel particulier dans
     * la ventilation par modèle ; '' sinon.
     *
     * La récupération Vector Store de MxChat passe par la Responses API, comme
     * la réponse web search : même URL, mais c'est une recherche (outil
     * file_search, pas de clé `instructions`), envoyée en plus de la réponse à
     * chaque message. Elle reste comptée — c'est une dépense réelle — sous un
     * libellé à part, pour qu'on ne la confonde pas avec la réponse elle-même.
     *
     * @param array<mixed> $endpoint Résultat d'identify_endpoint().
     * @param mixed        $args     Arguments de la requête (body JSON).
     */
    public static function request_label(array $endpoint, mixed $args): string {
        if (($endpoint['style'] ?? '') !== 'openai_responses' || !is_array($args) || !isset($args['body']) || !is_string($args['body'])) {
            return '';
        }
        $body = json_decode($args['body'], true);
        if (!is_array($body) || array_key_exists('instructions', $body) || !isset($body['tools']) || !is_array($body['tools'])) {
            return '';
        }
        foreach ($body['tools'] as $tool) {
            if (is_array($tool) && ($tool['type'] ?? null) === 'file_search') {
                return ' (file_search)';
            }
        }
        return '';
    }

    /**
     * Normalise l'usage tokens d'une réponse vers le schéma interne
     * {model, input, cache_creation, cache_read}.
     *
     * - anthropic         : usage.{input_tokens, cache_creation_input_tokens, cache_read_input_tokens}
     * - openai-compatible : usage.prompt_tokens + usage.prompt_tokens_details.cached_tokens
     *                       (cache automatique → pas d'écriture explicite, cache_creation = 0)
     * - openai_responses  : usage.input_tokens + usage.input_tokens_details.cached_tokens
     *                       (sémantique de l'API OpenAI, absente du code de l'hôte :
     *                       à vérifier sur une vraie réponse)
     * - gemini            : usageMetadata.{promptTokenCount, cachedContentTokenCount}
     *
     * Avec `strict_usage`, une réponse qui ne publie pas ses tokens cachés n'est
     * pas un échantillon (null), cf. identify_endpoint().
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

        $strict = !empty($endpoint['strict_usage']);

        if ($style === 'openai_responses') {
            if (!isset($data['usage']) || !is_array($data['usage'])) {
                return null;
            }
            $u      = $data['usage'];
            $prompt = (int) ($u['input_tokens'] ?? 0);
            $cached = isset($u['input_tokens_details']) && is_array($u['input_tokens_details']) && isset($u['input_tokens_details']['cached_tokens'])
                ? (int) $u['input_tokens_details']['cached_tokens']
                : 0;
            return [
                'model'          => (isset($data['model']) && is_string($data['model'])) ? $data['model'] : 'unknown',
                'input'          => max(0, $prompt - $cached),
                'cache_creation' => 0,
                'cache_read'     => $cached,
            ];
        }

        if ($style === 'openai') {
            if (!isset($data['usage']) || !is_array($data['usage'])) {
                return null;
            }
            $u        = $data['usage'];
            $prompt   = (int) ($u['prompt_tokens'] ?? 0);
            $reported = isset($u['prompt_tokens_details']) && is_array($u['prompt_tokens_details'])
                && isset($u['prompt_tokens_details']['cached_tokens']);
            if ($strict && !$reported) {
                return null;
            }
            $cached = $reported ? (int) $u['prompt_tokens_details']['cached_tokens'] : 0;
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

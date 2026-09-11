<?php
/**
 * Per-bot retrieval overrides (v0.13.0).
 *
 * Storage-level settings (backend, table, dimension, metric, HNSW, layout) are
 * always global — every bot shares one physical store. This section overrides
 * only the *retrieval-quality* knobs per bot: hybrid BM25, dedup, query cache,
 * and the slow-query threshold. A bot with "Override" unticked inherits the
 * global Retrieval settings above.
 *
 * Field names post into mxchat_plus_duckdb_options[bot_overrides][<bot_id>][...],
 * handled by MxChat_Plus_DuckDB_Options::sanitize_for_save() and resolved at query
 * time by get_for_bot(). Power users can also drive this from code via the
 * `mxchat_plus_duckdb_bot_config` filter (see docs/HOOKS.md).
 *
 * @var array<string, mixed> $opts
 * @var string[]             $known_bots
 */
if (!defined('ABSPATH')) { exit; }

$opt_key   = MXCHAT_PLUS_DUCKDB_OPTION_KEY;
$overrides = is_array($opts['bot_overrides'] ?? null) ? $opts['bot_overrides'] : [];
?>
<h2><?php esc_html_e('Per-bot retrieval overrides', 'mxchat-plus'); ?></h2>
<p class="description">
    <?php esc_html_e(
        'Tune retrieval per bot for multi-bot installs. Only hybrid search, dedup, the query cache, and the slow-query threshold can vary per bot — backend and storage are always shared. A bot with "Override" off inherits the global Retrieval settings above. You can also set these from code with the mxchat_plus_duckdb_bot_config filter.',
        'mxchat-plus'
    ); ?>
</p>

<?php if (empty($known_bots)): ?>
    <p class="description">
        <em><?php esc_html_e(
            'No bots discovered yet — enable a backend and sync some content first, then your bot IDs will appear here. (The default bot is "default".)',
            'mxchat-plus'
        ); ?></em>
    </p>
<?php else: ?>
<table class="widefat striped" style="max-width:980px;">
    <thead>
        <tr>
            <th><?php esc_html_e('Bot', 'mxchat-plus'); ?></th>
            <th><?php esc_html_e('Override', 'mxchat-plus'); ?></th>
            <th><?php esc_html_e('Hybrid BM25', 'mxchat-plus'); ?></th>
            <th><?php esc_html_e('Alpha', 'mxchat-plus'); ?></th>
            <th><?php esc_html_e('Dedup', 'mxchat-plus'); ?></th>
            <th><?php esc_html_e('Query cache', 'mxchat-plus'); ?></th>
            <th><?php esc_html_e('Cache TTL (s)', 'mxchat-plus'); ?></th>
            <th><?php esc_html_e('Slow query (ms)', 'mxchat-plus'); ?></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($known_bots as $bot):
        $ov       = is_array($overrides[$bot] ?? null) ? $overrides[$bot] : null;
        $enabled  = $ov !== null;
        // Pre-fill each field from the stored override when present, else the
        // current global value, so an operator starts from the inherited state.
        $hybrid   = $enabled ? !empty($ov['hybrid_enabled'])      : !empty($opts['hybrid_enabled']);
        $alpha    = $enabled ? (float) ($ov['hybrid_alpha'] ?? $opts['hybrid_alpha']) : (float) ($opts['hybrid_alpha'] ?? 0.7);
        $dedup    = $enabled ? !empty($ov['dedup_per_source'])    : !empty($opts['dedup_per_source']);
        $cache    = $enabled ? !empty($ov['query_cache_enabled']) : !empty($opts['query_cache_enabled']);
        $ttl      = $enabled ? (int) ($ov['query_cache_ttl'] ?? $opts['query_cache_ttl']) : (int) ($opts['query_cache_ttl'] ?? 300);
        $slow     = $enabled ? (int) ($ov['slow_query_ms'] ?? $opts['slow_query_ms'])     : (int) ($opts['slow_query_ms'] ?? 500);
        $base     = $opt_key . '[bot_overrides][' . esc_attr($bot) . ']';
    ?>
        <tr>
            <td><code><?php echo esc_html($bot); ?></code></td>
            <td>
                <input type="checkbox" name="<?php echo esc_attr($base); ?>[_enabled]" value="1" <?php checked($enabled); ?>>
            </td>
            <td>
                <input type="checkbox" name="<?php echo esc_attr($base); ?>[hybrid_enabled]" value="1" <?php checked($hybrid); ?>>
            </td>
            <td>
                <input type="number" step="0.05" min="0" max="1" class="small-text"
                       name="<?php echo esc_attr($base); ?>[hybrid_alpha]" value="<?php echo esc_attr((string) $alpha); ?>">
            </td>
            <td>
                <input type="checkbox" name="<?php echo esc_attr($base); ?>[dedup_per_source]" value="1" <?php checked($dedup); ?>>
            </td>
            <td>
                <input type="checkbox" name="<?php echo esc_attr($base); ?>[query_cache_enabled]" value="1" <?php checked($cache); ?>>
            </td>
            <td>
                <input type="number" min="0" max="3600" class="small-text"
                       name="<?php echo esc_attr($base); ?>[query_cache_ttl]" value="<?php echo esc_attr((string) $ttl); ?>">
            </td>
            <td>
                <input type="number" min="0" class="small-text"
                       name="<?php echo esc_attr($base); ?>[slow_query_ms]" value="<?php echo esc_attr((string) $slow); ?>">
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<p class="description">
    <?php esc_html_e(
        'Untick "Override" and Save to make a bot fall back to the global settings.',
        'mxchat-plus'
    ); ?>
</p>
<?php endif; ?>

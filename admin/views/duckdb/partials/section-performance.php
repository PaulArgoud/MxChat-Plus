<?php
/** @var array<string, mixed> $opts */
if (!defined('ABSPATH')) { exit; }
?>
<h2><?php esc_html_e('Performance', 'mxchat-plus'); ?></h2>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e('Query cache', 'mxchat-plus'); ?></th>
        <td>
            <label>
                <input type="checkbox" name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[query_cache_enabled]" value="1" <?php checked(!empty($opts['query_cache_enabled'])); ?>>
                <?php esc_html_e('Cache top-K results (keyed by embedding hash + filter + bot). Invalidated automatically on upsert/delete.', 'mxchat-plus'); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="query_cache_ttl"><?php esc_html_e('Cache TTL (seconds)', 'mxchat-plus'); ?></label></th>
        <td>
            <input type="number" id="query_cache_ttl" class="small-text"
                   name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[query_cache_ttl]"
                   min="0" max="3600" value="<?php echo esc_attr((int) ($opts['query_cache_ttl'] ?? 300)); ?>">
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="slow_query_ms"><?php esc_html_e('Slow-query threshold (ms)', 'mxchat-plus'); ?></label></th>
        <td>
            <input type="number" id="slow_query_ms" class="small-text"
                   name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[slow_query_ms]"
                   min="0" value="<?php echo esc_attr((int) ($opts['slow_query_ms'] ?? 500)); ?>">
            <p class="description"><?php esc_html_e('Queries slower than this are written to the PHP error log. Set to 0 to disable.', 'mxchat-plus'); ?></p>
        </td>
    </tr>
</table>

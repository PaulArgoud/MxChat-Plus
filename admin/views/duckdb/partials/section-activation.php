<?php
/**
 * @var array<string, mixed> $opts Plugin options (provided by the parent settings.php).
 */
if (!defined('ABSPATH')) { exit; }
?>
<h2><?php esc_html_e('Activation', 'mxchat-plus'); ?></h2>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e('Enable DuckDB / MotherDuck', 'mxchat-plus'); ?></th>
        <td>
            <label>
                <input type="checkbox" name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[enabled]" value="1" <?php checked(!empty($opts['enabled'])); ?>>
                <?php esc_html_e('Enabled', 'mxchat-plus'); ?>
            </label>
            <p class="description">
                <?php esc_html_e(
                    'When enabled, MxChat uses this plugin as its vector store (in place of Pinecone). When disabled, MxChat falls back to its default behaviour.',
                    'mxchat-plus'
                ); ?>
            </p>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Backend', 'mxchat-plus'); ?></th>
        <td>
            <label style="margin-right:1em;">
                <input type="radio" name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[mode]" value="motherduck" <?php checked($opts['mode'] ?? '', 'motherduck'); ?>>
                <?php esc_html_e('MotherDuck (cloud, via ATTACH)', 'mxchat-plus'); ?>
            </label>
            <label>
                <input type="radio" name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[mode]" value="embedded" <?php checked($opts['mode'] ?? '', 'embedded'); ?>>
                <?php esc_html_e('DuckDB embedded (local file)', 'mxchat-plus'); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Default-bot routing', 'mxchat-plus'); ?></th>
        <td>
            <label>
                <input type="checkbox"
                       name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[takeover_default_bot_pinecone]"
                       value="1" <?php checked(!empty($opts['takeover_default_bot_pinecone'])); ?>>
                <?php esc_html_e('Route default-bot Pinecone calls through this plugin (Option B reach)', 'mxchat-plus'); ?>
            </label>
            <p class="description">
                <?php esc_html_e(
                    'MxChat reads its Pinecone settings straight from the database for the default bot when the Multi-Bot Manager isn\'t active, which bypasses the filter this plugin uses to advertise itself. Enable this to shortcircuit that read and route default-bot calls through the proxy too. Leave OFF if you\'re running real Pinecone alongside DuckDB on the default bot, or if you\'ve applied the upstream patch (Option A) — the patch path doesn\'t need this setting.',
                    'mxchat-plus'
                ); ?>
            </p>
        </td>
    </tr>
</table>

<?php
/**
 * @var array<string, mixed> $opts Plugin options (provided by the parent settings.php).
 * @var array{all: bool, bots: list<string>, migrated: bool} $bot_routing
 * @var bool $multibot_active
 */
if (!defined('ABSPATH')) { exit; }

$routing         = isset($bot_routing) && is_array($bot_routing) ? $bot_routing : MxChat_Plus_DuckDB_Options::bot_routing();
$multibot_active = !empty($multibot_active);
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
                    'Turns the DuckDB / MotherDuck vector store on. MxChat uses it only for the bots routed below: the default bot with Default-bot routing, other bots with Multi-Bot routing. Every other bot keeps its own Pinecone or WordPress-database storage. When disabled, MxChat falls back to its default behaviour.',
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
                    'MxChat reads the default bot\'s Pinecone settings straight from the database, so the filter this plugin uses for other bots never reaches it. Enable this to answer that read with the DuckDB proxy, so the default bot is served by DuckDB too. Your stored Pinecone settings are kept: switching this off gives them back to MxChat. Leave OFF if the default bot should keep real Pinecone or the WordPress database, or if you\'ve applied the upstream patch (Option A) — the patch path doesn\'t need this setting.',
                    'mxchat-plus'
                ); ?>
            </p>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Multi-Bot routing', 'mxchat-plus'); ?></th>
        <td>
            <label>
                <input type="checkbox"
                       name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[takeover_multibot_bots]"
                       value="1" <?php checked(!empty($routing['all'])); ?>>
                <?php esc_html_e('Route every Multi-Bot bot through this plugin', 'mxchat-plus'); ?>
            </label>
            <p style="margin-top:8px;">
                <label for="mxchat-plus-takeover-bot-ids"><?php esc_html_e('Or only these bot IDs (comma-separated):', 'mxchat-plus'); ?></label><br>
                <input type="text" id="mxchat-plus-takeover-bot-ids" class="regular-text code"
                       name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[takeover_bot_ids]"
                       value="<?php echo esc_attr(implode(', ', $routing['bots'])); ?>">
            </p>
            <p class="description">
                <?php esc_html_e(
                    'For MxChat\'s Multi-Bot add-on. A routed bot reads and writes its own DuckDB namespace, named after its bot ID, instead of the storage the add-on gives it — so it no longer sees the knowledge MxChat keeps in the WordPress database, which all bots share. A bot on a Pinecone document index is never routed. Bots that are not routed keep their own settings.',
                    'mxchat-plus'
                ); ?>
            </p>
            <?php if (empty($routing['migrated'])): ?>
                <?php
                // Marks this rendering as the pending-upgrade one, so a save
                // that leaves these fields untouched keeps the upgrade
                // pending (MxChat_Plus_DuckDB_Options::sanitize_routing()).
                ?>
                <input type="hidden"
                       name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[<?php echo esc_attr(MxChat_Plus_DuckDB_Options::ROUTING_LEGACY_FORM_FIELD); ?>]"
                       value="1">
                <p class="description">
                    <em><?php esc_html_e(
                        'This install predates the setting and still routes every bot, because DuckDB could not be read to find which bots have vectors there. As soon as it answers, only those bots stay routed. Saving this page without changing these fields leaves that upgrade pending; untick the box or list bot IDs to choose now.',
                        'mxchat-plus'
                    ); ?></em>
                </p>
                <p>
                    <label>
                        <input type="checkbox"
                               name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[<?php echo esc_attr(MxChat_Plus_DuckDB_Options::ROUTING_KEEP_ALL_FIELD); ?>]"
                               value="1">
                        <?php esc_html_e('Keep routing every bot (skip the automatic upgrade)', 'mxchat-plus'); ?>
                    </label>
                </p>
            <?php endif; ?>
            <?php if (!$multibot_active): ?>
                <p class="description">
                    <em><?php esc_html_e('The Multi-Bot add-on is not active, so this setting has no effect.', 'mxchat-plus'); ?></em>
                </p>
            <?php endif; ?>
        </td>
    </tr>
</table>

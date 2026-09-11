<?php
/** @var array<string, mixed> $opts */
if (!defined('ABSPATH')) { exit; }
?>
<h2><?php esc_html_e('MotherDuck', 'mxchat-plus'); ?></h2>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><label for="motherduck_token"><?php esc_html_e('Token', 'mxchat-plus'); ?></label></th>
        <td>
            <?php $token_overridden = MxChat_Plus_DuckDB_Options::motherduck_token_is_from_constant(); ?>
            <input type="password" id="motherduck_token" class="regular-text"
                   name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[motherduck_token]"
                   value="<?php echo esc_attr($opts['motherduck_token'] ?? ''); ?>"
                   autocomplete="off" <?php disabled($token_overridden); ?>>
            <p class="description">
                <?php if ($token_overridden) : ?>
                    <strong><?php esc_html_e('Overridden by the MXCHAT_PLUS_MOTHERDUCK_TOKEN constant in wp-config.php.', 'mxchat-plus'); ?></strong>
                    <?php esc_html_e('The token defined in wp-config takes precedence over this field; remove the constant to edit the value here.', 'mxchat-plus'); ?>
                <?php else : ?>
                    <?php
                    echo wp_kses_post(__(
                        'MotherDuck token (from <a href="https://app.motherduck.com" target="_blank" rel="noopener">app.motherduck.com</a>). For compliance-driven installs that forbid storing secrets in the database, define <code>MXCHAT_PLUS_MOTHERDUCK_TOKEN</code> in <code>wp-config.php</code> to override this field at runtime.',
                        'mxchat-plus'
                    ));
                    ?>
                <?php endif; ?>
            </p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="motherduck_database"><?php esc_html_e('Database', 'mxchat-plus'); ?></label></th>
        <td>
            <input type="text" id="motherduck_database" class="regular-text"
                   name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[motherduck_database]"
                   value="<?php echo esc_attr($opts['motherduck_database'] ?? 'my_db'); ?>">
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Local mirror', 'mxchat-plus'); ?></th>
        <td>
            <label>
                <input type="checkbox" id="motherduck_mirror_enabled"
                       name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[motherduck_mirror_enabled]"
                       value="1" <?php checked(!empty($opts['motherduck_mirror_enabled'])); ?>>
                <?php esc_html_e('Maintain a local DuckDB shadow with HNSW for fast reads (recommended for > 100k vectors)', 'mxchat-plus'); ?>
            </label>
            <p class="description">
                <?php esc_html_e('When enabled, writes are mirrored to a local `.duckdb` file and queries run there for HNSW acceleration. MotherDuck remains the canonical store. The initial bootstrap runs in the background via Action Scheduler — watch the status panel below for progress.', 'mxchat-plus'); ?>
            </p>

            <?php if (class_exists('MxChat_Plus_DuckDB_Mirror_Bootstrap') && !empty($opts['motherduck_mirror_enabled'])) :
                $status = MxChat_Plus_DuckDB_Mirror_Bootstrap::get_status();
                $state  = MxChat_Plus_DuckDB_Mirror_Bootstrap::get_state();
                $pending  = class_exists('MxChat_Plus_DuckDB_Mirrored_Connection') ? MxChat_Plus_DuckDB_Mirrored_Connection::pending_count()    : 0;
                $quarant  = class_exists('MxChat_Plus_DuckDB_Mirrored_Connection') ? MxChat_Plus_DuckDB_Mirrored_Connection::quarantine_count() : 0;
                $last_dc  = class_exists('MxChat_Plus_DuckDB_Mirror_Drift_Check')  ? MxChat_Plus_DuckDB_Mirror_Drift_Check::get_last_check_timestamp() : 0;

                // Status pill colour — picked to match the existing wp-admin
                // notice palette so the page stays visually consistent.
                $status_colour = [
                    'disabled'      => '#aaa',
                    'bootstrapping' => '#dba617',  // amber
                    'active'        => '#00a32a',  // green
                    'drifted'       => '#dba617',
                    'error'         => '#d63638',  // red
                ][$status] ?? '#aaa';
                ?>
                <div class="mxchat-plus-mirror-status" style="margin-top: 12px; padding: 12px; background: #f6f7f7; border-left: 4px solid <?php echo esc_attr($status_colour); ?>;">
                    <strong><?php esc_html_e('Mirror status:', 'mxchat-plus'); ?></strong>
                    <code style="background: transparent;"><?php echo esc_html($status); ?></code>

                    <?php if ($status === MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_BOOTSTRAPPING && $state['target_count'] !== null) :
                        $pct = $state['target_count'] > 0
                            ? min(100, (int) round(100 * $state['processed_count'] / $state['target_count']))
                            : 0;
                        ?>
                        <span style="margin-left: 12px;">
                            <?php printf(
                                /* translators: 1: processed rows, 2: target rows, 3: percentage */
                                esc_html__('Progress: %1$d / %2$d (%3$d%%)', 'mxchat-plus'),
                                (int) $state['processed_count'],
                                (int) $state['target_count'],
                                $pct
                            ); ?>
                        </span>
                    <?php endif; ?>

                    <?php if ($status === MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_ERROR && !empty($state['last_error'])) : ?>
                        <p style="margin: 6px 0 0; color: #d63638;">
                            <?php esc_html_e('Last error:', 'mxchat-plus'); ?>
                            <code style="background: transparent;"><?php echo esc_html($state['last_error']); ?></code>
                        </p>
                    <?php endif; ?>

                    <?php if ($status === MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_DRIFTED) : ?>
                        <p style="margin: 6px 0 0;">
                            <?php
                            echo wp_kses_post(__(
                                'Drift detected between MotherDuck and local. Run <code>wp mxchat-plus mirror-bootstrap --reset</code> to re-converge, or investigate via <code>wp mxchat-plus mirror-drift-check</code>.',
                                'mxchat-plus'
                            ));
                            ?>
                        </p>
                    <?php endif; ?>

                    <ul style="margin: 6px 0 0 0; padding: 0; list-style: none; font-size: 12px; color: #50575e;">
                        <li>
                            <?php printf(
                                /* translators: %d = number of pending mirror writes */
                                esc_html__('Pending writes queued: %d', 'mxchat-plus'),
                                (int) $pending
                            ); ?>
                        </li>
                        <li>
                            <?php printf(
                                /* translators: %d = number of quarantined entries */
                                esc_html__('Quarantined entries: %d', 'mxchat-plus'),
                                (int) $quarant
                            ); ?>
                        </li>
                        <li>
                            <?php if ($last_dc > 0) :
                                printf(
                                    /* translators: %s = number of seconds since the last drift check */
                                    esc_html__('Last drift check: %s ago', 'mxchat-plus'),
                                    esc_html(human_time_diff($last_dc))
                                );
                            else :
                                esc_html_e('Last drift check: never (the daily cron has not run yet)', 'mxchat-plus');
                            endif; ?>
                        </li>
                    </ul>
                </div>
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="motherduck_mirror_path"><?php esc_html_e('Mirror path', 'mxchat-plus'); ?></label></th>
        <td>
            <input type="text" id="motherduck_mirror_path" class="regular-text code"
                   name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[motherduck_mirror_path]"
                   value="<?php echo esc_attr($opts['motherduck_mirror_path'] ?? ''); ?>"
                   placeholder="<?php echo esc_attr(MxChat_Plus_DuckDB_Options::default_mirror_path()); ?>">
            <p class="description">
                <?php esc_html_e('Path to the local mirror `.duckdb` file. Leave empty for the default under uploads/mxchat-plus-private/. Point at a fast local disk if uploads/ is on a network mount.', 'mxchat-plus'); ?>
            </p>
        </td>
    </tr>
</table>

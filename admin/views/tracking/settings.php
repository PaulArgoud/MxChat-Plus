<?php
/**
 * Tracking tab. Included by MxChat_Plus_Tracking_Admin::render_tab(), inside
 * the <div class="wrap"> and under the <h1> that MxChat_Plus_Admin already
 * printed — hence no wrapper and no page title here.
 *
 * @var array<string,mixed> $opts         plugin options
 * @var list<array{clicked_url:string,scope:string,clicks:int,sessions:int,last_click:string}> $rows
 * @var array{clicks:int,links:int,sessions:int} $totals
 * @var bool   $table_exists              wp_mxchat_url_clicks is present
 * @var string $export_url                admin-ajax.php
 * @var string $export_nonce
 */

if (!defined('ABSPATH')) {
    exit;
}

$key = MXCHAT_PLUS_TRACKING_OPTION_KEY;
// The host writes click_timestamp with current_time('mysql', 1) — GMT — so
// every value out of that table has to be shifted into the site's timezone
// before it is shown.
$datetime_format = (string) get_option('date_format') . ' ' . (string) get_option('time_format');
?>
<h2><?php esc_html_e('Click tracking', 'mxchat-plus'); ?></h2>

<p class="description" style="max-width:720px;">
    <?php esc_html_e(
        'Sends an analytics event when a visitor clicks a link inside a chatbot answer, or a suggested question. Nothing leaves the page until one of the two destinations below is switched on.',
        'mxchat-plus'
    ); ?>
</p>

<form method="post" action="options.php">
    <?php settings_fields(MxChat_Plus_Tracking_Admin::SETTINGS_GROUP); ?>

    <?php // The number field below is what keeps the option array present in
          // $_POST when every checkbox is unticked — options.php writes null
          // over an option of the submitted group that it cannot find there.
          // Any future edit that removes it needs a hidden field in its place. ?>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php esc_html_e('Destinations', 'mxchat-plus'); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr($key); ?>[track_matomo]" value="1"
                        <?php checked(!empty($opts['track_matomo'])); ?>>
                    <?php esc_html_e('Send events to Matomo', 'mxchat-plus'); ?>
                </label>
                <p class="description">
                    <?php esc_html_e(
                        'Events go through the Matomo tracker already loaded on the page, so the site id is the one in your existing Matomo snippet — there is nothing to enter here. On a page with no Matomo snippet, nothing is sent.',
                        'mxchat-plus'
                    ); ?>
                </p>

                <label style="display:inline-block;margin-top:12px;">
                    <input type="checkbox" name="<?php echo esc_attr($key); ?>[track_ga4]" value="1"
                        <?php checked(!empty($opts['track_ga4'])); ?>>
                    <?php esc_html_e('Send events to Google Analytics 4', 'mxchat-plus'); ?>
                </label>
                <p class="description">
                    <?php esc_html_e(
                        'Requires gtag() to be loaded by something else — a GA4 plugin, a tag manager, or the snippet in your theme. This module never injects the GA4 tag itself, and sends nothing when gtag() is absent.',
                        'mxchat-plus'
                    ); ?>
                </p>
            </td>
        </tr>

        <tr>
            <th scope="row"><?php esc_html_e('Session id', 'mxchat-plus'); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr($key); ?>[send_session_id]" value="1"
                        <?php checked(!empty($opts['send_session_id'])); ?>>
                    <?php esc_html_e('Include the MxChat session id in each event', 'mxchat-plus'); ?>
                </label>
                <p class="description" style="max-width:720px;">
                    <?php esc_html_e(
                        'The session id is the join key into MxChat\'s transcripts table: with it, a click can be traced back to the conversation that produced it. It also means the id leaves the site — analytics vendors sit outside MxChat\'s own erasure path, so a deletion handled by MxChat does not reach them. Turning this off is the conservative choice; the events still work without it.',
                        'mxchat-plus'
                    ); ?>
                </p>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="mxchat-plus-matomo-dimension"><?php esc_html_e('Matomo custom dimension', 'mxchat-plus'); ?></label>
            </th>
            <td>
                <input type="number" id="mxchat-plus-matomo-dimension" class="small-text"
                       name="<?php echo esc_attr($key); ?>[matomo_dimension_id]"
                       min="0" max="999" step="1"
                       value="<?php echo esc_attr((string) (int) ($opts['matomo_dimension_id'] ?? 0)); ?>">
                <p class="description" style="max-width:720px;">
                    <?php esc_html_e(
                        'The session id is also written to this Matomo custom dimension — give the id (1 to 999) of the dimension you created in Matomo. Leave it at 0 to send no custom dimension; it has no effect while the session id is not sent.',
                        'mxchat-plus'
                    ); ?>
                </p>
            </td>
        </tr>

        <tr>
            <th scope="row"><?php esc_html_e('Debug', 'mxchat-plus'); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr($key); ?>[debug]" value="1"
                        <?php checked(!empty($opts['debug'])); ?>>
                    <?php esc_html_e('Log every event to the browser console', 'mxchat-plus'); ?>
                </label>
                <p class="description" style="max-width:720px;">
                    <?php esc_html_e(
                        'Each event is printed in the DevTools console, prefixed [MxChat Plus tracking]. It logs for every visitor, not only for administrators, so this is meant to be switched back off once you have checked that the events look right.',
                        'mxchat-plus'
                    ); ?>
                </p>
            </td>
        </tr>
    </table>

    <?php submit_button(); ?>
</form>

<hr>

<h2><?php esc_html_e('Clicked links', 'mxchat-plus'); ?></h2>

<?php if (!$table_exists) : ?>

    <p style="max-width:720px;">
        <?php esc_html_e(
            'MxChat logs link clicks server-side into its own table, which it creates when the plugin is activated. That table does not exist on this site, so no click has ever been recorded and there is nothing to report. It appears the next time MxChat is activated.',
            'mxchat-plus'
        ); ?>
    </p>

<?php else : ?>

    <p class="description" style="max-width:720px;">
        <?php esc_html_e(
            'This report counts what MxChat itself records server-side, which is a narrower population than the events sent above: MxChat only tracks absolute http(s) links inside answers — never relative links, and never suggested questions. The two figures measure different things and will not match, by construction.',
            'mxchat-plus'
        ); ?>
    </p>

    <?php if (empty($rows)) : ?>

        <p><?php esc_html_e('No click has been recorded yet.', 'mxchat-plus'); ?></p>

    <?php else : ?>

        <p>
            <?php
            printf(
                /* translators: 1: number of clicks, 2: number of distinct links, 3: number of distinct sessions */
                esc_html__('%1$s clicks recorded, on %2$s links, across %3$s sessions.', 'mxchat-plus'),
                esc_html(number_format_i18n((int) ($totals['clicks'] ?? 0))),
                esc_html(number_format_i18n((int) ($totals['links'] ?? 0))),
                esc_html(number_format_i18n((int) ($totals['sessions'] ?? 0)))
            );

            // The totals cover the whole table; the rows below are capped.
            if (count($rows) >= MxChat_Plus_Tracking_Admin::REPORT_LIMIT) {
                echo ' ';
                printf(
                    /* translators: %s: number of links listed in the table */
                    esc_html__('Only the %s most clicked links are listed here.', 'mxchat-plus'),
                    esc_html(number_format_i18n(count($rows)))
                );
            }
            ?>
        </p>

        <table class="widefat striped" style="max-width:960px;">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('URL', 'mxchat-plus'); ?></th>
                    <th scope="col"><?php esc_html_e('Scope', 'mxchat-plus'); ?></th>
                    <th scope="col" style="text-align:right;"><?php esc_html_e('Clicks', 'mxchat-plus'); ?></th>
                    <th scope="col" style="text-align:right;"><?php esc_html_e('Sessions', 'mxchat-plus'); ?></th>
                    <th scope="col"><?php esc_html_e('Last click', 'mxchat-plus'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row) : ?>
                <?php
                $url   = (string) ($row['clicked_url'] ?? '');
                $scope = (string) ($row['scope'] ?? '');
                $last  = (string) ($row['last_click'] ?? '');
                $stamp = $last !== '' ? strtotime($last . ' UTC') : false;
                $shown = $stamp !== false ? wp_date($datetime_format, $stamp) : false;
                ?>
                <tr>
                    <td style="word-break:break-all;">
                        <?php
                        // esc_url() returns an empty string for a scheme it
                        // refuses. The value is still worth showing in that
                        // case — just not as something clickable.
                        if (esc_url($url) !== '') :
                            ?>
                            <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($url); ?></a>
                        <?php else : ?>
                            <?php echo esc_html($url); ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        if ($scope === 'internal') {
                            esc_html_e('Internal', 'mxchat-plus');
                        } elseif ($scope === 'external') {
                            esc_html_e('External', 'mxchat-plus');
                        } else {
                            echo esc_html($scope);
                        }
                        ?>
                    </td>
                    <td style="text-align:right;"><?php echo esc_html(number_format_i18n((int) ($row['clicks'] ?? 0))); ?></td>
                    <td style="text-align:right;"><?php echo esc_html(number_format_i18n((int) ($row['sessions'] ?? 0))); ?></td>
                    <td><?php echo esc_html(is_string($shown) ? $shown : $last); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <form method="post" action="<?php echo esc_url($export_url); ?>" style="margin-top:12px;">
            <input type="hidden" name="action" value="<?php echo esc_attr(MxChat_Plus_Tracking_Admin::EXPORT_ACTION); ?>">
            <input type="hidden" name="security" value="<?php echo esc_attr($export_nonce); ?>">
            <?php // Hand-written rather than submit_button(): the unwrapped,
                  // secondary variant needs four arguments, and the PHPStan
                  // stub for submit_button() only declares one. ?>
            <button type="submit" class="button"><?php esc_html_e('Export CSV', 'mxchat-plus'); ?></button>
            <p class="description">
                <?php esc_html_e('Downloads the clicks MxChat has logged as a CSV file.', 'mxchat-plus'); ?>
            </p>
        </form>

    <?php endif; ?>

<?php endif; ?>

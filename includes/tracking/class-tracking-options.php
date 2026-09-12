<?php
/**
 * Options storage for the Tracking module.
 *
 * One WP option (mxchat_plus_tracking_options) holding the five settings the
 * front-end click tracker needs. Every read materialises all five keys: the
 * localized script object is built from this array on every front-end request,
 * and a key missing there becomes an `undefined` in the browser — a failure
 * mode with no PHP-side trace at all.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_Tracking_Options {

    /**
     * The option written by the standalone "MxChat Link Tracking" plugin this
     * module replaces.
     *
     * It carried the host's `mxchat_` prefix, which belongs to MxChat. That
     * prefix is the only thing separating the host's symbols from ours, so
     * folding the plugin in required renaming the option to
     * `mxchat_plus_tracking_options`; migrate_legacy() carries the settings
     * across once so the rename is invisible to the site owner.
     */
    const LEGACY_OPTION_KEY = 'mxchat_tracking_options';

    /** Matomo numbers custom dimensions 1..999. We use 0 for "not configured". */
    const MATOMO_DIMENSION_MIN = 1;
    const MATOMO_DIMENSION_MAX = 999;

    /**
     * @return array{track_matomo:bool,track_ga4:bool,send_session_id:bool,matomo_dimension_id:int,debug:bool}
     */
    public static function defaults(): array {
        return [
            'track_matomo'        => true,
            // Off by default: unlike Matomo, gtag is absent from most of the
            // installs this module targets, and firing at a missing global
            // would only produce console noise.
            'track_ga4'           => false,
            // The host's session id is what joins a click back to a transcript,
            // so it is on unless the site owner would rather not send it.
            'send_session_id'     => true,
            'matomo_dimension_id' => 0,
            'debug'               => false,
        ];
    }

    /**
     * The stored settings, every key present and coerced to its declared type.
     *
     * Nothing read back is trusted: the row can predate a key (migrate_legacy()
     * only knows the three settings the standalone plugin had), and a row
     * written by hand or by WP-CLI can hold strings where we expect booleans.
     * Keys we do not declare are dropped — the array is rebuilt from our shape,
     * not filtered.
     *
     * @return array{track_matomo:bool,track_ga4:bool,send_session_id:bool,matomo_dimension_id:int,debug:bool}
     */
    public static function get(): array {
        $stored = get_option(MXCHAT_PLUS_TRACKING_OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $merged = array_merge(self::defaults(), $stored);

        return [
            'track_matomo'        => (bool) $merged['track_matomo'],
            'track_ga4'           => (bool) $merged['track_ga4'],
            'send_session_id'     => (bool) $merged['send_session_id'],
            'matomo_dimension_id' => self::clamp_dimension_id($merged['matomo_dimension_id']),
            'debug'               => (bool) $merged['debug'],
        ];
    }

    /**
     * register_setting() callback for the 'mxchat_plus_tracking' group.
     *
     * Always returns all five keys. An unchecked checkbox is simply absent from
     * $_POST, so returning only what was posted would leave the row without
     * that key — and get() would then hand back the *default*, which is `true`
     * for track_matomo and send_session_id. Unticking either would look like it
     * had done nothing.
     *
     * @return array{track_matomo:bool,track_ga4:bool,send_session_id:bool,matomo_dimension_id:int,debug:bool}
     */
    public static function sanitize(mixed $input): array {
        if (!is_array($input)) {
            // A scalar under our option name means a malformed submission, not
            // a set of settings: fall through to defaults-with-boxes-unticked.
            $input = [];
        }

        $raw_dimension = $input['matomo_dimension_id'] ?? 0;
        $dimension     = self::clamp_dimension_id($raw_dimension);

        // Clamping an out-of-range id to 0 turns the custom dimension off. Say
        // so, rather than let a typo silently stop the dimension being sent.
        // add_settings_error() lives in wp-admin/includes/template.php, and
        // this callback runs off the `sanitize_option_*` filter — which fires
        // on *every* update_option(), WP-CLI and front-end writes included.
        if ($dimension === 0 && !empty($raw_dimension) && function_exists('add_settings_error')) {
            add_settings_error(
                MXCHAT_PLUS_TRACKING_OPTION_KEY,
                'matomo_dimension_out_of_range',
                sprintf(
                    /* translators: 1: lowest valid dimension id, 2: highest valid dimension id */
                    __('The Matomo custom dimension ID must be a number between %1$d and %2$d. The value entered was ignored, and no custom dimension will be sent.', 'mxchat-plus'),
                    self::MATOMO_DIMENSION_MIN,
                    self::MATOMO_DIMENSION_MAX
                ),
                'warning'
            );
        }

        return [
            'track_matomo'        => !empty($input['track_matomo']),
            'track_ga4'           => !empty($input['track_ga4']),
            'send_session_id'     => !empty($input['send_session_id']),
            'matomo_dimension_id' => $dimension,
            'debug'               => !empty($input['debug']),
        ];
    }

    /**
     * One-shot migration from the standalone plugin's option.
     *
     * Runs only when our option does not exist yet: an install that has already
     * saved the settings tab keeps what it saved, whatever is left lying around
     * under the old name. Deleting the legacy row is what makes this one-shot —
     * its absence *is* the "already migrated" marker, so there is no separate
     * flag to keep in sync.
     *
     * Only the three settings the standalone plugin actually had are carried
     * over, and only when present: the rest take our defaults, so
     * send_session_id arrives on rather than being read as an unticked box.
     *
     * @return bool Whether settings were migrated.
     */
    public static function migrate_legacy(): bool {
        // Null default, not the usual []: it is the one value that tells an
        // absent option apart from one deliberately saved as an empty array.
        if (get_option(MXCHAT_PLUS_TRACKING_OPTION_KEY, null) !== null) {
            return false;
        }

        $legacy = get_option(self::LEGACY_OPTION_KEY, null);
        if (!is_array($legacy)) {
            // Absent, or stored as something we cannot read. Leave it alone —
            // deleting a row we did not understand would destroy the only copy
            // of those settings.
            return false;
        }

        $input = self::defaults();
        foreach (['track_matomo', 'track_ga4', 'debug'] as $key) {
            if (array_key_exists($key, $legacy)) {
                $input[$key] = $legacy[$key];
            }
        }

        // No autoload argument, so this row is created exactly as the settings
        // form would create it — the module reads it on front-end requests.
        update_option(MXCHAT_PLUS_TRACKING_OPTION_KEY, self::sanitize($input));
        delete_option(self::LEGACY_OPTION_KEY);

        return true;
    }

    /**
     * A Matomo custom dimension id, or 0 for "not configured". Anything that
     * lands outside 1..999 — a typo, a non-numeric string, an array — collapses
     * to 0 rather than being sent to Matomo, which would reject it.
     *
     * absint() takes the absolute value, so a stray minus sign is corrected
     * ("-4" is read as dimension 4) instead of disabling the dimension.
     */
    private static function clamp_dimension_id(mixed $value): int {
        $id = is_scalar($value) ? absint($value) : 0;
        return ($id >= self::MATOMO_DIMENSION_MIN && $id <= self::MATOMO_DIMENSION_MAX) ? $id : 0;
    }
}

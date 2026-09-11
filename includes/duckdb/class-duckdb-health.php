<?php
/**
 * Read-only /health endpoint for external uptime monitors.
 *
 *   GET /wp-json/mxchat-plus/v1/health
 *
 * Returns 200 with backend identity + vector count + last sync age + metrics
 * snapshot when everything is healthy, or 503 when ping() fails. No auth by
 * default — the response leaks only aggregate counts, not vector content.
 * Sites that want auth can replace the permission callback via filter.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Health {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function register_routes(): void {
        add_action('rest_api_init', function () {
            register_rest_route('mxchat-plus/v1', '/health', [
                'methods'             => 'GET',
                'callback'            => [$this, 'handle'],
                'permission_callback' => function (WP_REST_Request $req) {
                    $allow = apply_filters('mxchat_plus_duckdb_health_public', true, $req);
                    if ($allow) return true;
                    return current_user_can('manage_options');
                },
            ]);
        });
    }

    public function handle(WP_REST_Request $req): WP_REST_Response {
        $opts = MxChat_Plus_DuckDB_Options::get();
        $payload = [
            'plugin_version' => MXCHAT_PLUS_VERSION,
            'enabled'        => (bool) $opts['enabled'],
            'mode'           => (string) $opts['mode'],
            'ext_loaded'     => extension_loaded('duckdb'),
            'metrics'        => MxChat_Plus_DuckDB_Metrics::snapshot(),
            'last_sync_at'   => (int) $opts['last_sync_at'],
            'last_sync_age_s' => $opts['last_sync_at'] ? (time() - (int) $opts['last_sync_at']) : null,
            'last_error'     => (string) $opts['last_error'],
        ];

        // Mirror telemetry. Always populated when the classes exist
        // (test installs don't auto-load the mirror) so external
        // monitors can chart pending/quarantine over time even on
        // installs where the mirror is currently disabled — values
        // are zero on disabled installs.
        if (class_exists('MxChat_Plus_DuckDB_Mirror_Bootstrap') && class_exists('MxChat_Plus_DuckDB_Mirrored_Connection')) {
            $pending_state = MxChat_Plus_DuckDB_Mirrored_Connection::pending_state();
            $last_drift = class_exists('MxChat_Plus_DuckDB_Mirror_Drift_Check')
                ? MxChat_Plus_DuckDB_Mirror_Drift_Check::get_last_check_timestamp()
                : 0;
            $payload['mirror'] = [
                'enabled'              => !empty($opts['motherduck_mirror_enabled']),
                'status'               => MxChat_Plus_DuckDB_Mirror_Bootstrap::get_status(),
                'pending_count'        => count($pending_state['pending']),
                'quarantine_count'     => count($pending_state['quarantine']),
                'drained_total'        => (int) $pending_state['drained_total'],
                'quarantine_total'     => (int) $pending_state['quarantine_total'],
                'last_drift_check_at'  => $last_drift,
                'last_drift_check_age_s' => $last_drift > 0 ? (time() - $last_drift) : null,
            ];
        }

        if (!$opts['enabled']) {
            $payload['ok'] = true;
            $payload['status'] = 'disabled';
            return new WP_REST_Response($payload, 200);
        }

        try {
            $conn = MxChat_Plus_DuckDB_Connection_Factory::current();
            $payload['backend'] = $conn->identifier();
            $payload['ping'] = $conn->ping();

            $store = new MxChat_Plus_DuckDB_Vector_Store($conn);
            $payload['count'] = $store->count();
            $payload['ok'] = (bool) $payload['ping'];
            $payload['status'] = $payload['ok'] ? 'healthy' : 'ping_failed';
            return new WP_REST_Response($payload, $payload['ok'] ? 200 : 503);
        } catch (\Throwable $e) {
            $payload['ok'] = false;
            $payload['status'] = 'error';
            $payload['error'] = $e->getMessage();
            return new WP_REST_Response($payload, 503);
        }
    }
}

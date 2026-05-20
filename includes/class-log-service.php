<?php
namespace WooElementorAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Log_Service — Structured activity logging for debugging AI flows.
 *
 * Table: {prefix}woo_ai_logs
 * Columns: id, timestamp, channel, level, message, context (JSON), post_id
 *
 * Channels: ai_request, ai_response, json_parse, image_resolve, api_endpoint
 * Levels: info, warning, error, success
 */
class Log_Service {

    private static ?self $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->maybe_create_table();
    }

    /**
     * Create the logs table if it doesn't exist.
     */
    private function maybe_create_table(): void {
        global $wpdb;
        $table      = $this->table_name();
        $charset    = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Table creation, runs once
        $exists = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedPreparedExpression -- Table name is safe
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
        );

        if ( $exists === $table ) {
            return;
        }

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            channel varchar(50) NOT NULL DEFAULT 'general',
            level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context longtext,
            post_id bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY channel (channel),
            KEY level (level),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    private function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'woo_ai_logs';
    }

    /**
     * Insert a log entry.
     *
     * @param string $channel  Log channel: ai_request, ai_response, json_parse, image_resolve, api_endpoint
     * @param string $level    Log level: info, success, warning, error
     * @param string $message  Human-readable log message
     * @param mixed  $context  Optional context data (will be JSON encoded). Full payload OK.
     * @param int    $post_id  Optional related post ID
     */
    public function log( string $channel, string $level, string $message, $context = null, int $post_id = 0 ): void {
        global $wpdb;

        $context_json = null;
        if ( null !== $context ) {
            $context_json = wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            // Cap context at 4MB to prevent DB bloat from huge AI responses
            if ( strlen( $context_json ) > 4194304 ) {
                $context_json = wp_json_encode( [
                    'notice' => 'Payload truncated (exceeded 4MB)',
                    'size'   => strlen( $context_json ),
                    'preview' => substr( $context_json, 0, 2000 ),
                ], JSON_UNESCAPED_UNICODE );
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Log insert
        $wpdb->insert( $this->table_name(), [
            'channel'   => $channel,
            'level'     => $level,
            'message'   => $message,
            'context'   => $context_json,
            'post_id'   => $post_id > 0 ? $post_id : null,
        ], [ '%s', '%s', '%s', '%s', '%d' ] );

        $this->maybe_auto_purge();
    }

    /**
     * Query logs with filters.
     *
     * @param array $args {
     *   @type string $channel   Filter by channel
     *   @type string $level     Filter by level
     *   @type int    $post_id   Filter by post ID
     *   @type string $search    Search in message
     *   @type int    $per_page  Results per page (default 50)
     *   @type int    $page      Page number (1-based)
     *   @type string $orderby   Order by column (default 'created_at')
     *   @type string $order     ASC or DESC (default 'DESC')
     * }
     * @return array ['logs' => array, 'total' => int, 'pages' => int]
     */
    public function query( array $args = [] ): array {
        global $wpdb;
        $table = $this->table_name();

        $channel  = sanitize_text_field( $args['channel'] ?? '' );
        $level    = sanitize_text_field( $args['level'] ?? '' );
        $post_id  = absint( $args['post_id'] ?? 0 );
        $search   = sanitize_text_field( $args['search'] ?? '' );
        $per_page = max( 1, min( 200, absint( $args['per_page'] ?? 50 ) ) );
        $page     = max( 1, absint( $args['page'] ?? 1 ) );
        $orderby  = in_array( $args['orderby'] ?? '', [ 'id', 'created_at', 'channel', 'level' ], true )
            ? $args['orderby'] : 'created_at';
        $order    = 'ASC' === ( $args['order'] ?? '' ) ? 'ASC' : 'DESC';

        $where = [ '1=1' ];
        $params = [];

        if ( ! empty( $channel ) ) {
            $where[] = 'channel = %s';
            $params[] = $channel;
        }
        if ( ! empty( $level ) ) {
            $where[] = 'level = %s';
            $params[] = $level;
        }
        if ( $post_id > 0 ) {
            $where[] = 'post_id = %d';
            $params[] = $post_id;
        }
        if ( ! empty( $search ) ) {
            $where[] = 'message LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $search ) . '%';
        }

        $where_sql = implode( ' AND ', $where );

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) );

        $offset = ( $page - 1 ) * $per_page;
        $query_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
        $logs = $wpdb->get_results( $wpdb->prepare( $query_sql, ...$params ), ARRAY_A );

        return [
            'logs'  => $logs ?: [],
            'total' => $total,
            'pages' => (int) ceil( $total / $per_page ),
        ];
    }

    /**
     * Clear all logs.
     */
    public function clear_all(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Truncate logs
        $wpdb->query( "TRUNCATE TABLE " . $this->table_name() );
    }

    /**
     * Auto-purge old logs to keep within configured limit.
     */
    private function maybe_auto_purge(): void {
        // Run purge ~1% of the time to avoid overhead on every insert
        if ( mt_rand( 1, 100 ) > 1 ) {
            return;
        }

        $settings_obj = new Settings();
        $limit = absint( $settings_obj->get( 'log_purge_limit', 1000 ) );
        if ( $limit < 100 ) {
            $limit = 100;
        }

        global $wpdb;
        $table = $this->table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Purge old logs
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        if ( $count > $limit ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query(
                "DELETE FROM {$table} ORDER BY id ASC LIMIT " . ( $count - $limit )
            );
        }
    }
}

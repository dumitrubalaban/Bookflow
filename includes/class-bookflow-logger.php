<?php
/**
 * Audit Logger
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Logger {

    public static function init() {
        // Auto-log status changes
        add_action('bookflow_booking_status_changed', [__CLASS__, 'log_status_change'], 10, 3);
    }

    /**
     * Log an action
     */
    public static function log($action, $booking_id = null, $data = []) {
        global $wpdb;

        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
            $wpdb->prefix . 'bookflow_log',
            [
                'booking_id' => $booking_id ? absint($booking_id) : null,
                'user_id'    => get_current_user_id() ?: null,
                'action'     => sanitize_text_field($action),
                'old_value'  => isset($data['old']) ? wp_json_encode($data['old']) : null,
                'new_value'  => isset($data['new']) ? wp_json_encode($data['new']) : null,
                'note'       => isset($data['note']) ? sanitize_textarea_field($data['note']) : null,
                'ip_address' => self::get_ip(),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        return $wpdb->insert_id;
    }

    /**
     * Log status change
     */
    public static function log_status_change($booking_id, $old_status, $new_status) {
        self::log('status_changed', $booking_id, [
            'old'  => $old_status,
            'new'  => $new_status,
            'note' => sprintf('Status changed from %s to %s', $old_status, $new_status),
        ]);
    }

    /**
     * Get logs for a booking
     */
    public static function get_booking_logs($booking_id, $limit = 50) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
            "SELECT l.*, u.display_name as user_name
             FROM {$wpdb->prefix}bookflow_log l
             LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID
             WHERE l.booking_id = %d
             ORDER BY l.created_at DESC
             LIMIT %d",
            $booking_id,
            $limit
        ));
    }

    /**
     * Get client IP
     */
    private static function get_ip() {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', sanitize_text_field(wp_unslash($_SERVER[$header])));
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
}

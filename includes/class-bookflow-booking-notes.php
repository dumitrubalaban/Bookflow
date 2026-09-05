<?php
/**
 * Booking notes — freeform or structured notes attached to a booking (e.g.
 * a coach's session notes, a skills/progress checklist). Bookflow only
 * stores and retrieves; `note_type` and the shape of `structured_data` are
 * defined entirely by the integrator.
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Booking_Notes {

    /**
     * @param array $args {
     *     @type string $note_type            default 'note'
     *     @type string $note_text
     *     @type array  $structured_data      arbitrary JSON-encodable data
     *     @type bool   $visible_to_customer  default false
     *     @type int    $author_id            default current user
     * }
     * @return int|WP_Error note id
     */
    public static function add($booking_id, $args = []) {
        global $wpdb;

        $defaults = [
            'note_type'           => 'note',
            'note_text'           => '',
            'structured_data'     => null,
            'visible_to_customer' => false,
            'author_id'           => get_current_user_id() ?: null,
        ];
        $args = wp_parse_args($args, $defaults);

        $inserted = $wpdb->insert($wpdb->prefix . 'bookflow_booking_notes', [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists
            'booking_id'           => absint($booking_id),
            'author_id'            => $args['author_id'] ? absint($args['author_id']) : null,
            'note_type'            => sanitize_key($args['note_type']),
            'note_text'            => sanitize_textarea_field($args['note_text']),
            'structured_data'      => $args['structured_data'] !== null ? wp_json_encode($args['structured_data']) : null,
            'visible_to_customer'  => $args['visible_to_customer'] ? 1 : 0,
        ]);

        if (!$inserted) {
            return new WP_Error('db_error', 'Could not save note.');
        }

        $note_id = (int) $wpdb->insert_id;
        do_action('bookflow_booking_note_added', $note_id, $booking_id, $args);

        return $note_id;
    }

    public static function get_for_booking($booking_id, $note_type = null, $customer_visible_only = false) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_booking_notes';

        $where = ['booking_id = %d'];
        $params = [absint($booking_id)];

        if ($note_type) {
            $where[] = 'note_type = %s';
            $params[] = sanitize_key($note_type);
        }
        if ($customer_visible_only) {
            $where[] = 'visible_to_customer = 1';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom table, no core API exists; $where entries are fixed literals, never request-derived
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC",
            $params
        ));

        foreach ($rows as $row) {
            $row->structured_data = $row->structured_data ? json_decode($row->structured_data, true) : null;
        }

        return $rows;
    }

    /**
     * All structured-note values for a given note_type across every booking
     * of a customer — useful for e.g. building a cumulative skills checklist
     * from many individual session notes.
     */
    public static function get_structured_history_for_customer($customer_id, $note_type) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT n.* FROM {$wpdb->prefix}bookflow_booking_notes n
             INNER JOIN {$wpdb->prefix}bookflow_bookings b ON b.id = n.booking_id
             WHERE b.customer_id = %d AND n.note_type = %s
             ORDER BY n.created_at ASC",
            absint($customer_id),
            sanitize_key($note_type)
        ));

        foreach ($rows as $row) {
            $row->structured_data = $row->structured_data ? json_decode($row->structured_data, true) : null;
        }

        return $rows;
    }
}

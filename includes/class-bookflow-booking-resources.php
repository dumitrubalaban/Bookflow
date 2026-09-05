<?php
/**
 * Booking resources — generalizes a booking's single `resource_id` into a
 * many-to-many, so one booking can require several resources to
 * simultaneously be free (e.g. a room AND a piece of equipment). Locking and
 * capacity-checking for secondary resources happens inside
 * Bookflow_Booking::create() (same transaction as the primary resource
 * check); this class is the read/write API for the resulting rows.
 *
 * The booking's legacy `resource_id` column is always also written here
 * with role 'primary', so this table is a strict superset of what the
 * single-resource column already expressed.
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Booking_Resources {

    /**
     * Attach a set of resources to a booking. $resources is a list of
     * ['resource_id' => int, 'role' => string] — 'role' defaults to
     * 'primary' for the first entry and 'secondary' for the rest if omitted.
     */
    public static function attach($booking_id, array $resources) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_booking_resources';

        foreach ($resources as $i => $resource) {
            $resource_id = is_array($resource) ? ($resource['resource_id'] ?? 0) : $resource;
            if (!$resource_id) {
                continue;
            }
            $role = is_array($resource) && !empty($resource['role'])
                ? sanitize_key($resource['role'])
                : ($i === 0 ? 'primary' : 'secondary');

            $wpdb->insert($table, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists
                'booking_id'  => absint($booking_id),
                'resource_id' => absint($resource_id),
                'role'        => $role,
            ]);
        }
    }

    /**
     * All resources attached to a booking, keyed by role where roles are
     * unique, or as a flat list of rows otherwise.
     */
    public static function get_for_booking($booking_id) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists
        return $wpdb->get_results($wpdb->prepare(
            "SELECT br.*, r.title, r.capacity
             FROM {$wpdb->prefix}bookflow_booking_resources br
             INNER JOIN {$wpdb->prefix}bookflow_resources r ON r.id = br.resource_id
             WHERE br.booking_id = %d
             ORDER BY FIELD(br.role, 'primary') DESC, br.id ASC",
            absint($booking_id)
        ));
    }

    public static function get_resource_id_for_role($booking_id, $role) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists
        return $wpdb->get_var($wpdb->prepare(
            "SELECT resource_id FROM {$wpdb->prefix}bookflow_booking_resources WHERE booking_id = %d AND role = %s",
            absint($booking_id),
            sanitize_key($role)
        ));
    }

    /**
     * Whether a resource is booked (any role) on a given date/time, across
     * bookings that haven't been cancelled/refunded. Used to lock/check
     * secondary resources during Bookflow_Booking::create() the same way
     * the primary resource_id is already checked.
     */
    public static function count_booked($resource_id, $booking_date, $start_time, $for_update = false) {
        global $wpdb;
        $lock = $for_update ? ' FOR UPDATE' : '';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom table, no core API exists; $lock is a fixed literal, never request-derived
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bookflow_booking_resources br
             INNER JOIN {$wpdb->prefix}bookflow_bookings b ON b.id = br.booking_id
             WHERE br.resource_id = %d AND b.booking_date = %s AND b.start_time = %s
             AND b.status NOT IN ('cancelled', 'refunded')" . $lock,
            absint($resource_id),
            $booking_date,
            $start_time
        ));
        // phpcs:enable
        return (int) $count;
    }
}

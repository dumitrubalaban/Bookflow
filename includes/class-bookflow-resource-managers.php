<?php
/**
 * Resource managers — which WP users may manage which resources, for
 * scoped (non-manage_woocommerce) REST access to booking-management
 * endpoints. This is the plugin's answer to "an instructor should only see
 * their own bookings, not the whole business's" without hardcoding any
 * notion of "instructor" into Bookflow itself: a theme grants any role it
 * wants (a custom "instructor" role, a contractor role, whatever) the
 * `bookflow_manage_own_bookings` WP capability, then assigns that user to
 * the resource(s) they're allowed to see here.
 *
 * A user with `manage_woocommerce` always has full, unscoped access — this
 * class only matters for users who have `bookflow_manage_own_bookings`
 * instead of the broader capability.
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Resource_Managers {

    public static function assign($user_id, $resource_id) {
        global $wpdb;
        // INSERT IGNORE semantics via the unique key — re-assigning is a no-op, not an error.
        return $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists
            "INSERT IGNORE INTO {$wpdb->prefix}bookflow_resource_managers (user_id, resource_id) VALUES (%d, %d)",
            absint($user_id),
            absint($resource_id)
        ));
    }

    public static function unassign($user_id, $resource_id) {
        global $wpdb;
        return $wpdb->delete($wpdb->prefix . 'bookflow_resource_managers', [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists
            'user_id'     => absint($user_id),
            'resource_id' => absint($resource_id),
        ]);
    }

    public static function get_resource_ids_for_user($user_id) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT resource_id FROM {$wpdb->prefix}bookflow_resource_managers WHERE user_id = %d",
            absint($user_id)
        ));
        return array_map('absint', $rows);
    }

    public static function user_manages_resource($user_id, $resource_id) {
        if (!$resource_id) {
            return false; // a booking with no resource can't be attributed to any scoped manager
        }
        return in_array(absint($resource_id), self::get_resource_ids_for_user($user_id), true);
    }
}

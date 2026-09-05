<?php
/**
 * Customer flags — a generic per-customer key/value eligibility flag store.
 *
 * Bookflow core treats flag_key/flag_value as opaque strings; it never
 * assigns them meaning. A product opts into gating on a flag by setting its
 * `_bookflow_requires_flag` meta to the flag_key that must be present (and,
 * optionally, `_bookflow_requires_flag_value` for a specific value — default
 * is "any truthy value"). The `bookflow_is_product_bookable_for_customer`
 * filter is the extension point a theme can hook into for logic this simple
 * key/value model doesn't cover.
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Customer_Flags {

    public function __construct() {
        add_filter('bookflow_is_product_bookable_for_customer', [$this, 'check_required_flag'], 10, 3);
    }

    public function check_required_flag($bookable, $product_id, $customer_id) {
        if (!$bookable) {
            return $bookable;
        }

        $required_key = get_post_meta($product_id, '_bookflow_requires_flag', true);
        if (empty($required_key)) {
            return $bookable;
        }

        if (!$customer_id) {
            return false;
        }

        $value = self::get($customer_id, $required_key);
        if ($value === null || $value === '' || $value === '0') {
            return false;
        }

        $required_value = get_post_meta($product_id, '_bookflow_requires_flag_value', true);
        if ($required_value !== '' && (string) $value !== (string) $required_value) {
            return false;
        }

        return true;
    }

    /**
     * Whether a customer is currently allowed to book a given product, per
     * every flag-gate registered on `bookflow_is_product_bookable_for_customer`.
     */
    public static function is_product_bookable_for_customer($product_id, $customer_id) {
        return (bool) apply_filters('bookflow_is_product_bookable_for_customer', true, $product_id, $customer_id);
    }

    public static function set($customer_id, $flag_key, $flag_value = '1', $set_by = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_customer_flags';

        $existing = self::get_row($customer_id, $flag_key);
        $data = [
            'flag_value' => sanitize_text_field($flag_value),
            'set_by'     => $set_by ? absint($set_by) : (get_current_user_id() ?: null),
        ];

        if ($existing) {
            $result = $wpdb->update($table, $data, ['id' => (int) $existing->id]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists
            return $result !== false;
        }

        $data['customer_id'] = absint($customer_id);
        $data['flag_key'] = sanitize_key($flag_key);
        $result = $wpdb->insert($table, $data); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists

        if ($result) {
            do_action('bookflow_customer_flag_set', $customer_id, $flag_key, $flag_value);
        }

        return (bool) $result;
    }

    public static function get($customer_id, $flag_key) {
        $row = self::get_row($customer_id, $flag_key);
        return $row ? $row->flag_value : null;
    }

    public static function clear($customer_id, $flag_key) {
        global $wpdb;
        return $wpdb->delete($wpdb->prefix . 'bookflow_customer_flags', [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists
            'customer_id' => absint($customer_id),
            'flag_key'    => sanitize_key($flag_key),
        ]);
    }

    private static function get_row($customer_id, $flag_key) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bookflow_customer_flags WHERE customer_id = %d AND flag_key = %s",
            absint($customer_id),
            sanitize_key($flag_key)
        ));
    }
}

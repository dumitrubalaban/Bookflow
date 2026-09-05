<?php
/**
 * Customer-resource pins — a persistent binding of a customer to a specific
 * resource (e.g. "this customer's assigned provider"), scoped per product or
 * globally. When a pin exists for the current customer + product, the
 * customer-facing resource pickers (Bookflow_Resources::get_available_for_slot
 * / get_available_for_date, via the bookflow_bookable_resources_for_product
 * filter) are narrowed to only the pinned resource(s) — admin-side resource
 * configuration screens are unaffected, since they don't run that filter.
 *
 * `role` is an opaque label the integrator defines (e.g. "instructor",
 * "stylist") allowing more than one independent pin per customer/product.
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Resource_Pins {

    public function __construct() {
        add_filter('bookflow_bookable_resources_for_product', [$this, 'filter_resources'], 10, 2);
    }

    /**
     * Narrow the resource list to the current customer's pin(s) for this
     * product, if any exist. No pin => no filtering (falls back to the
     * product's full resource pool).
     */
    public function filter_resources($resources, $product_id) {
        $customer_id = get_current_user_id();
        if (!$customer_id) {
            return $resources;
        }

        $pinned_ids = self::get_pinned_resource_ids($customer_id, $product_id);
        if (empty($pinned_ids)) {
            return $resources;
        }

        return array_values(array_filter($resources, function ($resource) use ($pinned_ids) {
            return in_array((int) $resource->id, $pinned_ids, true);
        }));
    }

    /**
     * Pin a customer to a resource for a product (or globally, if
     * $product_id is null). Re-pinning the same customer/product/role
     * replaces the previous resource.
     *
     * @return int|WP_Error pin id
     */
    public static function pin($customer_id, $resource_id, $product_id = null, $role = 'primary') {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_customer_resource_pins';

        $existing = self::get_pin($customer_id, $product_id, $role);

        if ($existing) {
            $updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists
                $table,
                ['resource_id' => absint($resource_id)],
                ['id' => (int) $existing->id]
            );
            return $updated !== false ? (int) $existing->id : new WP_Error('db_error', 'Could not update pin.');
        }

        $inserted = $wpdb->insert($table, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists
            'customer_id' => absint($customer_id),
            'product_id'  => $product_id ? absint($product_id) : null,
            'resource_id' => absint($resource_id),
            'role'        => sanitize_key($role),
        ]);

        if (!$inserted) {
            return new WP_Error('db_error', 'Could not create pin.');
        }

        return (int) $wpdb->insert_id;
    }

    public static function unpin($customer_id, $product_id = null, $role = 'primary') {
        global $wpdb;
        $where = ['customer_id' => absint($customer_id), 'role' => sanitize_key($role)];
        if ($product_id) {
            $where['product_id'] = absint($product_id);
        }
        return $wpdb->delete($wpdb->prefix . 'bookflow_customer_resource_pins', $where); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists
    }

    /**
     * A single pin row (product-scoped pin takes priority over a global one
     * for the same customer/role).
     */
    public static function get_pin($customer_id, $product_id = null, $role = 'primary') {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_customer_resource_pins';

        if ($product_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE customer_id = %d AND product_id = %d AND role = %s",
                absint($customer_id),
                absint($product_id),
                sanitize_key($role)
            ));
            if ($row) {
                return $row;
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE customer_id = %d AND product_id IS NULL AND role = %s",
            absint($customer_id),
            sanitize_key($role)
        ));
    }

    /**
     * All resource ids pinned for this customer that apply to this product
     * (product-specific pins + global pins), across every role.
     */
    public static function get_pinned_resource_ids($customer_id, $product_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_customer_resource_pins';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT resource_id FROM $table WHERE customer_id = %d AND (product_id IS NULL OR product_id = %d)",
            absint($customer_id),
            absint($product_id)
        ));

        return array_map(function ($row) {
            return (int) $row->resource_id;
        }, $rows);
    }
}

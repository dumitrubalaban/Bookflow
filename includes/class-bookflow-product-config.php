<?php
/**
 * Product configuration accessor.
 *
 * Every `_bookflow_*` product-level setting introduced by the generic
 * mechanisms (credits, eligibility flags, manual approval, anti-hoarding
 * limits) is read through this class instead of scattered `get_post_meta()`
 * calls. Bookflow stores this configuration as WooCommerce product post-meta
 * today, for zero-friction admin UI integration (it's just custom fields on
 * the product edit screen) — but every *caller* only ever asks this class
 * "what's the credit type for this product?", never WordPress directly.
 *
 * That indirection is what keeps a future migration contained: if this
 * config ever needs to move off post-meta (e.g. its own table, for cleaner
 * HPOS/multi-store support, or because a non-WooCommerce order system
 * replaces WC as the payment layer), only the method bodies below change —
 * every call site in class-bookflow-booking.php, class-bookflow-credits.php,
 * etc. stays untouched.
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Product_Config {

    /**
     * The credit_type this product auto-consumes on booking confirmation
     * (and auto-refunds on cancellation), or '' if the product doesn't use
     * credits at all. See Bookflow_Credits.
     */
    public static function credit_type($product_id) {
        return (string) get_post_meta($product_id, '_bookflow_credit_type', true);
    }

    public static function requires_manual_approval($product_id) {
        return (bool) get_post_meta($product_id, '_bookflow_requires_manual_approval', true);
    }

    /**
     * 0 (or unset) means no limit.
     */
    public static function max_active_bookings_per_customer($product_id) {
        return (int) get_post_meta($product_id, '_bookflow_max_active_bookings_per_customer', true);
    }

    /**
     * The customer flag key required to book this product, or '' if the
     * product has no eligibility gate. See Bookflow_Customer_Flags.
     */
    public static function required_flag_key($product_id) {
        return (string) get_post_meta($product_id, '_bookflow_requires_flag', true);
    }

    /**
     * The specific flag value required, or '' to accept any truthy value.
     */
    public static function required_flag_value($product_id) {
        return (string) get_post_meta($product_id, '_bookflow_requires_flag_value', true);
    }

    /**
     * The credit_type this product's purchase grants to the buyer on order
     * completion, or '' if this product doesn't grant credits (i.e. it's not
     * a "package"/"course" product). See Bookflow_Credits::on_order_completed().
     */
    public static function grants_credit_type($product_id) {
        return (string) get_post_meta($product_id, '_bookflow_grants_credit_type', true);
    }

    public static function grants_credit_amount($product_id) {
        return (int) get_post_meta($product_id, '_bookflow_grants_credit_amount', true);
    }

    /**
     * 0 (or unset) means the granted credits never expire.
     */
    public static function grants_credit_expires_days($product_id) {
        return (int) get_post_meta($product_id, '_bookflow_grants_credit_expires_days', true);
    }
}

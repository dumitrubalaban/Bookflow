<?php
/**
 * Customer credits — generic "package with a remaining balance" ledger.
 *
 * A credit pool is a bundle of prepaid units (bookflow_customer_credits)
 * granted to a customer, identified only by an opaque `credit_type` string
 * the integrator defines (e.g. "lesson", "session", "visit"). Bookflow core
 * never assigns meaning to that string — it just tracks totals, remaining
 * balance, optional expiry, and an auditable consume/refund ledger
 * (bookflow_credit_transactions) keyed to the booking that caused each
 * movement, so consumption and refunds are idempotent per booking.
 *
 * A product opts into auto-consumption by setting its `_bookflow_credit_type`
 * meta to a non-empty credit_type: confirming a booking for that product then
 * consumes one unit from the customer's matching pool, and cancelling it
 * refunds that unit. Products that don't set this meta are entirely
 * unaffected — the feature is opt-in per product.
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Credits {

    public function __construct() {
        add_action('bookflow_booking_confirmed', [$this, 'on_confirmed']);
        add_action('bookflow_booking_cancelled', [$this, 'on_cancelled']);
    }

    /**
     * Auto-consume a credit when a booking for a credit-consuming product
     * becomes confirmed (i.e. approved), if it hasn't consumed one already.
     */
    public function on_confirmed($booking_id) {
        $booking = Bookflow_Booking::get($booking_id);
        if (!$booking || empty($booking->customer_id)) {
            return;
        }

        $credit_type = get_post_meta($booking->product_id, '_bookflow_credit_type', true);
        if (empty($credit_type)) {
            return;
        }

        self::consume($booking->customer_id, $booking_id, $credit_type, $booking->product_id);
    }

    /**
     * Auto-refund the consumed credit when a booking is cancelled — unless
     * the integrator's theme has already recorded a forfeiting cancellation
     * (see the `bookflow_credit_should_refund_on_cancel` filter).
     */
    public function on_cancelled($booking_id) {
        $booking = Bookflow_Booking::get($booking_id);
        if (!$booking || empty($booking->customer_id)) {
            return;
        }

        $credit_type = get_post_meta($booking->product_id, '_bookflow_credit_type', true);
        if (empty($credit_type)) {
            return;
        }

        /**
         * Filter: bookflow_credit_should_refund_on_cancel
         *
         * Return false to forfeit the credit instead of refunding it (e.g.
         * a late cancellation policy). Defaults to true — always refund.
         */
        $should_refund = apply_filters('bookflow_credit_should_refund_on_cancel', true, $booking_id, $booking);
        if (!$should_refund) {
            return;
        }

        self::refund($booking_id, 'cancelled');
    }

    /**
     * Grant a new credit pool to a customer.
     *
     * @return int|WP_Error credit_id
     */
    public static function grant($customer_id, $total, $args = []) {
        global $wpdb;

        $defaults = [
            'product_id'  => null,
            'credit_type' => 'lesson',
            'expires_at'  => null,
        ];
        $args = wp_parse_args($args, $defaults);

        $total = max(0, absint($total));

        $inserted = $wpdb->insert($wpdb->prefix . 'bookflow_customer_credits', [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists
            'customer_id' => absint($customer_id),
            'product_id'  => $args['product_id'] ? absint($args['product_id']) : null,
            'credit_type' => sanitize_key($args['credit_type']),
            'total'       => $total,
            'remaining'   => $total,
            'expires_at'  => $args['expires_at'] ?: null,
            'status'      => 'active',
        ]);

        if (!$inserted) {
            return new WP_Error('db_error', 'Could not grant credits.');
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Sum of remaining, active, non-expired credits for a customer.
     */
    public static function get_balance($customer_id, $credit_type = 'lesson', $product_id = null) {
        global $wpdb;

        $pools = self::get_available_pools($customer_id, $credit_type, $product_id);
        $balance = 0;
        foreach ($pools as $pool) {
            $balance += (int) $pool->remaining;
        }
        return $balance;
    }

    /**
     * Active, non-expired, non-empty pools matching a customer/type, scoped
     * to a product when the pool itself was granted for a specific product
     * (globally-granted pools — product_id NULL — always match).
     */
    private static function get_available_pools($customer_id, $credit_type, $product_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_customer_credits';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists; $table built from prefix + literal only
        $sql = $wpdb->prepare(
            "SELECT * FROM $table
             WHERE customer_id = %d AND credit_type = %s AND status = 'active' AND remaining > 0
             AND (product_id IS NULL OR product_id = %d)
             AND (expires_at IS NULL OR expires_at >= %s)
             ORDER BY (expires_at IS NULL), expires_at ASC, id ASC",
            absint($customer_id),
            sanitize_key($credit_type),
            absint($product_id),
            current_time('mysql')
        );
        // phpcs:enable

        return $wpdb->get_results($sql);
    }

    /**
     * Consume one unit of credit for a booking. Idempotent: calling this
     * twice for the same booking_id has no further effect.
     *
     * @return true|WP_Error
     */
    public static function consume($customer_id, $booking_id, $credit_type = 'lesson', $product_id = null, $amount = 1) {
        global $wpdb;
        $amount = max(1, absint($amount));

        $wpdb->query('START TRANSACTION'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        $table = $wpdb->prefix . 'bookflow_customer_credits';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists
        $pool = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table
             WHERE customer_id = %d AND credit_type = %s AND status = 'active' AND remaining >= %d
             AND (product_id IS NULL OR product_id = %d)
             AND (expires_at IS NULL OR expires_at >= %s)
             ORDER BY (expires_at IS NULL), expires_at ASC, id ASC
             FOR UPDATE",
            absint($customer_id),
            sanitize_key($credit_type),
            $amount,
            absint($product_id),
            current_time('mysql')
        ));
        // phpcs:enable

        if (!$pool) {
            $wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            return new WP_Error('no_credits', 'Customer has no remaining credits of this type.');
        }

        $ledger = $wpdb->insert($wpdb->prefix . 'bookflow_credit_transactions', [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            'credit_id'  => (int) $pool->id,
            'booking_id' => $booking_id ? absint($booking_id) : null,
            'delta'      => -$amount,
            'type'       => 'consume',
        ]);

        // A duplicate-key failure here means this booking already consumed
        // from this pool — treat as already-done, not an error.
        if (!$ledger) {
            $wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            return true;
        }

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $table,
            ['remaining' => (int) $pool->remaining - $amount],
            ['id' => (int) $pool->id]
        );

        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        do_action('bookflow_credit_consumed', $pool->id, $booking_id, $amount);

        return true;
    }

    /**
     * Refund the credit(s) previously consumed for a booking. Idempotent:
     * calling this twice for the same booking has no further effect, and
     * refunding a booking that never consumed anything is a no-op.
     *
     * @return true|WP_Error
     */
    public static function refund($booking_id, $note = '') {
        global $wpdb;
        $credits_table = $wpdb->prefix . 'bookflow_customer_credits';
        $tx_table = $wpdb->prefix . 'bookflow_credit_transactions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists
        $consumes = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $tx_table WHERE booking_id = %d AND type = 'consume'",
            absint($booking_id)
        ));

        if (!$consumes) {
            return true; // nothing to refund
        }

        foreach ($consumes as $consume) {
            $inserted = $wpdb->insert($tx_table, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                'credit_id'  => (int) $consume->credit_id,
                'booking_id' => absint($booking_id),
                'delta'      => abs((int) $consume->delta),
                'type'       => 'refund',
                'note'       => sanitize_text_field($note),
            ]);

            if (!$inserted) {
                continue; // already refunded (unique key) — skip
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists
            $wpdb->query($wpdb->prepare(
                "UPDATE $credits_table SET remaining = remaining + %d WHERE id = %d",
                abs((int) $consume->delta),
                (int) $consume->credit_id
            ));
        }

        do_action('bookflow_credit_refunded', $booking_id);

        return true;
    }
}

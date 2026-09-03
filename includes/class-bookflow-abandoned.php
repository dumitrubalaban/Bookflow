<?php
/**
 * Abandoned-Booking Recovery
 *
 * Captures partial contact info (name/phone/email) as soon as a visitor
 * fills it in on the wizard's Contact step but never completes checkout,
 * so a "still interested?" follow-up email can go out an hour later.
 * Marked recovered the moment a matching real booking is created.
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Abandoned {

    public function __construct() {
        add_action('wp_ajax_bookflow_save_partial', [$this, 'ajax_save_partial']);
        add_action('wp_ajax_nopriv_bookflow_save_partial', [$this, 'ajax_save_partial']);
        add_action('woocommerce_checkout_order_processed', [$this, 'mark_recovered'], 20, 3);
    }

    /**
     * Upsert a partial-contact row by product+email+phone, so repeated
     * blur events from the same visitor update one row instead of spamming
     * new ones.
     */
    public function ajax_save_partial() {
        check_ajax_referer('bookflow_nonce', 'nonce');

        $product_id = absint($_POST['product_id'] ?? 0);
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $step = sanitize_text_field(wp_unslash($_POST['step'] ?? ''));

        if (!$product_id || (!$email && !$phone)) {
            wp_send_json_error(['message' => Bookflow_I18n::t('error.invalid_request')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_abandoned_bookings';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists; live data required for booking/availability integrity; $table is $wpdb->prefix + a literal suffix, never request data; real values go through %d/%s + $wpdb->prepare()
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE product_id = %d AND recovered = 0
             AND ((email != '' AND email = %s) OR (phone != '' AND phone = %s))
             ORDER BY id DESC LIMIT 1",
            $product_id, $email, $phone
        ));
        // phpcs:enable

        $data = [
            'product_id'   => $product_id,
            'email'        => $email,
            'phone'        => $phone,
            'name'         => $name,
            'step_reached' => $step,
        ];

        if ($existing_id) {
            $wpdb->update($table, $data, ['id' => (int) $existing_id]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
        } else {
            $wpdb->insert($table, $data); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
        }

        wp_send_json_success();
    }

    /**
     * Mark any abandoned row matching this order's billing email/phone as
     * recovered, so it's excluded from the follow-up cron.
     */
    public function mark_recovered($order_id, $posted_data, $order) {
        $email = $order->get_billing_email();
        $phone = $order->get_billing_phone();
        if (!$email && !$phone) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_abandoned_bookings';

        // Let MySQL compute "now" itself (DATE_SUB(NOW(), ...)) rather than
        // passing a PHP-computed cutoff — the app server's clock isn't
        // guaranteed to agree with the DB server's, and `created_at` is
        // populated by the DB's own CURRENT_TIMESTAMP.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists; live data required for booking/availability integrity; $table is $wpdb->prefix + a literal suffix, never request data; real values go through %d/%s + $wpdb->prepare()
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET recovered = 1
             WHERE recovered = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
             AND ((email != '' AND email = %s) OR (phone != '' AND phone = %s))",
            $email, $phone
        ));
        // phpcs:enable
    }

    /**
     * Cron handler: email anyone who abandoned more than an hour ago and
     * hasn't been followed up with yet.
     */
    public static function send_followups() {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_abandoned_bookings';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists; live data required for booking/availability integrity; $table is $wpdb->prefix + a literal suffix, never request data; real values go through %d/%s + $wpdb->prepare()
        $rows = $wpdb->get_results(
            "SELECT * FROM $table WHERE recovered = 0 AND followup_sent_at IS NULL
             AND created_at <= DATE_SUB(NOW(), INTERVAL 1 HOUR) LIMIT 50"
        );
        // phpcs:enable

        foreach ($rows as $row) {
            if (!$row->email) {
                // No email captured (phone-only) — nothing to send to; mark
                // sent so it isn't retried forever.
                $wpdb->query($wpdb->prepare("UPDATE $table SET followup_sent_at = NOW() WHERE id = %d", $row->id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists; live data required for booking/availability integrity; table/sql var built only from fixed literals ($wpdb->prefix + literal string or %d/%s placeholders resolved via $wpdb->prepare()), never from unescaped request data
                continue;
            }
            self::send_followup_email($row);
            $wpdb->update($table, ['followup_sent_at' => current_time('mysql', true)], ['id' => $row->id]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
        }
    }

    private static function send_followup_email($row) {
        $product = wc_get_product($row->product_id);
        if (!$product) {
            return;
        }

        $site_name = get_bloginfo('name');
        $subject = Bookflow_I18n::t('email.subject.abandoned_followup', $product->get_name());

        $html = '<div style="font-family:sans-serif;max-width:520px;margin:0 auto;padding:24px;">'
            . '<h2 style="margin-top:0;">' . esc_html($site_name) . '</h2>'
            . '<p>' . esc_html(sprintf(Bookflow_I18n::t('email.body.abandoned_followup'), $row->name ?: '', $product->get_name())) . '</p>'
            . '<p><a href="' . esc_url($product->get_permalink()) . '" style="display:inline-block;background:#917236;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;">'
            . esc_html(Bookflow_I18n::t('email.label.finish_booking')) . '</a></p>'
            . '</div>';

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . get_option('admin_email') . '>',
        ];

        wp_mail($row->email, $subject, $html, $headers);
    }
}

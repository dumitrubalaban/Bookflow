<?php
/**
 * Waiting list for fully-booked slots.
 *
 * A customer who hits a full slot in the widget can leave their contact
 * details instead of walking away; when a cancellation frees capacity on
 * that exact product/date/time/resource/schedule, everyone still waiting
 * gets emailed a "spot opened up" notification with a link back to book.
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Waitlist {

    public function __construct() {
        add_action('wp_ajax_bookflow_join_waitlist', [$this, 'ajax_join']);
        add_action('wp_ajax_nopriv_bookflow_join_waitlist', [$this, 'ajax_join']);
        // Fired by Bookflow_Booking::transition_status() — see
        // do_action("bookflow_booking_{$new_status}", ...) in
        // class-bookflow-booking.php. Both statuses can free a slot.
        add_action('bookflow_booking_cancelled', [$this, 'on_booking_freed_slot']);
        add_action('bookflow_booking_refunded', [$this, 'on_booking_freed_slot']);
    }

    public function ajax_join() {
        check_ajax_referer('bookflow_nonce', 'nonce');

        if (!Bookflow_Rate_Limit::check('join_waitlist', 10, 60)) {
            wp_send_json_error(['message' => Bookflow_I18n::t('calendar.error_generic')]);
        }

        // Same honeypot convention as the main booking form.
        if (!empty($_POST['bookflow_website'])) {
            wp_send_json_error(['message' => Bookflow_I18n::t('calendar.error_generic')]);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        $start_time = sanitize_text_field(wp_unslash($_POST['start_time'] ?? ''));
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));

        if (!$product_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $start_time)) {
            wp_send_json_error(['message' => Bookflow_I18n::t('error.invalid_request')]);
        }
        if (!$name || !is_email($email)) {
            wp_send_json_error(['message' => Bookflow_I18n::t('form.error_email')]);
        }

        $id = self::create([
            'product_id'        => $product_id,
            'resource_id'       => absint($_POST['resource_id'] ?? 0) ?: null,
            'schedule_id'       => absint($_POST['schedule_id'] ?? 0) ?: null,
            'booking_date'      => $date,
            'start_time'        => $start_time,
            'persons_requested' => max(1, absint($_POST['persons'] ?? 1)),
            'customer_name'     => $name,
            'customer_email'    => $email,
            'customer_phone'    => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
        ]);

        if (is_wp_error($id)) {
            wp_send_json_error(['message' => $id->get_error_message()]);
        }

        wp_send_json_success(['id' => $id]);
    }

    /**
     * @return int|WP_Error
     */
    public static function create($data) {
        global $wpdb;

        $inserted = $wpdb->insert($wpdb->prefix . 'bookflow_waitlist', [
            'product_id'        => absint($data['product_id']),
            'resource_id'       => $data['resource_id'] ?? null,
            'schedule_id'       => $data['schedule_id'] ?? null,
            'booking_date'      => $data['booking_date'],
            'start_time'        => $data['start_time'],
            'persons_requested' => max(1, absint($data['persons_requested'] ?? 1)),
            'customer_name'     => $data['customer_name'],
            'customer_email'    => $data['customer_email'],
            'customer_phone'    => $data['customer_phone'] ?? '',
            'status'            => 'waiting',
            'created_at'        => current_time('mysql'),
        ]);

        if (!$inserted) {
            return new WP_Error('waitlist_insert_failed', Bookflow_I18n::t('calendar.error_generic'));
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Entries still waiting for a given slot, oldest first (first-come,
     * first-notified).
     */
    public static function get_for_slot($product_id, $date, $start_time, $resource_id = null, $schedule_id = null, $status = 'waiting') {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_waitlist';

        $where = ['product_id = %d', 'booking_date = %s', 'start_time = %s', 'status = %s'];
        $values = [absint($product_id), $date, $start_time, $status];

        if ($resource_id) {
            $where[] = 'resource_id = %d';
            $values[] = absint($resource_id);
        }
        if ($schedule_id) {
            $where[] = 'schedule_id = %d';
            $values[] = absint($schedule_id);
        }

        $sql = "SELECT * FROM $table WHERE " . implode(' AND ', $where) . ' ORDER BY created_at ASC';
        return $wpdb->get_results($wpdb->prepare($sql, ...$values));
    }

    /**
     * A booking on this slot just freed capacity — notify anyone still
     * waiting, oldest request first, until the newly-freed capacity is
     * accounted for (each notified entry "claims" its requested persons so
     * the plugin doesn't over-promise a spot to everyone at once).
     */
    public function on_booking_freed_slot($booking_id) {
        $booking = Bookflow_Booking::get($booking_id);
        if (!$booking) {
            return;
        }

        $entries = self::get_for_slot(
            $booking->product_id, $booking->booking_date, $booking->start_time,
            $booking->resource_id, $booking->schedule_id
        );
        if (!$entries) {
            return;
        }

        foreach ($entries as $entry) {
            if (!Bookflow_Availability::is_slot_available(
                $booking->product_id, $booking->booking_date, $booking->start_time,
                $booking->resource_id, $booking->schedule_id
            )) {
                break; // no more freed capacity for this slot right now
            }

            self::notify($entry);
        }
    }

    private static function notify($entry) {
        global $wpdb;

        $product = wc_get_product($entry->product_id);
        if (!$product) {
            return;
        }

        $subject = sprintf(Bookflow_I18n::t('email.subject.waitlist_opened'), $product->get_name());
        $book_url = esc_url_raw(get_permalink($entry->product_id));
        $body = sprintf(
            '<p>%s</p><p><a href="%s">%s</a></p>',
            esc_html(sprintf(Bookflow_I18n::t('email.body.waitlist_opened'), $entry->customer_name, $product->get_name(), $entry->booking_date, $entry->start_time)),
            esc_url($book_url),
            esc_html(Bookflow_I18n::t('email.label.finish_booking'))
        );

        wp_mail($entry->customer_email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);

        $wpdb->update(
            $wpdb->prefix . 'bookflow_waitlist',
            ['status' => 'notified', 'notified_at' => current_time('mysql')],
            ['id' => $entry->id]
        );
    }
}

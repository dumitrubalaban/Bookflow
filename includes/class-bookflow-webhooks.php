<?php
/**
 * Generic outbound webhooks.
 *
 * Off by default: a site owner wires `bookflow_webhook_urls` (and optionally
 * `bookflow_webhook_secret`) to point at their own endpoint(s) — an n8n/Zapier
 * flow, a CRM, a Slack relay, whatever. Every booking lifecycle event fires
 * a signed JSON POST to each configured URL.
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Webhooks {

    public function __construct() {
        add_action('bookflow_booking_created', [$this, 'on_created'], 10, 2);
        add_action('bookflow_booking_status_changed', [$this, 'on_status_changed'], 10, 3);
        add_action('bookflow_booking_deleted', [$this, 'on_deleted'], 10, 2);
    }

    public function on_created($data, $booking_id) {
        $this->dispatch('booking.created', $booking_id);
    }

    public function on_status_changed($booking_id, $old_status, $new_status) {
        $this->dispatch('booking.status_changed', $booking_id, [
            'old_status' => $old_status,
            'new_status' => $new_status,
        ]);
    }

    public function on_deleted($id, $booking) {
        // The row is already gone by the time this fires, so send what we
        // have from the hook rather than re-fetching (which would return
        // nothing) — same reasoning as the "structurally offered" fix for
        // Bookflow_Availability, favor real data over a dropped event.
        $this->send(self::urls(), 'booking.deleted', [
            'event'   => 'booking.deleted',
            'booking' => ['id' => (int) $id, 'product_id' => (int) ($booking->product_id ?? 0)],
            'timestamp' => current_time('mysql'),
        ]);
    }

    private function dispatch($event, $booking_id, $extra = []) {
        $urls = self::urls();
        if (!$urls) {
            return;
        }

        $booking = Bookflow_Booking::get($booking_id);
        if (!$booking) {
            return;
        }

        $payload = array_merge([
            'event'     => $event,
            'timestamp' => current_time('mysql'),
            'booking'   => $this->serialize_booking($booking),
        ], $extra);

        $this->send($urls, $event, $payload);
    }

    private function serialize_booking($booking) {
        return [
            'id'             => (int) $booking->id,
            'product_id'     => (int) $booking->product_id,
            'resource_id'    => $booking->resource_id ? (int) $booking->resource_id : null,
            'booking_date'   => $booking->booking_date,
            'start_time'     => $booking->start_time,
            'end_time'       => $booking->end_time,
            'persons_total'  => (int) $booking->persons_total,
            'cost'           => (float) $booking->cost,
            'status'         => $booking->status,
            'customer_name'  => $booking->customer_name,
            'customer_email' => $booking->customer_email,
            'customer_phone' => $booking->customer_phone,
        ];
    }

    /**
     * @return string[] Configured webhook endpoint URLs (empty = feature off).
     */
    private static function urls() {
        $urls = apply_filters('bookflow_webhook_urls', []);
        return array_values(array_filter(array_map('esc_url_raw', (array) $urls)));
    }

    private function send($urls, $event, $payload) {
        $body = wp_json_encode($payload);
        $secret = apply_filters('bookflow_webhook_secret', '');

        $headers = ['Content-Type' => 'application/json', 'X-Bookflow-Event' => $event];
        if ($secret) {
            // HMAC-SHA256 over the raw body, hex-encoded — the standard
            // "verify this actually came from us" pattern (Stripe/GitHub
            // webhooks use the same shape), so a receiver can reject
            // forged or tampered payloads instead of trusting the network.
            $headers['X-Bookflow-Signature'] = hash_hmac('sha256', $body, $secret);
        }

        foreach ($urls as $url) {
            $response = wp_remote_post($url, [
                'timeout'   => 5,
                'blocking'  => false, // fire-and-forget: never slow down the booking flow for a third-party endpoint
                'headers'   => $headers,
                'body'      => $body,
            ]);

            if (is_wp_error($response)) {
                Bookflow_Logger::log('webhook_dispatch_failed', $payload['booking']['id'] ?? null, [
                    'url' => $url, 'event' => $event, 'error' => $response->get_error_message(),
                ]);
            }
        }
    }
}

<?php
/**
 * PingMe integration
 *
 * Wires Bookflow's own lifecycle hooks (`bookflow_booking_created`,
 * `bookflow_booking_status_changed`, ...) to the PingMe_Connect plugin, the same
 * way that plugin's own class-pingme-hooks.php wires WooCommerce order
 * events. This file deliberately lives in Bookflow, not in PingMe_Connect —
 * PingMe_Connect has no idea what a "booking" is, and Bookflow has no idea what
 * PingMe is; the only coupling is three filters/actions PingMe_Connect
 * already documents as its extension points (`pingme_channels`,
 * `pingme_views`, `PingMe_Notify::event()`). If PingMe_Connect isn't
 * installed, every method here is a silent no-op.
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_PingMe {

    public static function init() {
        // Registering these filters costs nothing if PingMe_Connect isn't
        // active — WordPress just never calls a filter/action nobody applies.
        add_filter('pingme_channels', [__CLASS__, 'register_channel']);
        add_filter('pingme_views', [__CLASS__, 'register_view']);

        add_action('bookflow_booking_created', [__CLASS__, 'on_booking_created'], 10, 2);
        add_action('bookflow_booking_status_changed', [__CLASS__, 'on_status_changed'], 10, 3);
    }

    /** @param array<array{key:string,label:string,icon?:string,read_endpoint?:string,list_type?:string}> $channels */
    public static function register_channel(array $channels): array {
        $channels[] = [
            'key' => 'bookings',
            'label' => Bookflow_I18n::t('pingme.channel_bookings'),
            'icon' => 'calendar',
            // Same idea as PingMe_Connect's own "orders" channel: point at
            // an API the app already knows how to authenticate against
            // (same Application Password, same site) instead of inventing
            // a parallel read path.
            'read_endpoint' => '/wp-json/bookflow/v1/bookings',
            'list_type' => 'booking.created',
        ];
        return $channels;
    }

    /** @param array<string, array{title?: string, fields: array}> $views */
    public static function register_view(array $views): array {
        $views['booking.created'] = [
            'title' => Bookflow_I18n::t('pingme.view_title'),
            'fields' => [
                ['key' => 'customer_name', 'label' => Bookflow_I18n::t('pingme.field_customer'), 'type' => 'text'],
                ['key' => 'booking_date', 'label' => Bookflow_I18n::t('pingme.field_date'), 'type' => 'date'],
                ['key' => 'start_time', 'label' => Bookflow_I18n::t('pingme.field_time'), 'type' => 'text'],
                ['key' => 'persons_total', 'label' => Bookflow_I18n::t('pingme.field_guests'), 'type' => 'text'],
                ['key' => 'cost', 'label' => Bookflow_I18n::t('pingme.field_total'), 'type' => 'currency'],
                ['key' => 'status', 'label' => Bookflow_I18n::t('pingme.field_status'), 'type' => 'badge'],
            ],
        ];
        return $views;
    }

    /** Fires once, right after a booking is inserted (any status — usually "pending"). */
    public static function on_booking_created(array $data, int $booking_id) {
        if (!class_exists('PingMe_Notify')) {
            return;
        }

        $booking = Bookflow_Booking::get($booking_id);
        if (!$booking) {
            return;
        }

        $product = wc_get_product($booking->product_id);
        $product_name = $product ? $product->get_name() : Bookflow_I18n::t('pingme.booking_fallback');

        PingMe_Notify::event(
            'booking.created',
            self::payload($booking, $product_name, $product),
            [
                'title' => Bookflow_I18n::t('pingme.new_booking_title'),
                'body' => Bookflow_I18n::t(
                    'pingme.new_booking_body',
                    $product_name,
                    self::format_date($booking->booking_date),
                    $booking->start_time
                ),
            ],
            'bookings'
        );
    }

    /**
     * Fires for every status transition. Most transitions are surfaced with
     * a real push (confirmed/paid/cancelled/no-show — each is something an
     * owner or a second staff device needs to know about); "in-progress"
     * and "completed" only refresh the app's local data silently, same as
     * PingMe_Connect's own order.status_changed for the less noteworthy
     * WooCommerce transitions.
     */
    public static function on_status_changed(int $booking_id, string $old_status, string $new_status) {
        if (!class_exists('PingMe_Notify')) {
            return;
        }

        $booking = Bookflow_Booking::get($booking_id);
        if (!$booking) {
            return;
        }

        $product = wc_get_product($booking->product_id);
        $product_name = $product ? $product->get_name() : Bookflow_I18n::t('pingme.booking_fallback');
        $payload = self::payload($booking, $product_name, $product);
        $payload['from'] = $old_status;
        $payload['to'] = $new_status;

        PingMe_Notify::event(
            'booking.status_changed',
            $payload,
            self::notification_for($new_status, $product_name, $booking),
            'bookings'
        );
    }

    /** @return array{title?: string, body?: string} empty array = silent (badge/refresh only) */
    private static function notification_for(string $status, string $product_name, $booking): array {
        switch ($status) {
            case 'confirmed':
                return [
                    'title' => Bookflow_I18n::t('pingme.confirmed_title'),
                    'body' => Bookflow_I18n::t('pingme.confirmed_body', $product_name),
                ];
            case 'paid':
                return [
                    'title' => Bookflow_I18n::t('pingme.paid_title'),
                    'body' => Bookflow_I18n::t('pingme.paid_body', $product_name),
                ];
            case 'cancelled':
                return [
                    'title' => Bookflow_I18n::t('pingme.cancelled_title'),
                    'body' => $booking->cancellation_reason
                        ? Bookflow_I18n::t(
                            'pingme.cancelled_body_with_reason',
                            $product_name,
                            wp_trim_words($booking->cancellation_reason, 12)
                        )
                        : Bookflow_I18n::t('pingme.cancelled_body', $product_name),
                ];
            case 'no-show':
                return [
                    'title' => Bookflow_I18n::t('pingme.no_show_title'),
                    'body' => Bookflow_I18n::t('pingme.no_show_body', $product_name),
                ];
            default:
                // in-progress, completed, and anything a future version adds:
                // still worth telling the Worker so the app's cached booking
                // list refreshes, just not worth interrupting anyone for.
                return [];
        }
    }

    /** @return array<string, mixed> small, JSON-encodable — the app refetches the real record via read_endpoint. */
    private static function payload($booking, string $product_name, $product): array {
        return [
            'id' => (int) $booking->id,
            'product_id' => (int) $booking->product_id,
            'product_name' => $product_name,
            'customer_name' => $booking->customer_name,
            'booking_date' => $booking->booking_date,
            'start_time' => $booking->start_time,
            'persons_total' => (int) $booking->persons_total,
            'cost' => (float) $booking->cost,
            'currency' => get_woocommerce_currency(),
            'status' => $booking->status,
            // First product image, same idea as PingMe_Connect's order
            // notifications — a real thumbnail beats the generic app icon.
            'image' => $product && $product->get_image_id()
                ? (wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') ?: '')
                : '',
        ];
    }

    private static function format_date(string $date): string {
        $timestamp = strtotime($date);
        return $timestamp ? date_i18n(get_option('date_format'), $timestamp) : $date;
    }
}

<?php
/**
 * WooCommerce Cart Integration
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Cart {

    public function __construct() {
        add_filter('woocommerce_add_cart_item_data', [$this, 'add_booking_data_to_cart'], 10, 3);
        add_filter('woocommerce_get_item_data', [$this, 'display_booking_data_in_cart'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'save_booking_data_to_order'], 10, 4);
        add_filter('woocommerce_cart_item_price', [$this, 'update_cart_item_price'], 10, 3);
        add_action('woocommerce_before_calculate_totals', [$this, 'set_booking_price'], 10, 1);
        add_action('woocommerce_checkout_order_processed', [$this, 'create_booking_on_order'], 10, 3);
        add_action('woocommerce_order_status_completed', [$this, 'confirm_booking']);
        add_action('woocommerce_order_status_processing', [$this, 'paid_booking']);
        add_action('woocommerce_order_status_cancelled', [$this, 'cancel_booking']);
        add_action('woocommerce_order_status_refunded', [$this, 'refund_booking']);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_booking'], 10, 3);
        add_filter('woocommerce_hidden_order_itemmeta', [$this, 'hide_internal_meta']);
    }

    /**
     * Hide internal _bookflow_ meta keys from order admin view
     */
    public function hide_internal_meta($hidden) {
        $hidden[] = '_bookflow_booking_date';
        $hidden[] = '_bookflow_start_time';
        $hidden[] = '_bookflow_persons_total';
        $hidden[] = '_bookflow_resource_id';
        $hidden[] = '_bookflow_customer_name';
        $hidden[] = '_bookflow_customer_email';
        $hidden[] = '_bookflow_customer_phone';
        $hidden[] = '_bookflow_notes';
        $hidden[] = '_bookflow_person_types';
        $hidden[] = '_bookflow_booking_id';
        $hidden[] = '_bookflow_souvenir_for';
        $hidden[] = '_bookflow_schedule_id';
        $hidden[] = '_bookflow_tour_language';
        $hidden[] = '_bookflow_location_tag';
        $hidden[] = '_bookflow_terms_accepted';
        $hidden[] = '_bookflow_terms_accepted_at';
        $hidden[] = '_bookflow_extras';
        $hidden[] = 'Persoane';
        return $hidden;
    }

    /**
     * Validate booking before adding to cart
     */
    public function validate_booking($valid, $product_id, $quantity) {
        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'booking') {
            return $valid;
        }

        $date = sanitize_text_field(wp_unslash($_POST['bookflow_booking_date'] ?? ''));
        $time = sanitize_text_field(wp_unslash($_POST['bookflow_start_time'] ?? ''));
        $persons = absint($_POST['bookflow_persons_total'] ?? 1);
        $resource_id = absint($_POST['bookflow_resource_id'] ?? 0) ?: null;

        if (empty($date) || empty($time)) {
            wc_add_notice(Bookflow_I18n::t('error.select_date_and_time'), 'error');
            return false;
        }

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            wc_add_notice(Bookflow_I18n::t('error.invalid_date_format'), 'error');
            return false;
        }

        // Validate time format
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            wc_add_notice(Bookflow_I18n::t('error.invalid_time_format'), 'error');
            return false;
        }

        if (!Bookflow_Availability::is_slot_available($product_id, $date, $time, $resource_id)) {
            wc_add_notice(Bookflow_I18n::t('error.slot_unavailable'), 'error');
            return false;
        }

        if (get_post_meta($product_id, '_bookflow_terms_text', true) && empty($_POST['bookflow_terms_accepted'])) {
            wc_add_notice(Bookflow_I18n::t('form.error_terms'), 'error');
            return false;
        }

        // Validate person count
        if (Bookflow_Person_Types::product_has_types($product_id)) {
            $person_types = isset($_POST['bookflow_person_types']) && is_array($_POST['bookflow_person_types'])
                ? wp_unslash($_POST['bookflow_person_types'])
                : [];
            $persons_data = [];
            foreach ($person_types as $key => $pt) {
                $type_id = absint($pt['id'] ?? $key);
                $persons_data[$type_id] = absint($pt['quantity'] ?? 0);
            }
            // Enforces each type's min/max and total >= 1
            $check = Bookflow_Person_Types::validate($product_id, $persons_data);
            if (is_wp_error($check)) {
                wc_add_notice($check->get_error_message(), 'error');
                return false;
            }
        } else {
            $min = $product->get_min_persons();
            $max = $product->get_max_persons();
            if ($persons < $min || $persons > $max) {
                wc_add_notice(
                    Bookflow_I18n::t('error.persons_between', $min, $max),
                    'error'
                );
                return false;
            }
        }

        return $valid;
    }

    /**
     * Add booking data to cart item
     */
    public function add_booking_data_to_cart($cart_item_data, $product_id, $variation_id) {
        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'booking') {
            return $cart_item_data;
        }

        $date = sanitize_text_field(wp_unslash($_POST['bookflow_booking_date'] ?? ''));
        $time = sanitize_text_field(wp_unslash($_POST['bookflow_start_time'] ?? ''));
        $resource_id = absint($_POST['bookflow_resource_id'] ?? 0) ?: null;

        if (empty($date) || empty($time)) {
            return $cart_item_data;
        }

        $cart_item_data['bookflow_booking_date'] = $date;
        $cart_item_data['bookflow_start_time'] = $time;
        $cart_item_data['bookflow_resource_id'] = $resource_id;
        $cart_item_data['bookflow_customer_name'] = sanitize_text_field(wp_unslash($_POST['bookflow_customer_name'] ?? ''));
        $cart_item_data['bookflow_customer_email'] = sanitize_email(wp_unslash($_POST['bookflow_customer_email'] ?? ''));
        $cart_item_data['bookflow_customer_phone'] = sanitize_text_field(wp_unslash($_POST['bookflow_customer_phone'] ?? ''));
        $cart_item_data['bookflow_notes'] = sanitize_textarea_field(wp_unslash($_POST['bookflow_notes'] ?? ''));

        // Location tag chosen in the wizard — informational only, not used by
        // availability/pricing (Bookflow keeps "one location = one product").
        $location_tag = sanitize_title(wp_unslash($_POST['bookflow_location_tag'] ?? ''));
        if ($location_tag) {
            $cart_item_data['bookflow_location_tag'] = $location_tag;
        }

        // Liability/terms waiver — only recorded when the product actually
        // requires one (checkbox only renders then).
        if (get_post_meta($product_id, '_bookflow_terms_text', true)) {
            $cart_item_data['bookflow_terms_accepted'] = !empty($_POST['bookflow_terms_accepted']);
            $cart_item_data['bookflow_terms_accepted_at'] = current_time('mysql');
        }

        // Tour language (from schedule selection)
        $schedule_id = absint($_POST['bookflow_schedule_id'] ?? 0) ?: null;
        $cart_item_data['bookflow_schedule_id'] = $schedule_id;
        $lang_code = sanitize_text_field(wp_unslash($_POST['bookflow_language'] ?? ''));
        if ($lang_code) {
            $lang_labels = ['ro' => 'Română', 'ru' => 'Русский', 'en' => 'English', 'fr' => 'Français', 'tr' => 'Türkçe', 'it' => 'Italiano'];
            $cart_item_data['bookflow_tour_language'] = $lang_code;
            $cart_item_data['bookflow_tour_language_label'] = $lang_labels[$lang_code] ?? $lang_code;
        }

        // Extras (cart-level upsells) — server re-prices from the DB, never
        // trusts a client-submitted price.
        $extra_ids = isset($_POST['bookflow_extras']) && is_array($_POST['bookflow_extras'])
            ? array_map('absint', wp_unslash($_POST['bookflow_extras']))
            : [];
        $extras = $extra_ids ? Bookflow_Extras::get_many($extra_ids) : [];
        if ($extras) {
            $cart_item_data['bookflow_extras'] = array_map(function ($e) {
                return ['id' => (int) $e->id, 'title' => $e->title, 'price' => (float) $e->price];
            }, $extras);
        }
        $extra_ids_for_pricing = wp_list_pluck($extras, 'id');

        // Person types or simple count
        if (Bookflow_Person_Types::product_has_types($product_id) && !empty($_POST['bookflow_person_types'])) {
            $person_types = [];
            $total_persons = 0;
            $raw_person_types = is_array($_POST['bookflow_person_types']) ? wp_unslash($_POST['bookflow_person_types']) : [];
            foreach ($raw_person_types as $pt) {
                $qty = absint($pt['quantity'] ?? 0);
                if ($qty > 0) {
                    $person_types[] = [
                        'person_type_id' => absint($pt['person_type_id']),
                        'quantity'       => $qty,
                    ];
                    $total_persons += $qty;
                }
            }
            $cart_item_data['bookflow_person_types'] = $person_types;
            $cart_item_data['bookflow_persons_total'] = $total_persons;
            $cart_item_data['bookflow_cost'] = Bookflow_Pricing::calculate_total($product_id, $date, $time, $person_types, $resource_id, $extra_ids_for_pricing);
        } else {
            $persons = absint($_POST['bookflow_persons_total'] ?? 1);
            $cart_item_data['bookflow_persons_total'] = $persons;
            $cart_item_data['bookflow_cost'] = Bookflow_Pricing::calculate_total($product_id, $date, $time, $persons, $resource_id, $extra_ids_for_pricing);
        }

        // Unique key to allow multiple bookings in cart
        $cart_item_data['unique_key'] = md5($product_id . $date . $time . microtime());

        return $cart_item_data;
    }

    /**
     * Display booking info in cart
     */
    public function display_booking_data_in_cart($item_data, $cart_item) {
        if (isset($cart_item['bookflow_booking_date'])) {
            $item_data[] = [
                'key'   => Bookflow_I18n::t('cart.date'),
                'value' => date_i18n(get_option('date_format'), strtotime($cart_item['bookflow_booking_date'])),
            ];
        }
        if (isset($cart_item['bookflow_start_time'])) {
            $item_data[] = [
                'key'   => Bookflow_I18n::t('cart.time'),
                'value' => esc_html($cart_item['bookflow_start_time']),
            ];
        }
        if (isset($cart_item['bookflow_persons_total'])) {
            $item_data[] = [
                'key'   => Bookflow_I18n::t('cart.persons'),
                'value' => esc_html($cart_item['bookflow_persons_total']),
            ];
        }
        if (!empty($cart_item['bookflow_resource_id'])) {
            $resource = Bookflow_Resources::get($cart_item['bookflow_resource_id']);
            if ($resource) {
                $item_data[] = [
                    'key'   => Bookflow_I18n::t('cart.resource'),
                    'value' => esc_html($resource->title),
                ];
            }
        }
        if (!empty($cart_item['bookflow_extras'])) {
            $item_data[] = [
                'key'   => Bookflow_I18n::t('cart.extras'),
                'value' => esc_html(implode(', ', wp_list_pluck($cart_item['bookflow_extras'], 'title'))),
            ];
        }
        if (!empty($cart_item['bookflow_customer_name'])) {
            $item_data[] = [
                'key'   => Bookflow_I18n::t('cart.name'),
                'value' => esc_html($cart_item['bookflow_customer_name']),
            ];
        }
        return $item_data;
    }

    /**
     * Set correct price in cart
     */
    public function set_booking_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        foreach ($cart->get_cart() as $cart_item) {
            if (isset($cart_item['bookflow_cost']) && method_exists($cart_item['data'], 'set_bookflow_dynamic_price')) {
                // WC_Product_Booking::get_price() always reads the product's
                // base post-meta directly, so a plain WC_Product::set_price()
                // has no effect on this product type — this is the actual
                // per-cart-item override point.
                $cart_item['data']->set_bookflow_dynamic_price($cart_item['bookflow_cost']);
            }
        }
    }

    public function update_cart_item_price($price, $cart_item, $cart_item_key) {
        if (isset($cart_item['bookflow_cost'])) {
            return wc_price($cart_item['bookflow_cost']);
        }
        return $price;
    }

    /**
     * Save booking data to order item meta
     */
    public function save_booking_data_to_order($item, $cart_item_key, $values, $order) {
        if (!isset($values['bookflow_booking_date'])) {
            return;
        }

        // Visible meta
        $item->add_meta_data(Bookflow_I18n::t('cart.booking_date'), $values['bookflow_booking_date']);
        $item->add_meta_data(Bookflow_I18n::t('cart.time'), $values['bookflow_start_time']);
        $item->add_meta_data(Bookflow_I18n::t('cart.persons'), $values['bookflow_persons_total']);
        if (!empty($values['bookflow_tour_language_label'])) {
            $item->add_meta_data('Limba', $values['bookflow_tour_language_label']);
        }

        // Internal meta
        $item->add_meta_data('_bookflow_booking_date', $values['bookflow_booking_date']);
        $item->add_meta_data('_bookflow_start_time', $values['bookflow_start_time']);
        $item->add_meta_data('_bookflow_persons_total', $values['bookflow_persons_total']);
        $item->add_meta_data('_bookflow_resource_id', $values['bookflow_resource_id'] ?? '');
        $item->add_meta_data('_bookflow_location_tag', $values['bookflow_location_tag'] ?? '');
        if (isset($values['bookflow_terms_accepted'])) {
            $item->add_meta_data('_bookflow_terms_accepted', $values['bookflow_terms_accepted'] ? 'yes' : 'no');
            $item->add_meta_data('_bookflow_terms_accepted_at', $values['bookflow_terms_accepted_at'] ?? '');
        }
        if (!empty($values['bookflow_extras'])) {
            $item->add_meta_data(Bookflow_I18n::t('cart.extras'), implode(', ', wp_list_pluck($values['bookflow_extras'], 'title')));
            $item->add_meta_data('_bookflow_extras', wp_json_encode($values['bookflow_extras']));
        }
        $item->add_meta_data('_bookflow_customer_name', $values['bookflow_customer_name'] ?? '');
        $item->add_meta_data('_bookflow_customer_email', $values['bookflow_customer_email'] ?? '');
        $item->add_meta_data('_bookflow_customer_phone', $values['bookflow_customer_phone'] ?? '');
        $item->add_meta_data('_bookflow_notes', $values['bookflow_notes'] ?? '');
        $item->add_meta_data('_bookflow_schedule_id', $values['bookflow_schedule_id'] ?? '');
        $item->add_meta_data('_bookflow_tour_language', $values['bookflow_tour_language'] ?? '');

        if (!empty($values['bookflow_person_types'])) {
            $item->add_meta_data('_bookflow_person_types', $values['bookflow_person_types']);
        }
    }

    /**
     * Create booking record when order is placed
     */
    public function create_booking_on_order($order_id, $posted_data, $order) {
        foreach ($order->get_items() as $item) {
            $date = $item->get_meta('_bookflow_booking_date');
            if (empty($date)) {
                continue;
            }

            // Idempotency: if this line item already produced a booking, skip it.
            // Protects against duplicate `woocommerce_checkout_order_processed` / webhook replays.
            if ($item->get_meta('_bookflow_booking_id')) {
                continue;
            }

            $resource_id = $item->get_meta('_bookflow_resource_id');
            $schedule_id = $item->get_meta('_bookflow_schedule_id');
            $tour_lang   = $item->get_meta('_bookflow_tour_language');

            $booking_data = [
                'product_id'      => $item->get_product_id(),
                'resource_id'     => $resource_id ?: null,
                'schedule_id'     => $schedule_id ?: null,
                'order_id'        => $order_id,
                'customer_id'     => $order->get_customer_id(),
                'booking_date'    => $date,
                'start_time'      => $item->get_meta('_bookflow_start_time'),
                'persons_total'   => (int) $item->get_meta('_bookflow_persons_total'),
                'cost'            => (float) $item->get_total(),
                'status'          => 'pending',
                'customer_name'   => $item->get_meta('_bookflow_customer_name') ?: $order->get_formatted_billing_full_name(),
                'customer_email'  => $item->get_meta('_bookflow_customer_email') ?: $order->get_billing_email(),
                'customer_phone'  => $item->get_meta('_bookflow_customer_phone') ?: $order->get_billing_phone(),
                'customer_locale' => $tour_lang ?: ($order->get_meta('_locale') ?: get_locale()),
                'notes'           => $item->get_meta('_bookflow_notes'),
                'ip_address'      => $order->get_customer_ip_address(),
                'user_agent'      => $order->get_customer_user_agent(),
            ];

            $booking_id = Bookflow_Booking::create($booking_data);

            if (!is_wp_error($booking_id)) {
                $item->add_meta_data('_bookflow_booking_id', $booking_id);
                $item->save();

                // Save person type breakdown
                $person_types_data = $item->get_meta('_bookflow_person_types');
                if (!empty($person_types_data) && is_array($person_types_data)) {
                    Bookflow_Booking::save_person_types($booking_id, $person_types_data);
                }
            } else {
                // Don't fail silently: record on the order + log so staff can recover.
                $order->add_order_note(sprintf(
                    'Bookflow: failed to create booking for "%s" — %s',
                    $item->get_name(),
                    $booking_id->get_error_message()
                ));
                Bookflow_Logger::log('booking_creation_failed', 0, [
                    'order_id' => $order_id,
                    'item_id'  => $item->get_id(),
                    'error'    => $booking_id->get_error_message(),
                ]);
                do_action('bookflow_booking_creation_failed', $order_id, $item, $booking_id);
            }
        }
    }

    public function confirm_booking($order_id)  { $this->update_bookings_status($order_id, 'confirmed'); }
    public function paid_booking($order_id)     { $this->update_bookings_status($order_id, 'paid'); }
    public function cancel_booking($order_id)   { $this->update_bookings_status($order_id, 'cancelled'); }
    public function refund_booking($order_id)   { $this->update_bookings_status($order_id, 'refunded'); }

    private function update_bookings_status($order_id, $status) {
        $bookings = Bookflow_Booking::query(['order_id' => $order_id]);
        foreach ($bookings as $booking) {
            Bookflow_Booking::transition_status($booking->id, $status);
        }
    }
}

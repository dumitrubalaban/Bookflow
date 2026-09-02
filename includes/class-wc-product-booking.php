<?php
/**
 * WooCommerce Bookable Product Type
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Product_Booking extends WC_Product {

    /**
     * Per-cart-item computed price (date/time/persons/resource/extras),
     * set at runtime by Bookflow_Cart::set_booking_price(). get_price()
     * always reads the product's base post-meta otherwise, so a plain
     * WC_Product::set_price() call (the normal way plugins vary a cart
     * item's price) has no effect on this product type — it must go
     * through this instead.
     *
     * @var float|null
     */
    private $bookflow_dynamic_price = null;

    public function set_bookflow_dynamic_price($price) {
        $this->bookflow_dynamic_price = $price === null ? null : (float) $price;
    }

    public function get_type() {
        return 'booking';
    }

    public function is_virtual() {
        return true;
    }

    public function is_sold_individually() {
        return true;
    }

    public function get_base_price() {
        return (float) get_post_meta($this->get_id(), '_bookflow_base_price', true);
    }

    public function get_price($context = 'view') {
        if ($this->bookflow_dynamic_price !== null) {
            return $this->bookflow_dynamic_price;
        }

        $price = get_post_meta($this->get_id(), '_bookflow_base_price', true);
        if ($price && (float) $price > 0) {
            return (float) $price;
        }

        // Fall back to lowest person type cost for display/sorting
        $person_types = Bookflow_Person_Types::get_for_product($this->get_id());
        if (!empty($person_types)) {
            $costs = array_filter(array_map(function ($pt) {
                return isset($pt->cost) ? (float) $pt->cost : 0;
            }, $person_types));
            if (!empty($costs)) {
                return min($costs);
            }
        }

        return 0;
    }

    public function get_duration() {
        return (int) (get_post_meta($this->get_id(), '_bookflow_duration', true) ?: 60);
    }

    public function get_min_persons() {
        return (int) (get_post_meta($this->get_id(), '_bookflow_min_persons', true) ?: 1);
    }

    public function get_max_persons() {
        return (int) (get_post_meta($this->get_id(), '_bookflow_max_persons', true) ?: 20);
    }

    public function get_time_slots() {
        $slots = get_post_meta($this->get_id(), '_bookflow_time_slots', true);
        if (empty($slots)) {
            return [];
        }
        if (is_array($slots)) {
            return array_filter(array_map('trim', $slots));
        }
        return array_filter(array_map('trim', explode("\n", $slots)));
    }

    public function get_available_days() {
        $days = get_post_meta($this->get_id(), '_bookflow_available_days', true);
        return is_array($days) ? $days : ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    }

    public function get_pricing_rules() {
        $rules = get_post_meta($this->get_id(), '_bookflow_pricing_rules', true);
        return is_array($rules) ? $rules : [];
    }

    public function get_blocked_dates() {
        $dates = get_post_meta($this->get_id(), '_bookflow_blocked_dates', true);
        if (empty($dates)) {
            return [];
        }
        return array_filter(array_map('trim', explode("\n", $dates)));
    }

    public function get_buffer_time() {
        return (int) (get_post_meta($this->get_id(), '_bookflow_buffer_time', true) ?: 0);
    }

    public function get_max_bookings_per_slot() {
        return (int) (get_post_meta($this->get_id(), '_bookflow_max_bookings_per_slot', true) ?: 1);
    }

    public function get_cancel_before_hours() {
        return (int) (get_post_meta($this->get_id(), '_bookflow_cancel_before_hours', true) ?: 24);
    }

    public function get_min_advance() {
        return (int) (get_post_meta($this->get_id(), '_bookflow_min_advance', true) ?: 0);
    }

    public function get_max_advance() {
        return (int) (get_post_meta($this->get_id(), '_bookflow_max_advance', true) ?: 365);
    }

    /**
     * Percent of the total charged at checkout; the rest is collected later
     * (cash/card on-site). 0 = full payment required (default, unchanged
     * behavior for products that never set this).
     */
    public function get_deposit_percent() {
        $percent = (float) (get_post_meta($this->get_id(), '_bookflow_deposit_percent', true) ?: 0);
        return max(0, min(100, $percent));
    }

    public function has_resources() {
        $resources = Bookflow_Resources::get_for_product($this->get_id());
        return !empty($resources);
    }

    public function has_person_types() {
        return Bookflow_Person_Types::product_has_types($this->get_id());
    }

    public function has_schedules() {
        return Bookflow_Schedules::product_has_schedules($this->get_id());
    }

    public function is_purchasable() {
        return true;
    }

    public function add_to_cart_text() {
        return Bookflow_I18n::t('calendar.book_now');
    }

    public function single_add_to_cart_text() {
        return Bookflow_I18n::t('calendar.book_now');
    }
}

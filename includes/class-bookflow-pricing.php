<?php
/**
 * Enterprise Pricing Engine
 * Supports: base price, time-based, date-based, day-of-week, person-type, resource cost, seasonal
 *
 * Rule types: fixed (override), percentage (multiply), discount (subtract), surcharge (add)
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Pricing {

    /**
     * Get effective price for a specific slot
     */
    public static function get_price_for_slot($product_id, $date, $start_time, $person_type_id = null) {
        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'booking') {
            return 0;
        }

        // Start with base price
        $base_price = $product->get_base_price();

        // If person type pricing is enabled and type is specified
        if ($person_type_id && Bookflow_Person_Types::product_has_types($product_id)) {
            $pt = Bookflow_Person_Types::get($person_type_id);
            if ($pt) {
                $base_price = (float) $pt->cost;
            }
        }

        // Apply legacy time-based pricing rules (from post meta)
        $legacy_rules = $product->get_pricing_rules();
        foreach ($legacy_rules as $rule) {
            if (empty($rule['from']) || empty($rule['to']) || empty($rule['price'])) {
                continue;
            }
            if ($start_time >= $rule['from'] && $start_time < $rule['to']) {
                $base_price = (float) $rule['price'];
                break;
            }
        }

        // Apply DB-based pricing rules (more powerful)
        $base_price = self::apply_rules($product_id, $base_price, $date, $start_time);

        return apply_filters('bookflow_price_for_slot', max(0, $base_price), $product_id, $date, $start_time, $person_type_id);
    }

    /**
     * Apply pricing rules from database
     */
    private static function apply_rules($product_id, $base_price, $date, $start_time) {
        global $wpdb;

        $rules = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bookflow_pricing_rules
             WHERE product_id = %d
             ORDER BY priority DESC, sort_order ASC",
            $product_id
        ));

        if (empty($rules)) {
            return $base_price;
        }

        $day_name = strtolower(gmdate('l', strtotime($date)));
        $price = $base_price;

        foreach ($rules as $rule) {
            if (!self::rule_matches($rule, $date, $start_time, $day_name)) {
                continue;
            }

            $amount = (float) $rule->modifier_amount;

            switch ($rule->modifier_type) {
                case 'fixed':
                    $price = $amount;
                    break;
                case 'percentage':
                    $price = $base_price * ($amount / 100);
                    break;
                case 'surcharge':
                    $price = $base_price + $amount;
                    break;
                case 'discount':
                    $price = $base_price - $amount;
                    break;
            }
        }

        return $price;
    }

    /**
     * Check if a pricing rule matches the given date/time
     */
    private static function rule_matches($rule, $date, $start_time, $day_name) {
        switch ($rule->rule_type) {
            case 'time_range':
                if (!empty($rule->from_time) && !empty($rule->to_time)) {
                    if ($start_time < $rule->from_time || $start_time >= $rule->to_time) {
                        return false;
                    }
                }
                // Also check date range if set
                if (!empty($rule->from_date) && $date < $rule->from_date) return false;
                if (!empty($rule->to_date) && $date > $rule->to_date) return false;
                return true;

            case 'date_range':
                if (!empty($rule->from_date) && $date < $rule->from_date) return false;
                if (!empty($rule->to_date) && $date > $rule->to_date) return false;
                return true;

            case 'day_of_week':
                if (!empty($rule->day_of_week)) {
                    $days = array_map('trim', explode(',', $rule->day_of_week));
                    return in_array($day_name, $days);
                }
                return false;

            case 'specific_date':
                return $date === $rule->from_date;

            default:
                return false;
        }
    }

    /**
     * Calculate total cost for a booking
     */
    public static function calculate_total($product_id, $date, $start_time, $persons_data, $resource_id = null, $extra_ids = null) {
        $total = 0;

        // Check if using person types
        if (is_array($persons_data) && isset($persons_data[0]['person_type_id'])) {
            // Person types mode
            foreach ($persons_data as $pt) {
                $qty = max(0, (int) ($pt['quantity'] ?? 0));
                if ($qty <= 0) continue;

                $price = self::get_price_for_slot($product_id, $date, $start_time, $pt['person_type_id']);
                $total += $price * $qty;
            }
        } else {
            // Simple persons count mode
            $count = is_numeric($persons_data) ? max(1, (int) $persons_data) : 1;
            $price = self::get_price_for_slot($product_id, $date, $start_time);
            $total = $price * $count;
        }

        // Add resource cost
        if ($resource_id) {
            global $wpdb;
            $resource_cost = $wpdb->get_var($wpdb->prepare(
                "SELECT base_cost FROM {$wpdb->prefix}bookflow_product_resources WHERE product_id = %d AND resource_id = %d",
                $product_id,
                $resource_id
            ));
            if ($resource_cost) {
                $total += (float) $resource_cost;
            }
        }

        // Add selected extras (flat, once per booking — not per person)
        if (!empty($extra_ids)) {
            foreach (Bookflow_Extras::get_many($extra_ids) as $extra) {
                $total += (float) $extra->price;
            }
        }

        return apply_filters('bookflow_booking_total', round($total, 2), $product_id, $date, $start_time, $persons_data, $resource_id);
    }

    /**
     * Get price range for a product (min - max across all rules and person types)
     */
    public static function get_price_range($product_id) {
        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'booking') {
            return ['min' => 0, 'max' => 0];
        }

        $prices = [$product->get_base_price()];

        // Legacy rules
        foreach ($product->get_pricing_rules() as $rule) {
            if (!empty($rule['price'])) {
                $prices[] = (float) $rule['price'];
            }
        }

        // Person types
        $person_types = Bookflow_Person_Types::get_for_product($product_id);
        foreach ($person_types as $pt) {
            if ($pt->cost > 0) {
                $prices[] = (float) $pt->cost;
            }
        }

        // DB pricing rules (fixed only)
        global $wpdb;
        $fixed_rules = $wpdb->get_col($wpdb->prepare(
            "SELECT modifier_amount FROM {$wpdb->prefix}bookflow_pricing_rules WHERE product_id = %d AND modifier_type = 'fixed' AND modifier_amount > 0",
            $product_id
        ));
        foreach ($fixed_rules as $amount) {
            $prices[] = (float) $amount;
        }

        $prices = array_filter($prices, function ($p) { return $p > 0; });

        return [
            'min' => !empty($prices) ? min($prices) : 0,
            'max' => !empty($prices) ? max($prices) : 0,
        ];
    }

    /**
     * Format price for display
     */
    public static function format_price($price) {
        return wc_price($price);
    }
}

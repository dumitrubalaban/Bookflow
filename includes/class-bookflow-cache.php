<?php
/**
 * Caching Layer for Availability Calculations
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Cache {

    const GROUP = 'bookflow_booking';
    const TTL = 300; // 5 minutes

    public static function init() {
        // Invalidate cache when bookings change
        add_action('bookflow_booking_created', [__CLASS__, 'invalidate_product_cache'], 10, 1);
        add_action('bookflow_booking_status_changed', [__CLASS__, 'invalidate_booking_cache'], 10, 1);
        add_action('woocommerce_process_product_meta', [__CLASS__, 'invalidate_product_cache_by_id']);
    }

    /**
     * Get cached value
     */
    public static function get($key) {
        return wp_cache_get($key, self::GROUP);
    }

    /**
     * Set cached value
     */
    public static function set($key, $value, $ttl = null) {
        wp_cache_set($key, $value, self::GROUP, $ttl ?? self::TTL);
    }

    /**
     * Delete cached value
     */
    public static function delete($key) {
        wp_cache_delete($key, self::GROUP);
    }

    /**
     * Get or compute a cached value
     */
    public static function remember($key, $callback, $ttl = null) {
        $value = self::get($key);
        if ($value !== false) {
            return $value;
        }
        $value = $callback();
        self::set($key, $value, $ttl);
        return $value;
    }

    /**
     * Build cache key for month availability
     */
    public static function month_key($product_id, $year, $month) {
        return "month_{$product_id}_{$year}_{$month}";
    }

    /**
     * Build cache key for day slots
     */
    public static function slots_key($product_id, $date) {
        return "slots_{$product_id}_{$date}";
    }

    /**
     * Invalidate all caches for a product when a booking is created
     */
    public static function invalidate_product_cache($booking_data) {
        $product_id = is_array($booking_data) ? ($booking_data['product_id'] ?? 0) : 0;
        if ($product_id) {
            $schedule_id = is_array($booking_data) ? ($booking_data['schedule_id'] ?? null) : null;
            $resource_id = is_array($booking_data) ? ($booking_data['resource_id'] ?? null) : null;
            self::invalidate_product_cache_by_id($product_id, $schedule_id, $resource_id);
        }
    }

    /**
     * Invalidate product-level caches including schedule-scoped and resource-scoped variants
     *
     * @param int      $product_id
     * @param int|null $known_schedule_id  If provided, ensures this schedule's keys are cleared even
     *                                     if get_for_product doesn't return it (e.g., just deactivated).
     * @param int|null $known_resource_id  Same for resource.
     */
    public static function invalidate_product_cache_by_id($product_id, $known_schedule_id = null, $known_resource_id = null) {
        // Gather schedule IDs for this product
        $schedule_ids = [];
        if (class_exists('Bookflow_Schedules')) {
            $schedules = Bookflow_Schedules::get_for_product($product_id);
            if ($schedules) {
                foreach ($schedules as $schedule) {
                    $schedule_ids[] = (int) $schedule->id;
                }
            }
        }
        if ($known_schedule_id && !in_array((int) $known_schedule_id, $schedule_ids, true)) {
            $schedule_ids[] = (int) $known_schedule_id;
        }

        // Gather resource IDs for this product
        $resource_ids = [];
        if (class_exists('Bookflow_Resources')) {
            $resources = Bookflow_Resources::get_for_product($product_id);
            if ($resources) {
                foreach ($resources as $resource) {
                    $resource_ids[] = (int) $resource->id;
                }
            }
        }
        if ($known_resource_id && !in_array((int) $known_resource_id, $resource_ids, true)) {
            $resource_ids[] = (int) $known_resource_id;
        }

        // Build all suffix combinations: base, _r{id}, _s{id}, _r{id}_s{id}
        $suffixes = [''];
        foreach ($schedule_ids as $sid) {
            $suffixes[] = "_s{$sid}";
        }
        foreach ($resource_ids as $rid) {
            $suffixes[] = "_r{$rid}";
            foreach ($schedule_ids as $sid) {
                $suffixes[] = "_r{$rid}_s{$sid}";
            }
        }

        // Clear month caches for current and next 12 months
        $year = (int) gmdate('Y');
        $month = (int) gmdate('m');
        for ($i = 0; $i < 13; $i++) {
            $m = $month + $i;
            $y = $year;
            if ($m > 12) { $m -= 12; $y++; }
            $base_month_key = self::month_key($product_id, $y, $m);
            foreach ($suffixes as $suffix) {
                self::delete($base_month_key . $suffix);
            }
        }
        // Clear today and next 30 days slots
        for ($i = 0; $i < 31; $i++) {
            $date = gmdate('Y-m-d', strtotime("+{$i} days"));
            $base_slots_key = self::slots_key($product_id, $date);
            foreach ($suffixes as $suffix) {
                self::delete($base_slots_key . $suffix);
            }
        }
    }

    /**
     * Invalidate cache when booking status changes
     */
    public static function invalidate_booking_cache($booking_id) {
        $booking = Bookflow_Booking::get($booking_id);
        if ($booking) {
            self::invalidate_product_cache_by_id($booking->product_id);
        }
    }
}

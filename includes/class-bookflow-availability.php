<?php
/**
 * Availability Engine
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Availability {

    /**
     * Check if a date is available for booking
     *
     * @param int      $product_id
     * @param string   $date        Y-m-d
     * @param int|null $schedule_id Optional schedule variant (language, etc.)
     */
    public static function is_date_available($product_id, $date, $schedule_id = null) {
        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'booking') {
            return false;
        }

        // Past date check
        $today = wp_date('Y-m-d');
        if ($date < $today) {
            return false;
        }

        // Day of week check — use schedule days if provided, else product defaults
        $day_of_week = strtolower((new DateTimeImmutable($date))->format('l'));

        if ($schedule_id) {
            $schedule = Bookflow_Schedules::get($schedule_id);
            if (!$schedule || $schedule->status !== 'active') {
                return false;
            }
            $available_days = json_decode($schedule->available_days, true);
            if (empty($available_days) || !is_array($available_days)) {
                return false;
            }
        } else {
            $available_days = $product->get_available_days();
        }

        if (!in_array($day_of_week, $available_days)) {
            return false;
        }

        // Blocked dates
        $blocked = $product->get_blocked_dates();
        if (in_array($date, $blocked)) {
            return false;
        }

        // Date range restrictions
        $from = get_post_meta($product_id, '_bookflow_date_range_from', true);
        $to = get_post_meta($product_id, '_bookflow_date_range_to', true);
        if (!empty($from) && $date < $from) return false;
        if (!empty($to) && $date > $to) return false;

        // Min/max advance booking
        $diff = (strtotime($date) - strtotime($today)) / DAY_IN_SECONDS;
        $min_advance = (int) (get_post_meta($product_id, '_bookflow_min_advance', true) ?: 0);
        $max_advance = (int) (get_post_meta($product_id, '_bookflow_max_advance', true) ?: 365);
        if ($diff < $min_advance || $diff > $max_advance) {
            return false;
        }

        // Check custom availability rules from DB
        if (!self::check_availability_rules($product_id, $date)) {
            return false;
        }

        // Location: the product must ALSO satisfy its assigned location's
        // own working days/blocked dates/holidays, if any.
        $location_id = (int) get_post_meta($product_id, '_bookflow_location_id', true);
        if ($location_id && !self::is_date_available_for_location($location_id, $date, $day_of_week)) {
            return false;
        }

        return apply_filters('bookflow_is_date_available', true, $product_id, $date, $schedule_id);
    }

    /**
     * Whether a date is open at the given location: its own working days
     * (only enforced if the location defines any — an empty list means "no
     * restriction, defer entirely to the product"), blocked dates, and
     * holidays.
     */
    private static function is_date_available_for_location($location_id, $date, $day_of_week) {
        $location = Bookflow_Locations::get($location_id);
        if (!$location || $location->status !== 'active') {
            return false;
        }

        $available_days = json_decode($location->available_days, true);
        if (!empty($available_days) && is_array($available_days) && !in_array($day_of_week, $available_days, true)) {
            return false;
        }

        $blocked = (array) json_decode($location->blocked_dates, true);
        if (in_array($date, $blocked, true)) {
            return false;
        }

        $holidays = (array) json_decode($location->holidays, true);
        if (in_array($date, $holidays, true)) {
            return false;
        }

        return true;
    }

    /**
     * Check custom availability rules
     */
    private static function check_availability_rules($product_id, $date, $resource_id = null) {
        global $wpdb;

        $rules = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bookflow_availability_rules
             WHERE product_id = %d AND (resource_id IS NULL OR resource_id = %d)
             ORDER BY priority DESC, sort_order ASC",
            $product_id,
            $resource_id ?: 0
        ));

        if (empty($rules)) {
            return true; // No rules = available
        }

        $day_name = strtolower((new DateTimeImmutable($date))->format('l'));
        $is_available = true;

        foreach ($rules as $rule) {
            $matches = false;

            switch ($rule->rule_type) {
                case 'date_range':
                    $matches = ($date >= $rule->from_date && $date <= $rule->to_date);
                    break;

                case 'specific_date':
                    $matches = ($date === $rule->from_date);
                    break;

                case 'day_of_week':
                    $days = array_map('trim', explode(',', $rule->day_of_week));
                    $matches = in_array($day_name, $days);
                    break;

                case 'time_range':
                    // Time rules are checked at slot level, not date level
                    continue 2;
            }

            if ($matches) {
                $is_available = (bool) $rule->bookable;
            }
        }

        return $is_available;
    }

    /**
     * Check if a specific time slot is available
     *
     * @param int      $product_id
     * @param string   $date
     * @param string   $start_time
     * @param int|null $resource_id
     * @param int|null $schedule_id
     */
    public static function is_slot_available($product_id, $date, $start_time, $resource_id = null, $schedule_id = null) {
        $status = self::get_slot_status($product_id, $date, $start_time, $resource_id, $schedule_id);
        if (!$status['structurally_offered']) {
            return false;
        }

        if ($status['current_count'] >= $status['max_per_slot']) {
            return false;
        }
        if ($status['booked_persons'] >= $status['max_persons']) {
            return false;
        }

        return apply_filters('bookflow_is_slot_available', true, $product_id, $date, $start_time, $resource_id, $schedule_id);
    }

    /**
     * Compute whether a slot is structurally offered (in schedule, passes time
     * rules, resource available, not past) separately from its capacity numbers,
     * so callers can distinguish "not offered at all" from "offered but full".
     *
     * @return array{structurally_offered:bool,max_per_slot:int,max_persons:int,current_count:int,booked_persons:int}
     */
    private static function get_slot_status($product_id, $date, $start_time, $resource_id = null, $schedule_id = null) {
        $result = [
            'structurally_offered' => false,
            'max_per_slot'         => 0,
            'max_persons'          => 0,
            'current_count'        => 0,
            'booked_persons'       => 0,
        ];

        if (!self::is_date_available($product_id, $date, $schedule_id)) {
            return $result;
        }

        $product = wc_get_product($product_id);
        $schedule = $schedule_id ? Bookflow_Schedules::get($schedule_id) : null;

        // If schedule_id given, verify this time slot belongs to the schedule
        if ($schedule_id) {
            if ($schedule) {
                $schedule_slots = json_decode($schedule->time_slots, true);
                if (!empty($schedule_slots) && !in_array($start_time, $schedule_slots)) {
                    return $result;
                }
            }
        } else {
            // Product-level: verify the time is one of the product's configured slots
            $raw = get_post_meta($product_id, '_bookflow_time_slots', true);
            $product_slots = array_filter(array_map('trim', preg_split('/[\r\n]+/', (string) $raw)));
            if (!empty($product_slots) && !in_array($start_time, $product_slots, true)) {
                return $result;
            }
        }

        // Check time-based availability rules
        if (!self::check_time_availability_rules($product_id, $date, $start_time, $resource_id)) {
            return $result;
        }

        // Resource existence/active check only — capacity is deliberately
        // NOT checked here (that's what Bookflow_Resources::is_resource_available()
        // is for, and other callers like the "choose your guide" step still
        // want that full has-room check). A resource merely being at
        // capacity must still fall through to the booked_persons/max_persons
        // comparison below so a fully-booked resource slot comes back
        // structurally offered (and thus visible as "sold out", with a
        // waitlist option) instead of silently vanishing from the slot list.
        $resource = null;
        if ($resource_id) {
            $resource = Bookflow_Resources::get($resource_id);
            if (!$resource || $resource->status !== 'active') {
                return $result;
            }
        }

        $buffer = (int) (get_post_meta($product_id, '_bookflow_buffer_time', true) ?: 0);

        // Check if today and slot is in the past (leading buffer)
        $today = wp_date('Y-m-d');
        if ($date === $today) {
            // Compute "now + buffer" entirely in the site timezone to avoid UTC drift
            $buffered_time = current_datetime()->modify("+{$buffer} minutes")->format('H:i');
            if ($start_time <= $buffered_time) {
                return $result;
            }
        }

        // Trailing buffer: block a slot that starts too soon after another
        // booking (same product/resource/date) ends, so guides get cleanup
        // or travel time between bookings.
        if (Bookflow_Booking::has_conflicting_trailing_booking($product_id, $date, $start_time, $buffer, $resource_id, $schedule_id)) {
            return $result;
        }

        // Determine capacity limits — schedule can override product defaults
        $max_per_slot = $product->get_max_bookings_per_slot();
        $max_persons = $product->get_max_persons();

        if ($schedule) {
            if ($schedule->max_bookings_per_slot > 0) {
                $max_per_slot = (int) $schedule->max_bookings_per_slot;
            }
            if ($schedule->max_persons > 0) {
                $max_persons = (int) $schedule->max_persons;
            }
        }

        // A resource can't host more people than its own capacity, even if
        // the product/schedule would otherwise allow more.
        if ($resource) {
            $max_persons = min($max_persons, (int) $resource->capacity);
        }

        $result['structurally_offered'] = true;
        $result['max_per_slot'] = $max_per_slot;
        $result['max_persons'] = $max_persons;
        $result['current_count'] = Bookflow_Booking::count_for_slot($product_id, $date, $start_time, $resource_id, $schedule_id);
        $result['booked_persons'] = Bookflow_Booking::persons_for_slot($product_id, $date, $start_time, $resource_id, $schedule_id);

        return $result;
    }

    /**
     * Check time-based availability rules
     */
    private static function check_time_availability_rules($product_id, $date, $start_time, $resource_id = null) {
        global $wpdb;

        $rules = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bookflow_availability_rules
             WHERE product_id = %d AND rule_type = 'time_range'
             AND (resource_id IS NULL OR resource_id = %d)
             ORDER BY priority DESC",
            $product_id,
            $resource_id ?: 0
        ));

        foreach ($rules as $rule) {
            // Check if date matches (if date range specified)
            if (!empty($rule->from_date) && $date < $rule->from_date) continue;
            if (!empty($rule->to_date) && $date > $rule->to_date) continue;

            // Check day of week
            if (!empty($rule->day_of_week)) {
                $day_name = strtolower((new DateTimeImmutable($date))->format('l'));
                $days = array_map('trim', explode(',', $rule->day_of_week));
                if (!in_array($day_name, $days)) continue;
            }

            // Check time range
            if ($start_time >= $rule->from_time && $start_time < $rule->to_time) {
                return (bool) $rule->bookable;
            }
        }

        return true;
    }

    /**
     * Get available slots for a date
     *
     * @param int      $product_id
     * @param string   $date
     * @param int|null $resource_id
     * @param int|null $schedule_id
     */
    public static function get_available_slots($product_id, $date, $resource_id = null, $schedule_id = null) {
        $cache_key = Bookflow_Cache::slots_key($product_id, $date)
            . ($resource_id ? "_r{$resource_id}" : '')
            . ($schedule_id ? "_s{$schedule_id}" : '');

        return Bookflow_Cache::remember($cache_key, function () use ($product_id, $date, $resource_id, $schedule_id) {
            return self::compute_available_slots($product_id, $date, $resource_id, $schedule_id);
        });
    }

    /**
     * Compute available slots (uncached)
     */
    private static function compute_available_slots($product_id, $date, $resource_id = null, $schedule_id = null) {
        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'booking') {
            return [];
        }

        if (!self::is_date_available($product_id, $date, $schedule_id)) {
            return [];
        }

        // Time slots: use schedule's slots if provided, else product defaults
        if ($schedule_id) {
            $schedule = Bookflow_Schedules::get($schedule_id);
            if ($schedule) {
                $all_slots = json_decode($schedule->time_slots, true);
                if (empty($all_slots) || !is_array($all_slots)) {
                    $all_slots = $product->get_time_slots();
                }
                $max_per_slot = $schedule->max_bookings_per_slot > 0
                    ? (int) $schedule->max_bookings_per_slot
                    : $product->get_max_bookings_per_slot();
                $max_persons = $schedule->max_persons > 0
                    ? (int) $schedule->max_persons
                    : $product->get_max_persons();
                $price_modifier = (float) $schedule->price_modifier;
            } else {
                $all_slots = $product->get_time_slots();
                $max_per_slot = $product->get_max_bookings_per_slot();
                $max_persons = $product->get_max_persons();
                $price_modifier = 0;
            }
        } else {
            $all_slots = $product->get_time_slots();
            $max_per_slot = $product->get_max_bookings_per_slot();
            $max_persons = $product->get_max_persons();
            $price_modifier = 0;
        }

        $available = [];

        foreach ($all_slots as $slot) {
            $status = self::get_slot_status($product_id, $date, $slot, $resource_id, $schedule_id);
            if (!$status['structurally_offered']) {
                continue;
            }

            $booked_persons = $status['booked_persons'];
            $remaining_persons = max(0, $status['max_persons'] - $booked_persons);
            $booking_count = $status['current_count'];
            $remaining_bookings = max(0, $status['max_per_slot'] - $booking_count);
            $is_full = ($booking_count >= $status['max_per_slot']) || ($booked_persons >= $status['max_persons']);

            $base_price = Bookflow_Pricing::get_price_for_slot($product_id, $date, $slot);

            $slot_data = [
                'time'               => $slot,
                'available'          => $remaining_persons,
                'available_bookings' => $remaining_bookings,
                'booked_persons'     => $booked_persons,
                'max_persons'        => $status['max_persons'],
                'price'              => $base_price + $price_modifier,
                'is_full'            => $is_full,
            ];

            // Include available resources if product has resources
            $resources = Bookflow_Resources::get_for_product($product_id);
            if (!empty($resources) && !$resource_id) {
                $slot_data['resources'] = [];
                foreach ($resources as $res) {
                    if (Bookflow_Resources::is_resource_available($res->id, $product_id, $date, $slot)) {
                        $slot_data['resources'][] = [
                            'id'    => (int) $res->id,
                            'title' => $res->title,
                            'cost'  => (float) $res->base_cost,
                        ];
                    }
                }
            }

            $available[] = $slot_data;
        }

        return $available;
    }

    /**
     * Get availability calendar data for a month
     *
     * @param int      $product_id
     * @param int      $year
     * @param int      $month
     * @param int|null $schedule_id
     */
    public static function get_month_availability($product_id, $year, $month, $schedule_id = null) {
        $cache_key = Bookflow_Cache::month_key($product_id, $year, $month)
            . ($schedule_id ? "_s{$schedule_id}" : '');

        return Bookflow_Cache::remember($cache_key, function () use ($product_id, $year, $month, $schedule_id) {
            return self::compute_month_availability($product_id, $year, $month, $schedule_id);
        });
    }

    /**
     * Compute month availability (uncached)
     */
    private static function compute_month_availability($product_id, $year, $month, $schedule_id = null) {
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $calendar = [];

        for ($day = 1; $day <= $days_in_month; $day++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $available = self::is_date_available($product_id, $date, $schedule_id);

            $slots_count = 0;
            if ($available) {
                $slots = self::compute_available_slots($product_id, $date, null, $schedule_id);
                $slots_count = count(array_filter($slots, function ($s) { return empty($s['is_full']); }));
                $available = $slots_count > 0;
            }

            $calendar[$date] = [
                'date'      => $date,
                'available' => $available,
                'slots'     => $slots_count,
            ];
        }

        return $calendar;
    }

    /**
     * Get next available date from today
     */
    public static function get_next_available_date($product_id, $from_date = null, $schedule_id = null) {
        $from = $from_date ?: wp_date('Y-m-d');
        $max_advance = (int) (get_post_meta($product_id, '_bookflow_max_advance', true) ?: 365);

        for ($i = 0; $i <= $max_advance; $i++) {
            $date = gmdate('Y-m-d', strtotime($from . " +{$i} days"));
            if (self::is_date_available($product_id, $date, $schedule_id)) {
                $slots = self::get_available_slots($product_id, $date, null, $schedule_id);
                if (!empty($slots)) {
                    return $date;
                }
            }
        }

        return null;
    }
}

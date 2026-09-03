<?php
/**
 * AJAX Handlers
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Ajax {

    public function __construct() {
        $actions = [
            'bookflow_get_available_slots',
            'bookflow_get_month_availability',
            'bookflow_calculate_price',
            'bookflow_get_resources_for_slot',
            'bookflow_get_resources_for_date',
            'bookflow_get_options_for_date',
        ];

        foreach ($actions as $action) {
            add_action("wp_ajax_{$action}", [$this, str_replace('bookflow_', '', $action)]);
            add_action("wp_ajax_nopriv_{$action}", [$this, str_replace('bookflow_', '', $action)]);
        }
    }

    public function get_available_slots() {
        check_ajax_referer('bookflow_nonce', 'nonce');

        if (!Bookflow_Rate_Limit::check('slots', 30, 60)) {
            wp_send_json_error(['message' => Bookflow_I18n::t('error.rate_limited')], 429);
        }

        $product_id  = absint($_POST['product_id'] ?? 0);
        $date        = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        $resource_id = absint($_POST['resource_id'] ?? 0) ?: null;
        $schedule_id = absint($_POST['schedule_id'] ?? 0) ?: null;

        if (!$product_id || !$date) {
            wp_send_json_error(['message' => Bookflow_I18n::t('error.invalid_request')]);
        }

        $slots = Bookflow_Availability::get_available_slots($product_id, $date, $resource_id, $schedule_id);

        wp_send_json_success([
            'slots' => $slots,
            'date'  => $date,
        ]);
    }

    public function get_month_availability() {
        check_ajax_referer('bookflow_nonce', 'nonce');

        if (!Bookflow_Rate_Limit::check('month', 30, 60)) {
            wp_send_json_error(['message' => Bookflow_I18n::t('error.rate_limited')], 429);
        }

        $product_id  = absint($_POST['product_id'] ?? 0);
        $year        = absint($_POST['year'] ?? gmdate('Y'));
        $month       = absint($_POST['month'] ?? gmdate('m'));
        $schedule_id = absint($_POST['schedule_id'] ?? 0) ?: null;

        if (!$product_id) {
            wp_send_json_error(['message' => Bookflow_I18n::t('error.invalid_request')]);
        }

        wp_send_json_success([
            'calendar' => Bookflow_Availability::get_month_availability($product_id, $year, $month, $schedule_id),
            'year'     => $year,
            'month'    => $month,
        ]);
    }

    public function calculate_price() {
        check_ajax_referer('bookflow_nonce', 'nonce');

        if (!Bookflow_Rate_Limit::check('price', 60, 60)) {
            wp_send_json_error(['message' => Bookflow_I18n::t('error.rate_limited')], 429);
        }

        $product_id  = absint($_POST['product_id'] ?? 0);
        $date        = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        $start_time  = sanitize_text_field(wp_unslash($_POST['start_time'] ?? ''));
        $resource_id = absint($_POST['resource_id'] ?? 0) ?: null;
        $extra_ids   = isset($_POST['extras']) && is_array($_POST['extras']) ? array_map('absint', wp_unslash($_POST['extras'])) : null;

        if (!$product_id || !$date || !$start_time) {
            wp_send_json_error(['message' => Bookflow_I18n::t('error.invalid_request')]);
        }

        // Handle person types or simple count
        $person_types_raw = isset($_POST['person_types']) && is_array($_POST['person_types'])
            ? wp_unslash($_POST['person_types']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every element ('person_type_id', 'quantity') is sanitized with absint() in the loop below before use
            : null;
        if ($person_types_raw && is_array($person_types_raw)) {
            $persons_data = [];
            $total_persons = 0;
            foreach ($person_types_raw as $pt) {
                $qty = absint($pt['quantity'] ?? 0);
                if ($qty > 0) {
                    $persons_data[] = [
                        'person_type_id' => absint($pt['person_type_id']),
                        'quantity'       => $qty,
                    ];
                    $total_persons += $qty;
                }
            }
            $total = Bookflow_Pricing::calculate_total($product_id, $date, $start_time, $persons_data, $resource_id, $extra_ids);
        } else {
            $persons = absint($_POST['persons'] ?? 1);
            $total_persons = $persons;
            $total = Bookflow_Pricing::calculate_total($product_id, $date, $start_time, $persons, $resource_id, $extra_ids);
        }

        $price_per_person = Bookflow_Pricing::get_price_for_slot($product_id, $date, $start_time);

        $product = wc_get_product($product_id);
        $deposit_percent = $product ? $product->get_deposit_percent() : 0;
        $deposit_amount = null;
        if ($deposit_percent > 0 && $deposit_percent < 100) {
            $deposit_amount = round($total * $deposit_percent / 100, 2);
        }

        wp_send_json_success([
            'price_per_person'           => $price_per_person,
            'price_per_person_formatted' => Bookflow_Pricing::format_price($price_per_person),
            'total'                      => $total,
            'total_formatted'            => Bookflow_Pricing::format_price($total),
            'persons'                    => $total_persons,
            'deposit_amount'             => $deposit_amount,
            'deposit_amount_formatted'   => $deposit_amount !== null ? Bookflow_Pricing::format_price($deposit_amount) : null,
            'balance_due_formatted'      => $deposit_amount !== null ? Bookflow_Pricing::format_price($total - $deposit_amount) : null,
        ]);
    }

    /**
     * Get available options (languages, etc.) for a date
     * User picks a date first, then sees which options are available
     */
    public function get_options_for_date() {
        check_ajax_referer('bookflow_nonce', 'nonce');

        if (!Bookflow_Rate_Limit::check('options', 30, 60)) {
            wp_send_json_error(['message' => Bookflow_I18n::t('error.rate_limited')], 429);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        $date       = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));

        if (!$product_id || !$date) {
            wp_send_json_error(['message' => Bookflow_I18n::t('error.invalid_request')]);
        }

        $schedules = Bookflow_Schedules::get_for_product($product_id);
        $day_name = strtolower(gmdate('l', strtotime($date)));
        $options = [];

        foreach ($schedules as $sched) {
            $days = json_decode($sched->available_days, true);
            if (!is_array($days) || !in_array($day_name, $days)) {
                continue;
            }

            $time_slots = json_decode($sched->time_slots, true) ?: [];
            $slots_data = [];

            foreach ($time_slots as $slot_time) {
                $max_p = $sched->max_persons > 0 ? (int) $sched->max_persons : 20;
                $booked = Bookflow_Booking::persons_for_slot($product_id, $date, $slot_time, null, $sched->id);
                $remaining = max(0, $max_p - $booked);
                if ($remaining <= 0) continue;

                $slots_data[] = [
                    'time'      => $slot_time,
                    'available' => $remaining,
                    'price'     => Bookflow_Pricing::get_price_for_slot($product_id, $date, $slot_time) + (float) $sched->price_modifier,
                ];
            }

            if (empty($slots_data)) continue;

            $options[] = [
                'id'           => (int) $sched->id,
                'option_group' => $sched->option_group,
                'option_label' => $sched->option_label,
                'option_value' => $sched->option_value,
                'slots'        => $slots_data,
            ];
        }

        wp_send_json_success(['options' => $options, 'date' => $date]);
    }

    public function get_resources_for_slot() {
        check_ajax_referer('bookflow_nonce', 'nonce');

        if (!Bookflow_Rate_Limit::check('resources', 30, 60)) {
            wp_send_json_error(['message' => Bookflow_I18n::t('error.rate_limited')], 429);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        $date       = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        $start_time = sanitize_text_field(wp_unslash($_POST['start_time'] ?? ''));

        if (!$product_id || !$date || !$start_time) {
            wp_send_json_error(['message' => Bookflow_I18n::t('error.invalid_request')]);
        }

        $resources = Bookflow_Resources::get_available_for_slot($product_id, $date, $start_time);

        $result = array_map(function ($r) {
            return [
                'id'          => (int) $r->id,
                'title'       => $r->title,
                'description' => $r->description,
                'photoUrl'    => Bookflow_Resources::get_photo_url($r),
                'capacity'    => (int) $r->capacity,
                'cost'        => (float) $r->base_cost,
                'costFormatted' => $r->base_cost > 0 ? wp_kses_post(wc_price($r->base_cost)) : '',
                'avgRating'   => (float) $r->avg_rating,
                'ratingCount' => (int) $r->rating_count,
            ];
        }, $resources);

        wp_send_json_success(['resources' => $result]);
    }

    /**
     * Get resources (guides/staff) with an open slot on a given date, before
     * a specific time is chosen — used by the wizard's "choose your guide"
     * step, which now runs before the Time/Slots step.
     */
    public function get_resources_for_date() {
        check_ajax_referer('bookflow_nonce', 'nonce');

        if (!Bookflow_Rate_Limit::check('resources', 30, 60)) {
            wp_send_json_error(['message' => Bookflow_I18n::t('error.rate_limited')], 429);
        }

        $product_id  = absint($_POST['product_id'] ?? 0);
        $date        = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        $schedule_id = absint($_POST['schedule_id'] ?? 0) ?: null;

        if (!$product_id || !$date) {
            wp_send_json_error(['message' => Bookflow_I18n::t('error.invalid_request')]);
        }

        $resources = Bookflow_Resources::get_available_for_date($product_id, $date, $schedule_id);

        $result = array_map(function ($r) {
            return [
                'id'          => (int) $r->id,
                'title'       => $r->title,
                'description' => $r->description,
                'photoUrl'    => Bookflow_Resources::get_photo_url($r),
                'capacity'    => (int) $r->capacity,
                'cost'        => (float) $r->base_cost,
                'costFormatted' => $r->base_cost > 0 ? wp_kses_post(wc_price($r->base_cost)) : '',
                'avgRating'   => (float) $r->avg_rating,
                'ratingCount' => (int) $r->rating_count,
            ];
        }, $resources);

        wp_send_json_success(['resources' => $result]);
    }
}

<?php
/**
 * Booking Data Model with State Machine
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Booking {

    /**
     * Every create() business-rule check that doesn't require the
     * slot-locking transaction — i.e. isn't racing another concurrent
     * booking for the same capacity. Kept separate from create() itself so
     * new non-concurrency rules (there have already been three: eligibility,
     * anti-hoarding, cost/end_time normalization) have one obvious place to
     * go instead of growing create() indefinitely.
     *
     * @return array|WP_Error normalized $data on success
     */
    private static function validate_pre_checks($data, $product) {
        // Generic eligibility gate — e.g. a customer flag a product requires
        // before it can be booked at all. See Bookflow_Customer_Flags.
        if ($data['customer_id'] && !Bookflow_Customer_Flags::is_product_bookable_for_customer($data['product_id'], $data['customer_id'])) {
            return new WP_Error('not_eligible', Bookflow_I18n::t('error.not_eligible'));
        }

        // Generic anti-hoarding limit — a product can cap how many
        // pending/confirmed bookings one customer may hold at once.
        $max_active = Bookflow_Product_Config::max_active_bookings_per_customer($data['product_id']);
        if ($max_active > 0 && $data['customer_id']) {
            $active_count = self::count_active_for_customer($data['customer_id'], $data['product_id']);
            if ($active_count >= $max_active) {
                return new WP_Error('too_many_active_bookings', Bookflow_I18n::t('error.too_many_active_bookings'));
            }
        }

        // Ensure cost is never negative
        $data['cost'] = max(0, (float) $data['cost']);

        // Calculate end_time from duration if not provided
        if (empty($data['end_time']) && !empty($data['start_time'])) {
            $duration = $product->get_duration();
            $start = strtotime($data['booking_date'] . ' ' . $data['start_time']);
            $data['end_time'] = gmdate('H:i', $start + ($duration * 60));
        }

        return $data;
    }

    /**
     * Create a new booking with concurrency protection
     */
    public static function create($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_bookings';

        $defaults = [
            'product_id'      => 0,
            'resource_id'     => null,
            'schedule_id'     => null,
            'order_id'        => null,
            'customer_id'     => null,
            'parent_id'       => null,
            'booking_date'    => '',
            'start_time'      => '',
            'end_time'        => null,
            'all_day'         => 0,
            'persons_total'   => 1,
            'cost'            => 0.00,
            'full_total'      => 0.00,
            'deposit_amount'  => 0.00,
            'status'          => 'pending',
            'customer_name'   => '',
            'customer_email'  => '',
            'customer_phone'  => '',
            'customer_locale' => '',
            'notes'           => '',
            'internal_notes'  => '',
            'ip_address'      => '',
            'user_agent'      => '',
            'resource_ids'    => [],
        ];

        $data = wp_parse_args($data, $defaults);

        // Validate product exists and is bookable
        $product = wc_get_product($data['product_id']);
        if (!$product || $product->get_type() !== 'booking') {
            return new WP_Error('invalid_product', Bookflow_I18n::t('error.booking_not_found'));
        }

        // Every check that doesn't need the slot-locking transaction below
        // (business rules, not concurrency-sensitive capacity) lives in one
        // place so create() itself stays readable as new rules are added.
        $data = self::validate_pre_checks($data, $product);
        if (is_wp_error($data)) {
            return $data;
        }

        // Concurrency check: verify slot is still available before insert
        $wpdb->query('START TRANSACTION'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity

        // Lock rows for this slot to prevent concurrent overbooking
        // Scope by schedule_id if provided (each schedule has its own capacity)
        $schedule_id = $data['schedule_id'] ? absint($data['schedule_id']) : null;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom table, no core API exists; live data required for booking/availability integrity; $table is built only from $wpdb->prefix + a literal suffix, never from request data; all real values go through %d/%s + $wpdb->prepare()
        if ($schedule_id) {
            $slot_data = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) as booking_count, COALESCE(SUM(persons_total), 0) as booked_persons
                 FROM $table
                 WHERE product_id = %d AND booking_date = %s AND start_time = %s
                 AND schedule_id = %d
                 AND status NOT IN ('cancelled', 'refunded')
                 FOR UPDATE",
                $data['product_id'],
                $data['booking_date'],
                $data['start_time'],
                $schedule_id
            ));
        } else {
            $slot_data = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) as booking_count, COALESCE(SUM(persons_total), 0) as booked_persons
                 FROM $table
                 WHERE product_id = %d AND booking_date = %s AND start_time = %s
                 AND status NOT IN ('cancelled', 'refunded')
                 FOR UPDATE",
                $data['product_id'],
                $data['booking_date'],
                $data['start_time']
            ));
        }
        // phpcs:enable

        $slot_count = (int) $slot_data->booking_count;
        $booked_persons = (int) $slot_data->booked_persons;

        $product = wc_get_product($data['product_id']);

        // Determine capacity limits — schedule overrides product defaults
        $max_per_slot = 1;
        $max_persons = 20;
        if ($product && method_exists($product, 'get_max_bookings_per_slot')) {
            $max_per_slot = $product->get_max_bookings_per_slot();
        }
        if ($product && method_exists($product, 'get_max_persons')) {
            $max_persons = $product->get_max_persons();
        }

        if ($schedule_id) {
            $schedule = Bookflow_Schedules::get($schedule_id);
            if ($schedule) {
                if ($schedule->max_bookings_per_slot > 0) {
                    $max_per_slot = (int) $schedule->max_bookings_per_slot;
                }
                if ($schedule->max_persons > 0) {
                    $max_persons = (int) $schedule->max_persons;
                }
            }
        }

        if ($slot_count >= $max_per_slot) {
            $wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
            return new WP_Error('slot_taken', Bookflow_I18n::t('error.slot_taken'));
        }

        // Check person capacity
        $requested_persons = max(1, absint($data['persons_total']));
        if (($booked_persons + $requested_persons) > $max_persons) {
            $wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
            $remaining = $max_persons - $booked_persons;
            return new WP_Error(
                'capacity_exceeded',
                Bookflow_I18n::t('error.not_enough_capacity', max(0, $remaining))
            );
        }

        // Check resource capacity (rows for this slot are already locked above)
        $resource_id_val = $data['resource_id'] ? absint($data['resource_id']) : null;
        if ($resource_id_val) {
            $resource = $wpdb->get_row($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
                "SELECT capacity FROM {$wpdb->prefix}bookflow_resources WHERE id = %d AND status = 'active'",
                $resource_id_val
            ));
            if ($resource && (int) $resource->capacity > 0) {
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom table, no core API exists; live data required for booking/availability integrity; $table is built only from $wpdb->prefix + a literal suffix, never from request data; all real values go through %d/%s + $wpdb->prepare()
                $resource_booked = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(persons_total), 0) FROM $table
                     WHERE resource_id = %d AND booking_date = %s AND start_time = %s
                     AND status NOT IN ('cancelled', 'refunded')",
                    $resource_id_val,
                    $data['booking_date'],
                    $data['start_time']
                ));
                // phpcs:enable
                if (($resource_booked + $requested_persons) > (int) $resource->capacity) {
                    $wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
                    $remaining = (int) $resource->capacity - $resource_booked;
                    return new WP_Error(
                        'resource_capacity_exceeded',
                        Bookflow_I18n::t('error.not_enough_capacity', max(0, $remaining))
                    );
                }
            }
        }

        // Lock and capacity-check any secondary resources (e.g. equipment
        // paired with the primary staff resource) inside the same
        // transaction, so a booking either gets every resource it needs or
        // none of them.
        $extra_resources = array_values(array_filter((array) $data['resource_ids']));
        foreach ($extra_resources as $extra) {
            $extra_id = is_array($extra) ? absint($extra['resource_id'] ?? 0) : absint($extra);
            if (!$extra_id || $extra_id === $resource_id_val) {
                continue;
            }
            $extra_resource = $wpdb->get_row($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
                "SELECT capacity FROM {$wpdb->prefix}bookflow_resources WHERE id = %d AND status = 'active'",
                $extra_id
            ));
            if (!$extra_resource) {
                $wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
                return new WP_Error('resource_unavailable', Bookflow_I18n::t('error.resource_unavailable'));
            }
            $capacity = (int) $extra_resource->capacity ?: 1;
            $extra_booked = Bookflow_Booking_Resources::count_booked($extra_id, $data['booking_date'], $data['start_time'], true);
            if (($extra_booked + $requested_persons) > $capacity) {
                $wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
                return new WP_Error('resource_capacity_exceeded', Bookflow_I18n::t('error.not_enough_capacity', max(0, $capacity - $extra_booked)));
            }
        }

        $result = $wpdb->insert($table, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
            'product_id'      => absint($data['product_id']),
            'resource_id'     => $data['resource_id'] ? absint($data['resource_id']) : null,
            'schedule_id'     => $schedule_id,
            'order_id'        => $data['order_id'] ? absint($data['order_id']) : null,
            'customer_id'     => $data['customer_id'] ? absint($data['customer_id']) : null,
            'parent_id'       => $data['parent_id'] ? absint($data['parent_id']) : null,
            'booking_date'    => sanitize_text_field($data['booking_date']),
            'start_time'      => sanitize_text_field($data['start_time']),
            'end_time'        => $data['end_time'] ? sanitize_text_field($data['end_time']) : null,
            'all_day'         => (int) $data['all_day'],
            'persons_total'   => max(1, absint($data['persons_total'])),
            'cost'            => (float) $data['cost'],
            'full_total'      => (float) ($data['full_total'] ?: $data['cost']),
            'deposit_amount'  => (float) $data['deposit_amount'],
            'status'          => sanitize_text_field($data['status']),
            'customer_name'   => sanitize_text_field($data['customer_name']),
            'customer_email'  => sanitize_email($data['customer_email']),
            'customer_phone'  => sanitize_text_field($data['customer_phone']),
            'customer_locale' => sanitize_text_field($data['customer_locale']),
            'notes'           => sanitize_textarea_field($data['notes']),
            'internal_notes'  => sanitize_textarea_field($data['internal_notes']),
            'ip_address'      => sanitize_text_field($data['ip_address']),
            'user_agent'      => sanitize_text_field(substr($data['user_agent'], 0, 500)),
        ]);

        if ($result === false) {
            $wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
            return new WP_Error('db_error', Bookflow_I18n::t('error.could_not_create'));
        }

        $booking_id = $wpdb->insert_id;
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity

        // Record every resource this booking holds (primary + any extras)
        // in the many-to-many table, superseding the single resource_id
        // column for anything that needs more than one resource per booking.
        $all_resources = [];
        if ($resource_id_val) {
            $all_resources[] = ['resource_id' => $resource_id_val, 'role' => 'primary'];
        }
        foreach ($extra_resources as $extra) {
            $extra_id = is_array($extra) ? absint($extra['resource_id'] ?? 0) : absint($extra);
            if ($extra_id && $extra_id !== $resource_id_val) {
                $all_resources[] = [
                    'resource_id' => $extra_id,
                    'role'        => is_array($extra) && !empty($extra['role']) ? $extra['role'] : 'secondary',
                ];
            }
        }
        if (!empty($all_resources)) {
            Bookflow_Booking_Resources::attach($booking_id, $all_resources);
        }

        // Fire action for cache invalidation, logging, emails
        do_action('bookflow_booking_created', $data, $booking_id);

        Bookflow_Logger::log('booking_created', $booking_id, [
            'new' => $data,
        ]);

        return $booking_id;
    }

    /**
     * Count a customer's currently pending/confirmed (i.e. "active", not yet
     * resolved) bookings — used to enforce a per-product cap on how many
     * future bookings one customer may hold at once.
     */
    public static function count_active_for_customer($customer_id, $product_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_bookings';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists; $table built from prefix + literal only
        if ($product_id) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table
                 WHERE customer_id = %d AND product_id = %d
                 AND status IN ('pending', 'confirmed', 'paid', 'partially-paid')
                 AND deleted_at IS NULL",
                absint($customer_id),
                absint($product_id)
            ));
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table
             WHERE customer_id = %d
             AND status IN ('pending', 'confirmed', 'paid', 'partially-paid')
             AND deleted_at IS NULL",
            absint($customer_id)
        ));
        // phpcs:enable
    }

    /**
     * Get booking by ID
     */
    public static function get($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_bookings';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", absint($id))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists; live data required for booking/availability integrity; table/sql var built only from fixed literals ($wpdb->prefix + literal string or %d/%s placeholders resolved via $wpdb->prepare()), never from unescaped request data
    }

    /**
     * Update booking fields
     */
    public static function update($id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_bookings';

        $allowed = [
            'resource_id', 'schedule_id', 'order_id', 'customer_id', 'booking_date', 'start_time',
            'end_time', 'all_day', 'persons_total', 'cost', 'customer_name',
            'customer_email', 'customer_phone', 'customer_locale', 'notes',
            'internal_notes', 'cancellation_reason', 'google_calendar_event_id', 'rating_token',
        ];

        $update = [];
        $format = [];
        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed)) {
                continue;
            }
            $update[$key] = $value;
            $format[] = is_int($value) ? '%d' : (is_float($value) ? '%f' : '%s');
        }

        if (empty($update)) {
            return false;
        }

        $old = self::get($id);

        $result = $wpdb->update($table, $update, ['id' => absint($id)], $format, ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity

        if ($result !== false) {
            do_action('bookflow_booking_updated', $id, $update, $old);
            Bookflow_Logger::log('booking_updated', $id, [
                'old'  => (array) $old,
                'new'  => $update,
            ]);
        }

        return $result;
    }

    /**
     * Transition booking status with state machine validation
     */
    /**
     * @param array $context {
     *     Optional. Extra context recorded alongside a 'cancelled' transition.
     *
     *     @type string $cancelled_by Who initiated the cancellation —
     *           'customer', 'staff', or 'system' (e.g. an unpaid order
     *           auto-expiring). Free-text; Bookflow only stores and exposes
     *           it via $booking->cancelled_by for filters like
     *           `bookflow_credit_should_refund_on_cancel` to key a refund
     *           policy on (e.g. only forfeit on a late customer cancel).
     *           Defaults to 'staff' when omitted.
     * }
     */
    public static function transition_status($id, $new_status, $note = '', $context = []) {
        $booking = self::get($id);
        if (!$booking) {
            return new WP_Error('not_found', Bookflow_I18n::t('error.booking_not_found'));
        }

        $old_status = $booking->status;

        // Validate transition
        $allowed = BOOKFLOW_STATUS_TRANSITIONS[$old_status] ?? [];
        if (!in_array($new_status, $allowed) && $old_status !== $new_status) {
            return new WP_Error(
                'invalid_transition',
                Bookflow_I18n::t('error.invalid_transition', $old_status, $new_status)
            );
        }

        if ($old_status === $new_status) {
            return true; // No change needed
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_bookings';

        $update_data = ['status' => $new_status];

        // Set timestamp fields based on new status
        $now = current_time('mysql');
        switch ($new_status) {
            case 'confirmed':
                $update_data['confirmed_at'] = $now;
                break;
            case 'cancelled':
                $update_data['cancelled_at'] = $now;
                $update_data['cancelled_by'] = sanitize_key($context['cancelled_by'] ?? 'staff');
                if ($note) {
                    $update_data['cancellation_reason'] = sanitize_textarea_field($note);
                }
                break;
            case 'completed':
                $update_data['completed_at'] = $now;
                break;
        }

        $result = $wpdb->update($table, $update_data, ['id' => absint($id)]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity

        if ($result === false) {
            return new WP_Error('db_error', Bookflow_I18n::t('error.could_not_update_status'));
        }

        // Fire hooks
        do_action('bookflow_booking_status_changed', $id, $old_status, $new_status);
        do_action("bookflow_booking_{$new_status}", $id, $old_status);

        return true;
    }

    /**
     * Legacy method - wraps transition_status
     */
    public static function update_status($id, $status) {
        $result = self::transition_status($id, $status);
        return is_wp_error($result) ? false : $result;
    }

    /**
     * Set order ID on a booking
     */
    public static function set_order_id($id, $order_id) {
        return self::update($id, ['order_id' => absint($order_id)]);
    }

    /**
     * Get bookings for a product on a specific date
     */
    public static function get_by_date($product_id, $date) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_bookings';
        return $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom table, no core API exists; live data required for booking/availability integrity; table/sql var built only from fixed literals ($wpdb->prefix + literal string or %d/%s placeholders resolved via $wpdb->prepare()), never from unescaped request data
            "SELECT * FROM $table WHERE product_id = %d AND booking_date = %s AND status NOT IN ('cancelled', 'refunded') AND deleted_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + a literal suffix, never request data
            absint($product_id),
            $date
        ));
    }

    /**
     * Count bookings for a specific slot (with optional resource)
     */
    public static function count_for_slot($product_id, $date, $start_time, $resource_id = null, $schedule_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_bookings';

        $sql = "SELECT COUNT(*) FROM $table WHERE product_id = %d AND booking_date = %s AND start_time = %s AND status NOT IN ('cancelled', 'refunded') AND deleted_at IS NULL"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + a literal suffix, never request data; real values go through %d/%s + $wpdb->prepare() below
        $params = [absint($product_id), $date, $start_time];

        if ($resource_id) {
            $sql .= " AND resource_id = %d";
            $params[] = absint($resource_id);
        }

        if ($schedule_id) {
            $sql .= " AND schedule_id = %d";
            $params[] = absint($schedule_id);
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- built only from fixed literals + %d/%s placeholders; real values always passed through $wpdb->prepare()
    }

    /**
     * Count total persons for a slot
     */
    public static function persons_for_slot($product_id, $date, $start_time, $resource_id = null, $schedule_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_bookings';

        $sql = "SELECT COALESCE(SUM(persons_total), 0) FROM $table WHERE product_id = %d AND booking_date = %s AND start_time = %s AND status NOT IN ('cancelled', 'refunded') AND deleted_at IS NULL"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + a literal suffix, never request data; real values go through %d/%s + $wpdb->prepare() below
        $params = [absint($product_id), $date, $start_time];

        if ($resource_id) {
            $sql .= " AND resource_id = %d";
            $params[] = absint($resource_id);
        }

        if ($schedule_id) {
            $sql .= " AND schedule_id = %d";
            $params[] = absint($schedule_id);
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- built only from fixed literals + %d/%s placeholders; real values always passed through $wpdb->prepare()
    }

    /**
     * Whether an existing booking's stored end_time falls inside the
     * trailing-buffer window right before a candidate slot's start time —
     * i.e. there isn't enough gap after the previous booking (same
     * product/resource/date) for cleanup/travel time before this one.
     */
    public static function has_conflicting_trailing_booking($product_id, $date, $start_time, $buffer_minutes, $resource_id = null, $schedule_id = null) {
        if ($buffer_minutes <= 0) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_bookings';

        $window_start = gmdate('H:i:s', strtotime($start_time) - ($buffer_minutes * 60));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + a literal suffix, never request data; real values go through %d/%s + $wpdb->prepare() below
        $sql = "SELECT COUNT(*) FROM $table
                WHERE product_id = %d AND booking_date = %s
                AND status NOT IN ('cancelled', 'refunded') AND deleted_at IS NULL
                AND end_time IS NOT NULL
                AND end_time > %s AND end_time <= %s";
        // phpcs:enable
        $params = [absint($product_id), $date, $window_start, $start_time];

        if ($resource_id) {
            $sql .= " AND resource_id = %d";
            $params[] = absint($resource_id);
        }

        if ($schedule_id) {
            $sql .= " AND schedule_id = %d";
            $params[] = absint($schedule_id);
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params)) > 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- built only from fixed literals + %d/%s placeholders; real values always passed through $wpdb->prepare()
    }

    /**
     * Get all bookings with filters
     */
    public static function query($args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_bookings';

        $where = ['1=1'];
        $values = [];

        // Trashed bookings are hidden from every normal query by default —
        // same as WP posts disappearing from the main list once trashed.
        // Pass trashed_only=true to see only trash, or include_trashed=true to see both.
        if (!empty($args['trashed_only'])) {
            $where[] = 'deleted_at IS NOT NULL';
        } elseif (empty($args['include_trashed'])) {
            $where[] = 'deleted_at IS NULL';
        }

        if (!empty($args['product_id'])) {
            $where[] = 'product_id = %d';
            $values[] = absint($args['product_id']);
        }

        if (!empty($args['resource_id'])) {
            $where[] = 'resource_id = %d';
            $values[] = absint($args['resource_id']);
        }

        // Scope a query to any of several resources — e.g. a staff user who
        // only manages a subset of resources listing their own bookings.
        if (!empty($args['resource_id__in'])) {
            $ids = array_map('absint', (array) $args['resource_id__in']);
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $where[] = "resource_id IN ($placeholders)";
            $values = array_merge($values, $ids);
        }

        if (!empty($args['status'])) {
            if (is_array($args['status'])) {
                $placeholders = implode(',', array_fill(0, count($args['status']), '%s'));
                $where[] = "status IN ($placeholders)";
                $values = array_merge($values, array_map('sanitize_text_field', $args['status']));
            } else {
                $where[] = 'status = %s';
                $values[] = sanitize_text_field($args['status']);
            }
        }

        if (!empty($args['status_not'])) {
            $not_statuses = (array) $args['status_not'];
            $placeholders = implode(',', array_fill(0, count($not_statuses), '%s'));
            $where[] = "status NOT IN ($placeholders)";
            $values = array_merge($values, array_map('sanitize_text_field', $not_statuses));
        }

        if (!empty($args['date_from'])) {
            $where[] = 'booking_date >= %s';
            $values[] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where[] = 'booking_date <= %s';
            $values[] = $args['date_to'];
        }

        if (!empty($args['date'])) {
            $where[] = 'booking_date = %s';
            $values[] = $args['date'];
        }

        if (!empty($args['order_id'])) {
            $where[] = 'order_id = %d';
            $values[] = absint($args['order_id']);
        }

        if (!empty($args['customer_id'])) {
            $where[] = 'customer_id = %d';
            $values[] = absint($args['customer_id']);
        }

        if (!empty($args['customer_email'])) {
            $where[] = 'customer_email = %s';
            $values[] = sanitize_email($args['customer_email']);
        }

        if (!empty($args['search'])) {
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s)';
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
        }

        $order_by = $args['orderby'] ?? 'booking_date';
        $order = $args['order'] ?? 'DESC';
        $limit = isset($args['limit']) ? absint($args['limit']) : 50;
        $offset = isset($args['offset']) ? absint($args['offset']) : 0;

        $allowed_orderby = ['id', 'booking_date', 'created_at', 'cost', 'status', 'start_time', 'persons_total'];
        if (!in_array($order_by, $allowed_orderby, true)) {
            $order_by = 'booking_date';
        }
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $where_clause = implode(' AND ', $where);
        $sql = "SELECT * FROM $table WHERE $where_clause ORDER BY $order_by $order, start_time ASC LIMIT %d OFFSET %d";
        $values[] = $limit;
        $values[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, ...$values)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- built only from fixed literals + %d/%s placeholders; real values always passed through $wpdb->prepare()
    }

    /**
     * Count total bookings with filters
     */
    public static function count($args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_bookings';

        $where = ['1=1'];
        $values = [];

        if (!empty($args['trashed_only'])) {
            $where[] = 'deleted_at IS NOT NULL';
        } elseif (empty($args['include_trashed'])) {
            $where[] = 'deleted_at IS NULL';
        }

        foreach (['product_id', 'resource_id', 'order_id', 'customer_id'] as $int_field) {
            if (!empty($args[$int_field])) {
                $where[] = "$int_field = %d";
                $values[] = absint($args[$int_field]);
            }
        }

        if (!empty($args['resource_id__in'])) {
            $ids = array_map('absint', (array) $args['resource_id__in']);
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $where[] = "resource_id IN ($placeholders)";
            $values = array_merge($values, $ids);
        }

        if (!empty($args['status'])) {
            if (is_array($args['status'])) {
                $placeholders = implode(',', array_fill(0, count($args['status']), '%s'));
                $where[] = "status IN ($placeholders)";
                $values = array_merge($values, $args['status']);
            } else {
                $where[] = 'status = %s';
                $values[] = $args['status'];
            }
        }

        if (!empty($args['date_from'])) {
            $where[] = 'booking_date >= %s';
            $values[] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where[] = 'booking_date <= %s';
            $values[] = $args['date_to'];
        }

        if (!empty($args['search'])) {
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s)';
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
        }

        $where_clause = implode(' AND ', $where);
        $sql = "SELECT COUNT(*) FROM $table WHERE $where_clause";

        if (!empty($values)) {
            return (int) $wpdb->get_var($wpdb->prepare($sql, ...$values)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- built only from fixed literals + %d/%s placeholders; real values always passed through $wpdb->prepare()
        }

        return (int) $wpdb->get_var($sql); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- built only from fixed literals + %d/%s placeholders; real values always passed through $wpdb->prepare()
    }

    /**
     * Get revenue stats
     */
    public static function get_revenue($args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_bookings';

        $where = ["status NOT IN ('cancelled', 'refunded')"];
        $values = [];

        if (!empty($args['date_from'])) {
            $where[] = 'booking_date >= %s';
            $values[] = $args['date_from'];
        }
        if (!empty($args['date_to'])) {
            $where[] = 'booking_date <= %s';
            $values[] = $args['date_to'];
        }
        if (!empty($args['product_id'])) {
            $where[] = 'product_id = %d';
            $values[] = absint($args['product_id']);
        }

        $where_clause = implode(' AND ', $where);
        $sql = "SELECT COALESCE(SUM(cost), 0) as total_revenue, COUNT(*) as total_bookings, COALESCE(SUM(persons_total), 0) as total_persons FROM $table WHERE $where_clause";

        if (!empty($values)) {
            return $wpdb->get_row($wpdb->prepare($sql, ...$values)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- built only from fixed literals + %d/%s placeholders; real values always passed through $wpdb->prepare()
        }

        return $wpdb->get_row($sql); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- built only from fixed literals + %d/%s placeholders; real values always passed through $wpdb->prepare()
    }

    /**
     * Revenue + booking count grouped by day, for a simple reports chart.
     */
    public static function get_revenue_by_day($args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_bookings';

        $where = ["status NOT IN ('cancelled', 'refunded')"];
        $values = [];
        if (!empty($args['date_from'])) { $where[] = 'booking_date >= %s'; $values[] = $args['date_from']; }
        if (!empty($args['date_to'])) { $where[] = 'booking_date <= %s'; $values[] = $args['date_to']; }
        if (!empty($args['product_id'])) { $where[] = 'product_id = %d'; $values[] = absint($args['product_id']); }

        $where_clause = implode(' AND ', $where);
        $sql = "SELECT booking_date as date, COALESCE(SUM(cost), 0) as revenue, COUNT(*) as bookings
                FROM $table WHERE $where_clause GROUP BY booking_date ORDER BY booking_date ASC";

        return !empty($values) ? $wpdb->get_results($wpdb->prepare($sql, ...$values)) : $wpdb->get_results($sql); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- built only from fixed literals + %d/%s placeholders; real values always passed through $wpdb->prepare()
    }

    /**
     * Revenue + booking count grouped by product, for a top-products table.
     */
    public static function get_revenue_by_product($args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_bookings';

        $where = ["status NOT IN ('cancelled', 'refunded')"];
        $values = [];
        if (!empty($args['date_from'])) { $where[] = 'booking_date >= %s'; $values[] = $args['date_from']; }
        if (!empty($args['date_to'])) { $where[] = 'booking_date <= %s'; $values[] = $args['date_to']; }
        if (!empty($args['product_id'])) { $where[] = 'product_id = %d'; $values[] = absint($args['product_id']); }

        $where_clause = implode(' AND ', $where);
        $sql = "SELECT product_id, COALESCE(SUM(cost), 0) as revenue, COUNT(*) as bookings
                FROM $table WHERE $where_clause GROUP BY product_id ORDER BY revenue DESC LIMIT 10";

        return !empty($values) ? $wpdb->get_results($wpdb->prepare($sql, ...$values)) : $wpdb->get_results($sql); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- built only from fixed literals + %d/%s placeholders; real values always passed through $wpdb->prepare()
    }

    /**
     * Revenue/occupancy grouped by resource (e.g. per-instructor, per-room)
     * — a generic breakdown any resource-based booking business can use for
     * staff performance/utilization reporting.
     */
    public static function get_revenue_by_resource($args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_bookings';

        $where = ["status NOT IN ('cancelled', 'refunded')", 'resource_id IS NOT NULL'];
        $values = [];
        if (!empty($args['date_from'])) { $where[] = 'booking_date >= %s'; $values[] = $args['date_from']; }
        if (!empty($args['date_to'])) { $where[] = 'booking_date <= %s'; $values[] = $args['date_to']; }
        if (!empty($args['product_id'])) { $where[] = 'product_id = %d'; $values[] = absint($args['product_id']); }

        $where_clause = implode(' AND ', $where);
        $sql = "SELECT resource_id, COALESCE(SUM(cost), 0) as revenue, COUNT(*) as bookings
                FROM $table WHERE $where_clause GROUP BY resource_id ORDER BY revenue DESC LIMIT 50";

        return !empty($values) ? $wpdb->get_results($wpdb->prepare($sql, ...$values)) : $wpdb->get_results($sql); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- built only from fixed literals + %d/%s placeholders; real values always passed through $wpdb->prepare()
    }

    /**
     * Move a booking to a new date/time/resource(s) without treating it as
     * a real cancellation: any credit already consumed for the original
     * booking is silently re-keyed to the new booking id (so it's neither
     * refunded nor consumed a second time), then the original is marked
     * cancelled with cancelled_by='reschedule' (a marker theme code can use
     * to distinguish this from a real cancellation in reporting/policy
     * filters — Bookflow_Credits' own refund lookup finds nothing left
     * under the old id by the time this runs, so no refund fires for it).
     *
     * $new_data accepts the same keys as create() (booking_date, start_time,
     * resource_id, resource_ids, etc.); omitted keys default to the
     * original booking's values.
     *
     * @return int|WP_Error new booking id
     */
    public static function reschedule($booking_id, $new_data = []) {
        $old = self::get($booking_id);
        if (!$old) {
            return new WP_Error('not_found', Bookflow_I18n::t('error.booking_not_found'));
        }

        $carry_over_status = in_array($old->status, ['confirmed', 'paid', 'partially-paid'], true) ? $old->status : 'pending';

        $data = wp_parse_args($new_data, [
            'product_id'      => $old->product_id,
            'resource_id'     => $old->resource_id,
            'schedule_id'     => $old->schedule_id,
            'order_id'        => $old->order_id,
            'customer_id'     => $old->customer_id,
            'booking_date'    => $old->booking_date,
            'start_time'      => $old->start_time,
            'persons_total'   => $old->persons_total,
            'cost'            => $old->cost,
            'full_total'      => $old->full_total,
            'deposit_amount'  => $old->deposit_amount,
            'status'          => $carry_over_status,
            'customer_name'   => $old->customer_name,
            'customer_email'  => $old->customer_email,
            'customer_phone'  => $old->customer_phone,
            'customer_locale' => $old->customer_locale,
            'notes'           => $old->notes,
        ]);
        // Passing an explicit 'status' here (rather than the default
        // 'pending') deliberately skips the bookflow_booking_confirmed
        // action — create() only fires bookflow_booking_created — so this
        // never double-consumes a credit; the original consume ledger row
        // is re-keyed below instead.
        $new_id = self::create($data);

        if (is_wp_error($new_id)) {
            return $new_id;
        }

        global $wpdb;
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists
            $wpdb->prefix . 'bookflow_credit_transactions',
            ['booking_id' => $new_id],
            ['booking_id' => $booking_id, 'type' => 'consume']
        );

        self::transition_status($booking_id, 'cancelled', 'Rescheduled', ['cancelled_by' => 'reschedule']);

        do_action('bookflow_booking_rescheduled', $booking_id, $new_id);

        return $new_id;
    }

    /**
     * Delete a booking
     */
    public static function delete($id) {
        global $wpdb;

        $booking = self::get($id);
        if (!$booking) {
            return false;
        }

        Bookflow_Logger::log('booking_deleted', $id, ['old' => (array) $booking]);

        // Delete person type entries
        $wpdb->delete($wpdb->prefix . 'bookflow_booking_persons', ['booking_id' => absint($id)], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity

        // Delete log entries
        $wpdb->delete($wpdb->prefix . 'bookflow_log', ['booking_id' => absint($id)], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity

        // Delete the booking
        $result = $wpdb->delete($wpdb->prefix . 'bookflow_bookings', ['id' => absint($id)], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity

        if ($result) {
            do_action('bookflow_booking_deleted', $id, $booking);
        }

        return $result;
    }

    /**
     * Move a booking to Trash — reversible, unlike delete(). Trashed bookings
     * are excluded from query()/count()/availability checks by default (see
     * the deleted_at handling in those methods) but the row itself, its
     * person-type breakdown, and its log history are all left intact.
     */
    public static function trash($id) {
        global $wpdb;
        $booking = self::get($id);
        if (!$booking || $booking->deleted_at) {
            return false;
        }

        $result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
            $wpdb->prefix . 'bookflow_bookings',
            ['deleted_at' => current_time('mysql')],
            ['id' => absint($id)]
        );

        if ($result !== false) {
            Bookflow_Logger::log('booking_trashed', $id, ['old' => (array) $booking]);
            do_action('bookflow_booking_trashed', $id, $booking);
        }

        return $result !== false;
    }

    /**
     * Restore a trashed booking.
     */
    public static function restore($id) {
        global $wpdb;
        $booking = self::get($id);
        if (!$booking || !$booking->deleted_at) {
            return false;
        }

        $result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
            $wpdb->prefix . 'bookflow_bookings',
            ['deleted_at' => null],
            ['id' => absint($id)]
        );

        if ($result !== false) {
            Bookflow_Logger::log('booking_restored', $id, ['old' => (array) $booking]);
            do_action('bookflow_booking_restored', $id, $booking);
        }

        return $result !== false;
    }

    /**
     * Count how many bookings match the given filters — same filter args as
     * query()/count(), used by the admin bulk-delete tool to show "N bookings
     * match" before anything is deleted.
     */
    public static function count_matching($args = []) {
        return self::count($args);
    }

    /**
     * Delete every booking matching the given filters (same filter args as
     * query(): status, status_not, date_from, date_to, date, product_id,
     * resource_id, order_id, customer_id, customer_email, search), capped at
     * $limit rows per call.
     *
     * Deliberately does NOT loop delete() per row — for a few thousand rows
     * that's a few thousand extra round trips (one SELECT + up to 3 DELETEs
     * + a log insert each). Instead this selects the matching IDs once, then
     * issues exactly 3 DELETE ... WHERE id IN (...) statements plus a single
     * summary log entry, regardless of how many rows match. Use $limit to
     * process a large matching set in batches rather than one huge query —
     * call repeatedly until it returns fewer rows than $limit.
     *
     * Returns ['deleted' => int, 'ids' => int[]].
     */
    public static function bulk_delete_by_filter($args = [], $limit = 500) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_bookings';

        $where = ['1=1'];
        $values = [];

        // Pass trashed_only=true (as the admin "Empty Trash" action does) to
        // scope permanent deletion to already-trashed rows only. Omit both
        // flags and this operates regardless of trash state — a deliberate
        // power-user/CLI escape hatch, not exposed as the default UI action.
        if (!empty($args['trashed_only'])) {
            $where[] = 'deleted_at IS NOT NULL';
        } elseif (!empty($args['exclude_trashed'])) {
            $where[] = 'deleted_at IS NULL';
        }

        if (!empty($args['status'])) {
            if (is_array($args['status'])) {
                $placeholders = implode(',', array_fill(0, count($args['status']), '%s'));
                $where[] = "status IN ($placeholders)";
                $values = array_merge($values, array_map('sanitize_text_field', $args['status']));
            } else {
                $where[] = 'status = %s';
                $values[] = sanitize_text_field($args['status']);
            }
        }
        if (!empty($args['status_not'])) {
            $not_statuses = (array) $args['status_not'];
            $placeholders = implode(',', array_fill(0, count($not_statuses), '%s'));
            $where[] = "status NOT IN ($placeholders)";
            $values = array_merge($values, array_map('sanitize_text_field', $not_statuses));
        }
        if (!empty($args['product_id'])) {
            $where[] = 'product_id = %d';
            $values[] = absint($args['product_id']);
        }
        if (!empty($args['resource_id'])) {
            $where[] = 'resource_id = %d';
            $values[] = absint($args['resource_id']);
        }
        if (!empty($args['order_id'])) {
            $where[] = 'order_id = %d';
            $values[] = absint($args['order_id']);
        }
        if (!empty($args['customer_id'])) {
            $where[] = 'customer_id = %d';
            $values[] = absint($args['customer_id']);
        }
        if (!empty($args['customer_email'])) {
            $where[] = 'customer_email = %s';
            $values[] = sanitize_email($args['customer_email']);
        }
        if (!empty($args['date_from'])) {
            $where[] = 'booking_date >= %s';
            $values[] = $args['date_from'];
        }
        if (!empty($args['date_to'])) {
            $where[] = 'booking_date <= %s';
            $values[] = $args['date_to'];
        }
        if (!empty($args['date'])) {
            $where[] = 'booking_date = %s';
            $values[] = $args['date'];
        }
        if (!empty($args['search'])) {
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s)';
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
        }
        if (!empty($args['internal_notes_like'])) {
            $where[] = 'internal_notes LIKE %s';
            $values[] = '%' . $wpdb->esc_like($args['internal_notes_like']) . '%';
        }

        // Require at least one real filter — refuse to mass-delete an unfiltered table by accident.
        if (count($where) === 1) {
            return new WP_Error('bookflow_bulk_delete_no_filter', 'Refusing to bulk-delete with no filters applied.');
        }

        $where_sql = implode(' AND ', $where);
        $limit = max(1, absint($limit));

        $id_sql = "SELECT id, product_id FROM $table WHERE $where_sql ORDER BY id ASC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($id_sql, ...array_merge($values, [$limit]))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- built only from fixed literals + %d/%s placeholders; real values always passed through $wpdb->prepare()

        if (empty($rows)) {
            return ['deleted' => 0, 'ids' => []];
        }

        $ids = array_map(fn($r) => (int) $r->id, $rows);
        $product_ids = array_unique(array_map(fn($r) => (int) $r->product_id, $rows));
        $id_placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bookflow_booking_persons WHERE booking_id IN ($id_placeholders)", ...$ids)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- custom table, no core API exists; live data required for booking/availability integrity; $id_placeholders always has exactly count($ids) %d placeholders matching the spread $ids args, phpcs can't see that statically
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bookflow_log WHERE booking_id IN ($id_placeholders)", ...$ids)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- custom table, no core API exists; live data required for booking/availability integrity; $id_placeholders always has exactly count($ids) %d placeholders matching the spread $ids args, phpcs can't see that statically
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id IN ($id_placeholders)", ...$ids)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- custom table, no core API exists; live data required for booking/availability integrity; table/sql var built only from fixed literals ($wpdb->prefix + literal string or %d/%s placeholders resolved via $wpdb->prepare()), never from unescaped request data

        Bookflow_Logger::log('bulk_deleted', null, [
            'count'  => $deleted,
            'ids'    => $ids,
            'filter' => $args,
        ]);

        if (class_exists('Bookflow_Cache')) {
            foreach ($product_ids as $pid) {
                Bookflow_Cache::invalidate_product_cache_by_id($pid);
            }
        }

        do_action('bookflow_bookings_bulk_deleted', $ids, $args);

        return ['deleted' => (int) $deleted, 'ids' => $ids];
    }

    /**
     * Move every booking matching the given filters to Trash — same filter
     * args as query()/bulk_delete_by_filter(), same batching-by-$limit
     * reasoning (a single UPDATE ... WHERE id IN (...) rather than looping
     * trash() per row). Reversible via restore(); rows and their child data
     * are left intact, only deleted_at is set.
     *
     * Returns ['trashed' => int, 'ids' => int[]] or WP_Error if no filter given.
     */
    public static function bulk_trash_by_filter($args = [], $limit = 500) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_bookings';

        $where = ['deleted_at IS NULL']; // only non-trashed rows are eligible to be trashed
        $values = [];

        if (!empty($args['status'])) {
            if (is_array($args['status'])) {
                $placeholders = implode(',', array_fill(0, count($args['status']), '%s'));
                $where[] = "status IN ($placeholders)";
                $values = array_merge($values, array_map('sanitize_text_field', $args['status']));
            } else {
                $where[] = 'status = %s';
                $values[] = sanitize_text_field($args['status']);
            }
        }
        if (!empty($args['product_id'])) {
            $where[] = 'product_id = %d';
            $values[] = absint($args['product_id']);
        }
        if (!empty($args['date_from'])) {
            $where[] = 'booking_date >= %s';
            $values[] = $args['date_from'];
        }
        if (!empty($args['date_to'])) {
            $where[] = 'booking_date <= %s';
            $values[] = $args['date_to'];
        }
        if (!empty($args['search'])) {
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s)';
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
        }
        if (!empty($args['internal_notes_like'])) {
            $where[] = 'internal_notes LIKE %s';
            $values[] = '%' . $wpdb->esc_like($args['internal_notes_like']) . '%';
        }

        if (count($where) === 1) {
            return new WP_Error('bookflow_bulk_trash_no_filter', 'Refusing to bulk-trash with no filters applied.');
        }

        $where_sql = implode(' AND ', $where);
        $limit = max(1, absint($limit));

        $id_sql = "SELECT id FROM $table WHERE $where_sql ORDER BY id ASC LIMIT %d";
        $ids = $wpdb->get_col($wpdb->prepare($id_sql, ...array_merge($values, [$limit]))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- built only from fixed literals + %d/%s placeholders; real values always passed through $wpdb->prepare()
        $ids = array_map('intval', $ids);

        if (empty($ids)) {
            return ['trashed' => 0, 'ids' => []];
        }

        $id_placeholders = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $id_placeholders always has exactly count($ids) %d placeholders and args pass exactly that many ids after the %s; $table is $wpdb->prefix + a literal suffix; phpcs can't see any of this statically
        $trashed = $wpdb->query($wpdb->prepare(
            "UPDATE $table SET deleted_at = %s WHERE id IN ($id_placeholders)",
            current_time('mysql'), ...$ids
        ));
        // phpcs:enable

        Bookflow_Logger::log('bulk_trashed', null, [
            'count'  => $trashed,
            'ids'    => $ids,
            'filter' => $args,
        ]);

        do_action('bookflow_bookings_bulk_trashed', $ids, $args);

        return ['trashed' => (int) $trashed, 'ids' => $ids];
    }

    /**
     * Save person type breakdown for a booking
     */
    public static function save_person_types($booking_id, $person_types) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_booking_persons';

        // Delete existing
        $wpdb->delete($table, ['booking_id' => absint($booking_id)], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity

        // Insert new
        foreach ($person_types as $pt) {
            if (empty($pt['quantity']) || $pt['quantity'] < 1) {
                continue;
            }
            $wpdb->insert($table, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
                'booking_id'      => absint($booking_id),
                'person_type_id'  => absint($pt['person_type_id']),
                'quantity'        => absint($pt['quantity']),
                'cost_per_person' => (float) ($pt['cost_per_person'] ?? 0),
            ], ['%d', '%d', '%d', '%f']);
        }
    }

    /**
     * Get person type breakdown for a booking
     */
    public static function get_person_types($booking_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
            "SELECT bp.*, pt.name as type_name
             FROM {$wpdb->prefix}bookflow_booking_persons bp
             LEFT JOIN {$wpdb->prefix}bookflow_person_types pt ON bp.person_type_id = pt.id
             WHERE bp.booking_id = %d",
            absint($booking_id)
        ));
    }

    /**
     * Check if a booking can be cancelled by customer
     */
    public static function can_customer_cancel($booking_id) {
        $booking = self::get($booking_id);
        if (!$booking) {
            return false;
        }

        if (!in_array($booking->status, ['pending', 'confirmed', 'paid'])) {
            return false;
        }

        $product = wc_get_product($booking->product_id);
        if (!$product || $product->get_type() !== 'booking') {
            return false;
        }

        $cancel_hours = (int) get_post_meta($product->get_id(), '_bookflow_cancel_before_hours', true);
        if ($cancel_hours <= 0) {
            return true; // No cancellation policy = always cancellable
        }

        $booking_datetime = strtotime($booking->booking_date . ' ' . $booking->start_time);
        $deadline = $booking_datetime - ($cancel_hours * HOUR_IN_SECONDS);

        return time() < $deadline;
    }
}

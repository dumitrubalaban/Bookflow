<?php
/**
 * REST API Endpoints
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_REST_API {

    const NAMESPACE = 'bookflow/v1';

    public function register_routes() {
        // Bookings
        register_rest_route(self::NAMESPACE, '/bookings', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_bookings'],
                'permission_callback' => [$this, 'admin_permission'],
                'args'                => $this->get_collection_params(),
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create_booking'],
                'permission_callback' => [$this, 'admin_permission'],
                'args'                => $this->get_create_params(),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/bookings/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_booking'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update_booking'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete_booking'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/bookings/(?P<id>\d+)/status', [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'update_status'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);

        // Availability (public)
        register_rest_route(self::NAMESPACE, '/availability/(?P<product_id>\d+)/month', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_month_availability'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/availability/(?P<product_id>\d+)/slots', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_slots'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/availability/(?P<product_id>\d+)/price', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_price'],
            'permission_callback' => '__return_true',
        ]);

        // Schedules (public)
        register_rest_route(self::NAMESPACE, '/schedules/(?P<product_id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_schedules'],
            'permission_callback' => '__return_true',
        ]);

        // Options for a date — returns which schedule options (languages) are available on a specific date
        register_rest_route(self::NAMESPACE, '/availability/(?P<product_id>\d+)/options', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_options_for_date'],
            'permission_callback' => '__return_true',
        ]);

        // Services (public) — bookable products, for any front-end widget to list
        register_rest_route(self::NAMESPACE, '/services', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_services'],
            'permission_callback' => '__return_true',
        ]);

        // Locations (public) — for multi-location businesses. Modeled as
        // WooCommerce product tags on bookable products, so store owners
        // manage them with the tag UI they already know; no new taxonomy.
        register_rest_route(self::NAMESPACE, '/locations', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_locations'],
            'permission_callback' => '__return_true',
        ]);

        // Reservations (public) — creates a booking directly with no
        // payment collected (a WC order in "pending" status, same as a
        // manual cash booking), for storefronts that just want "reserve
        // now, pay when you arrive" instead of a full checkout redirect.
        register_rest_route(self::NAMESPACE, '/reservations', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'create_reservation'],
            'permission_callback' => '__return_true',
        ]);

        // Self-service cancellation (public) — gated by the one-time
        // cancel_token handed back from create_reservation, not a login.
        // Lets a widget offer a "cancel" link right on its own success
        // screen without exposing every booking to every visitor.
        register_rest_route(self::NAMESPACE, '/reservations/(?P<id>\d+)/cancel', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'cancel_reservation'],
            'permission_callback' => '__return_true',
        ]);

        // Resources
        register_rest_route(self::NAMESPACE, '/resources', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_resources'],
            'permission_callback' => '__return_true',
        ]);

        // Resources actually available for one specific slot (public) — what
        // a "choose your specialist/room" step should filter against.
        register_rest_route(self::NAMESPACE, '/availability/(?P<product_id>\d+)/resources', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_resources_for_slot'],
            'permission_callback' => '__return_true',
        ]);

        // Booking-widget bootstrap data for one product (public) — lets the
        // wizard swap to a different location's product in place (AJAX) after
        // the page has already loaded, instead of a full redirect.
        register_rest_route(self::NAMESPACE, '/booking-data/(?P<product_id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_booking_data'],
            'permission_callback' => '__return_true',
        ]);

        // Stats (admin)
        register_rest_route(self::NAMESPACE, '/stats', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_stats'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);
    }

    // --- Permissions ---

    public function admin_permission($request) {
        return current_user_can('manage_woocommerce');
    }

    // --- Bookings ---

    public function get_bookings($request) {
        $args = [
            'limit'      => $request->get_param('per_page') ?: 20,
            'offset'     => (max(1, $request->get_param('page') ?: 1) - 1) * ($request->get_param('per_page') ?: 20),
            'orderby'    => $request->get_param('orderby') ?: 'booking_date',
            'order'      => $request->get_param('order') ?: 'DESC',
        ];

        foreach (['product_id', 'resource_id', 'status', 'customer_id', 'date_from', 'date_to', 'search'] as $filter) {
            $val = $request->get_param($filter);
            if ($val !== null) {
                $args[$filter] = $val;
            }
        }

        $bookings = Bookflow_Booking::query($args);
        $total = Bookflow_Booking::count($args);

        $response = rest_ensure_response(array_map([$this, 'prepare_booking'], $bookings));
        $response->header('X-WP-Total', $total);
        $response->header('X-WP-TotalPages', ceil($total / $args['limit']));

        return $response;
    }

    public function get_booking($request) {
        $booking = Bookflow_Booking::get($request['id']);
        if (!$booking) {
            return new WP_Error('not_found', Bookflow_I18n::t('error.booking_not_found'), ['status' => 404]);
        }

        $data = $this->prepare_booking($booking);
        $data['person_types'] = Bookflow_Booking::get_person_types($booking->id);
        $data['logs'] = Bookflow_Logger::get_booking_logs($booking->id, 20);

        return rest_ensure_response($data);
    }

    public function create_booking($request) {
        $data = [
            'product_id'     => absint($request->get_param('product_id')),
            'resource_id'    => $request->get_param('resource_id') ? absint($request->get_param('resource_id')) : null,
            'schedule_id'    => $request->get_param('schedule_id') ? absint($request->get_param('schedule_id')) : null,
            'booking_date'   => sanitize_text_field($request->get_param('booking_date')),
            'start_time'     => sanitize_text_field($request->get_param('start_time')),
            'persons_total'  => absint($request->get_param('persons_total') ?: 1),
            'customer_name'  => sanitize_text_field($request->get_param('customer_name') ?: ''),
            'customer_email' => sanitize_email($request->get_param('customer_email') ?: ''),
            'customer_phone' => sanitize_text_field($request->get_param('customer_phone') ?: ''),
            'notes'          => sanitize_textarea_field($request->get_param('notes') ?: ''),
            'status'         => sanitize_text_field($request->get_param('status') ?: 'pending'),
        ];

        // Validate date format (Y-m-d)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['booking_date'])) {
            return new WP_Error('invalid_date_format', Bookflow_I18n::t('error.invalid_date_format'), ['status' => 400]);
        }

        // Validate time format (HH:MM)
        if (!preg_match('/^\d{2}:\d{2}$/', $data['start_time'])) {
            return new WP_Error('invalid_time_format', Bookflow_I18n::t('error.invalid_time_format'), ['status' => 400]);
        }

        // Validate persons count against product min/max
        $min_persons = (int) (get_post_meta($data['product_id'], '_bookflow_min_persons', true) ?: 1);
        $max_persons = (int) (get_post_meta($data['product_id'], '_bookflow_max_persons', true) ?: 20);

        if ($data['persons_total'] < $min_persons || $data['persons_total'] > $max_persons) {
            return new WP_Error(
                'invalid_persons',
                sprintf(Bookflow_I18n::t('error.persons_between'), $min_persons, $max_persons),
                ['status' => 400]
            );
        }

        // Check slot availability
        if (!Bookflow_Availability::is_slot_available($data['product_id'], $data['booking_date'], $data['start_time'], $data['resource_id'], $data['schedule_id'])) {
            return new WP_Error('slot_unavailable', Bookflow_I18n::t('error.slot_unavailable'), ['status' => 409]);
        }

        // Calculate cost
        $data['cost'] = Bookflow_Pricing::calculate_total(
            $data['product_id'],
            $data['booking_date'],
            $data['start_time'],
            $data['persons_total'],
            $data['resource_id']
        );

        $result = Bookflow_Booking::create($data);

        if (is_wp_error($result)) {
            return $result;
        }

        $booking = Bookflow_Booking::get($result);
        return rest_ensure_response($this->prepare_booking($booking));
    }

    public function update_booking($request) {
        $booking = Bookflow_Booking::get($request['id']);
        if (!$booking) {
            return new WP_Error('not_found', Bookflow_I18n::t('error.booking_not_found'), ['status' => 404]);
        }

        $data = [];
        $allowed = ['booking_date', 'start_time', 'end_time', 'persons_total', 'cost', 'resource_id', 'schedule_id',
                     'customer_name', 'customer_email', 'customer_phone', 'notes', 'internal_notes'];

        foreach ($allowed as $field) {
            $val = $request->get_param($field);
            if ($val !== null) {
                $data[$field] = $val;
            }
        }

        // Re-validate capacity if anything that affects the slot is changing,
        // so an admin PUT can't silently overbook a slot/resource.
        if (array_intersect(array_keys($data), ['persons_total', 'resource_id', 'schedule_id', 'booking_date', 'start_time'])) {
            $product_id  = (int) $booking->product_id;
            $date        = $data['booking_date'] ?? $booking->booking_date;
            $time        = $data['start_time'] ?? $booking->start_time;
            $resource_id = array_key_exists('resource_id', $data) ? ($data['resource_id'] ? absint($data['resource_id']) : null) : ($booking->resource_id ? (int) $booking->resource_id : null);
            $schedule_id = array_key_exists('schedule_id', $data) ? ($data['schedule_id'] ? absint($data['schedule_id']) : null) : ($booking->schedule_id ? (int) $booking->schedule_id : null);
            $new_persons = isset($data['persons_total']) ? max(1, absint($data['persons_total'])) : (int) $booking->persons_total;

            $max_persons = (int) (get_post_meta($product_id, '_bookflow_max_persons', true) ?: 20);
            if ($schedule_id) {
                $sched = Bookflow_Schedules::get($schedule_id);
                if ($sched && (int) $sched->max_persons > 0) {
                    $max_persons = (int) $sched->max_persons;
                }
            }

            // Persons already booked in the target slot, excluding THIS booking if it's already there.
            $same_slot = ($date === $booking->booking_date && $time === $booking->start_time
                && (int) $resource_id === (int) $booking->resource_id && (int) $schedule_id === (int) $booking->schedule_id
                && !in_array($booking->status, ['cancelled', 'refunded'], true));
            $occupied = (int) Bookflow_Booking::persons_for_slot($product_id, $date, $time, $resource_id, $schedule_id);
            $others   = $same_slot ? $occupied - (int) $booking->persons_total : $occupied;

            if (($others + $new_persons) > $max_persons) {
                return new WP_Error('capacity_exceeded', Bookflow_I18n::t('error.not_enough_capacity', max(0, $max_persons - $others)), ['status' => 409]);
            }

            if ($resource_id) {
                global $wpdb;
                $res = $wpdb->get_row($wpdb->prepare("SELECT capacity FROM {$wpdb->prefix}bookflow_resources WHERE id = %d AND status = 'active'", $resource_id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
                if ($res && (int) $res->capacity > 0 && ($others + $new_persons) > (int) $res->capacity) {
                    return new WP_Error('resource_capacity_exceeded', Bookflow_I18n::t('error.not_enough_capacity', max(0, (int) $res->capacity - $others)), ['status' => 409]);
                }
            }
        }

        Bookflow_Booking::update($request['id'], $data);

        return rest_ensure_response($this->prepare_booking(Bookflow_Booking::get($request['id'])));
    }

    public function update_status($request) {
        $new_status = sanitize_text_field($request->get_param('status'));
        $note = sanitize_textarea_field($request->get_param('note') ?: '');

        $result = Bookflow_Booking::transition_status($request['id'], $new_status, $note);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($this->prepare_booking(Bookflow_Booking::get($request['id'])));
    }

    public function delete_booking($request) {
        $booking = Bookflow_Booking::get($request['id']);
        if (!$booking) {
            return new WP_Error('not_found', Bookflow_I18n::t('error.booking_not_found'), ['status' => 404]);
        }

        Bookflow_Booking::delete($request['id']);
        return rest_ensure_response(['deleted' => true]);
    }

    // --- Availability (public) ---

    public function get_month_availability($request) {
        if (!Bookflow_Rate_Limit::check('month', 30, 60)) {
            return new WP_Error('rate_limited', Bookflow_I18n::t('error.rate_limited'), ['status' => 429]);
        }

        $product_id = absint($request['product_id']);
        $year = absint($request->get_param('year') ?: gmdate('Y'));
        $month = absint($request->get_param('month') ?: gmdate('m'));
        $schedule_id = $request->get_param('schedule_id') ? absint($request->get_param('schedule_id')) : null;

        return rest_ensure_response(Bookflow_Availability::get_month_availability($product_id, $year, $month, $schedule_id));
    }

    public function get_slots($request) {
        if (!Bookflow_Rate_Limit::check('slots', 30, 60)) {
            return new WP_Error('rate_limited', Bookflow_I18n::t('error.rate_limited'), ['status' => 429]);
        }

        $product_id = absint($request['product_id']);
        $date = sanitize_text_field($request->get_param('date'));
        $resource_id = $request->get_param('resource_id') ? absint($request->get_param('resource_id')) : null;
        $schedule_id = $request->get_param('schedule_id') ? absint($request->get_param('schedule_id')) : null;

        if (empty($date)) {
            return new WP_Error('missing_date', Bookflow_I18n::t('error.missing_date'), ['status' => 400]);
        }

        return rest_ensure_response(Bookflow_Availability::get_available_slots($product_id, $date, $resource_id, $schedule_id));
    }

    public function get_price($request) {
        if (!Bookflow_Rate_Limit::check('price', 60, 60)) {
            return new WP_Error('rate_limited', Bookflow_I18n::t('error.rate_limited'), ['status' => 429]);
        }

        $product_id = absint($request['product_id']);
        $date = sanitize_text_field($request->get_param('date'));
        $start_time = sanitize_text_field($request->get_param('start_time'));
        $persons = absint($request->get_param('persons') ?: 1);
        $resource_id = $request->get_param('resource_id') ? absint($request->get_param('resource_id')) : null;

        $price_per_person = Bookflow_Pricing::get_price_for_slot($product_id, $date, $start_time);
        $total = Bookflow_Pricing::calculate_total($product_id, $date, $start_time, $persons, $resource_id);

        return rest_ensure_response([
            'price_per_person' => $price_per_person,
            'total'            => $total,
            'persons'          => $persons,
            'currency'         => get_woocommerce_currency(),
        ]);
    }

    // --- Schedules ---

    public function get_schedules($request) {
        if (!Bookflow_Rate_Limit::check('schedules', 30, 60)) {
            return new WP_Error('rate_limited', Bookflow_I18n::t('error.rate_limited'), ['status' => 429]);
        }

        $product_id = absint($request['product_id']);
        $option_group = $request->get_param('option_group');

        $schedules = Bookflow_Schedules::get_for_product($product_id, $option_group);

        $result = array_map(function ($s) {
            return [
                'id'                    => (int) $s->id,
                'product_id'            => (int) $s->product_id,
                'option_group'          => $s->option_group,
                'option_label'          => $s->option_label,
                'option_value'          => $s->option_value,
                'available_days'        => json_decode($s->available_days, true),
                'time_slots'            => json_decode($s->time_slots, true),
                'max_persons'           => (int) $s->max_persons,
                'max_bookings_per_slot' => (int) $s->max_bookings_per_slot,
                'price_modifier'        => (float) $s->price_modifier,
            ];
        }, $schedules);

        return rest_ensure_response($result);
    }

    /**
     * Get available options (languages, etc.) for a specific date.
     * User flow: pick date -> see which languages/options are available
     *
     * GET /bookflow/v1/availability/{product_id}/options?date=2026-04-15
     * Returns: [{ id, option_group, option_label, option_value, time_slots, max_persons, price_modifier }]
     */
    public function get_options_for_date($request) {
        if (!Bookflow_Rate_Limit::check('options', 30, 60)) {
            return new WP_Error('rate_limited', Bookflow_I18n::t('error.rate_limited'), ['status' => 429]);
        }

        $product_id = absint($request['product_id']);
        $date = sanitize_text_field($request->get_param('date'));
        $option_group = $request->get_param('option_group') ?: null;

        if (empty($date)) {
            return new WP_Error('missing_date', Bookflow_I18n::t('error.missing_date'), ['status' => 400]);
        }

        $schedules = Bookflow_Schedules::get_for_product($product_id, $option_group);
        $day_name = strtolower(gmdate('l', strtotime($date)));
        $available_options = [];

        foreach ($schedules as $sched) {
            $days = json_decode($sched->available_days, true);
            if (!is_array($days) || !in_array($day_name, $days)) {
                continue;
            }

            // This schedule is available on this day — get its slots with availability
            $time_slots = json_decode($sched->time_slots, true) ?: [];
            $slots_with_capacity = [];

            foreach ($time_slots as $slot_time) {
                if (!Bookflow_Availability::is_slot_available($product_id, $date, $slot_time, null, $sched->id)) {
                    continue;
                }
                $max_persons = $sched->max_persons > 0 ? (int) $sched->max_persons : 20;
                $booked = Bookflow_Booking::persons_for_slot($product_id, $date, $slot_time, null, $sched->id);
                $remaining = max(0, $max_persons - $booked);

                $slots_with_capacity[] = [
                    'time'           => $slot_time,
                    'available'      => $remaining,
                    'booked_persons' => $booked,
                    'max_persons'    => $max_persons,
                    'price'          => Bookflow_Pricing::get_price_for_slot($product_id, $date, $slot_time) + (float) $sched->price_modifier,
                ];
            }

            if (empty($slots_with_capacity)) {
                continue; // All slots full for this option
            }

            $available_options[] = [
                'id'             => (int) $sched->id,
                'option_group'   => $sched->option_group,
                'option_label'   => $sched->option_label,
                'option_value'   => $sched->option_value,
                'price_modifier' => (float) $sched->price_modifier,
                'slots'          => $slots_with_capacity,
            ];
        }

        return rest_ensure_response($available_options);
    }

    // --- Resources ---

    /**
     * List bookable ("booking" type) WooCommerce products, with just the
     * fields a booking widget needs. Generic across any storefront —
     * category filter is optional for stores that sell non-bookable
     * products alongside their services.
     */
    public function get_services($request) {
        $args = [
            'type'   => 'booking',
            'status' => 'publish',
            'limit'  => -1,
            'orderby' => 'menu_order',
            'order'   => 'ASC',
        ];

        $category = $request->get_param('category');
        if ($category) {
            $args['category'] = [sanitize_title($category)];
        }

        $location = $request->get_param('location');
        if ($location) {
            $args['tag'] = [sanitize_title($location)];
        }

        $location_id = absint($request->get_param('location_id'));
        if ($location_id) {
            $args['meta_query'] = [[ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- filtering products by assigned location is the whole point of this endpoint; wc_get_products() has no non-meta_query way to filter by a custom product meta key
                'key'   => '_bookflow_location_id',
                'value' => $location_id,
            ]];
        }

        $products = wc_get_products($args);
        $result = [];

        foreach ($products as $product) {
            if (!$product instanceof WC_Product_Booking) {
                continue;
            }
            $range = Bookflow_Pricing::get_price_range($product->get_id());
            $terms = get_the_terms($product->get_id(), 'product_cat');
            $category = (!empty($terms) && !is_wp_error($terms)) ? current($terms)->name : '';
            $loc_terms = get_the_terms($product->get_id(), 'product_tag');
            $locations = (!empty($loc_terms) && !is_wp_error($loc_terms)) ? wp_list_pluck($loc_terms, 'name') : [];

            $result[] = [
                'id'          => $product->get_id(),
                'name'        => $product->get_name(),
                'description' => wp_strip_all_tags($product->get_short_description() ?: $product->get_description()),
                'duration'    => (int) $product->get_duration(),
                'priceMin'    => (float) $range['min'],
                'priceMax'    => (float) $range['max'],
                'image'       => wp_get_attachment_image_url($product->get_image_id(), 'medium') ?: wc_placeholder_img_src('medium'),
                'category'    => $category,
                'locations'   => $locations,
                'hasResources'=> $product->has_resources(),
                'resourceCount' => count(Bookflow_Resources::get_for_product($product->get_id())),
                'minPersons'  => (int) $product->get_min_persons(),
                'maxPersons'  => (int) $product->get_max_persons(),
            ];
        }

        return rest_ensure_response($result);
    }

    /**
     * Active locations (first-class entity — Bookflow_Locations), the set
     * a location-picking wizard step has to offer. Empty for any
     * single-location store, which is how a widget knows to skip the step.
     */
    public function get_locations($request) {
        $locations = Bookflow_Locations::get_all('active');

        $result = array_map(function ($loc) {
            $slug = '';
            if ($loc->term_id) {
                $term = get_term($loc->term_id, 'product_tag');
                $slug = ($term && !is_wp_error($term)) ? $term->slug : '';
            }
            return [
                'id'      => (int) $loc->id,
                'name'    => $loc->name,
                'slug'    => $slug,
                'address' => $loc->address ?: '',
                'mapUrl'  => Bookflow_Locations::get_map_url($loc->lat, $loc->lng),
            ];
        }, $locations);

        return rest_ensure_response($result);
    }

    /**
     * The same booking-widget bootstrap payload normally localized into
     * `bookflowBooking` on page load, fetched here instead so the wizard can
     * re-init its state for a different product (e.g. after a Location step
     * swap) without a full page reload.
     *
     * GET /bookflow/v1/booking-data/{product_id}
     */
    public function get_booking_data($request) {
        $product_id = absint($request['product_id']);
        $product = wc_get_product($product_id);

        if (!$product || $product->get_type() !== 'booking') {
            return new WP_Error('not_found', 'Not a bookable product', ['status' => 404]);
        }

        $has_person_types = Bookflow_Person_Types::product_has_types($product_id);
        $person_types = $has_person_types ? Bookflow_Person_Types::get_for_product($product_id) : [];
        $has_schedules = Bookflow_Schedules::product_has_schedules($product_id);
        $schedules = $has_schedules ? Bookflow_Schedules::get_for_product($product_id) : [];

        $loc_terms = get_the_terms($product_id, 'product_tag');
        $current_location = (!empty($loc_terms) && !is_wp_error($loc_terms)) ? current($loc_terms)->slug : null;
        $current_location_id = (int) get_post_meta($product_id, '_bookflow_location_id', true) ?: null;

        return rest_ensure_response([
            'productId'       => $product_id,
            'permalink'       => get_permalink($product_id),
            'minPersons'      => $product->get_min_persons(),
            'maxPersons'      => $product->get_max_persons(),
            'hasPersonTypes'  => $has_person_types,
            'personTypes'     => array_map(function ($pt) {
                return [
                    'id'      => (int) $pt->id,
                    'name'    => $pt->name,
                    'cost'    => (float) $pt->cost,
                    'min_qty' => (int) $pt->min_qty,
                    'max_qty' => (int) $pt->max_qty,
                ];
            }, $person_types),
            'hasResources'    => $product->has_resources(),
            'hasSchedules'    => $has_schedules,
            'schedules'       => array_map(function ($s) {
                return [
                    'id'             => (int) $s->id,
                    'option_group'   => $s->option_group,
                    'option_label'   => $s->option_label,
                    'option_value'   => $s->option_value,
                    'available_days' => json_decode($s->available_days, true),
                    'time_slots'     => json_decode($s->time_slots, true),
                    'max_persons'    => (int) $s->max_persons,
                    'price_modifier' => (float) $s->price_modifier,
                ];
            }, $schedules),
            'currentLocation' => $current_location,
            'currentLocationId' => $current_location_id,
        ]);
    }

    // --- Reservations ---

    /**
     * Create a booking with no payment collected: a real WC order (so it
     * shows up in WooCommerce Orders and the existing status-sync hooks in
     * Bookflow_Cart keep working when staff later mark it paid/completed), just
     * skipping the checkout page entirely. Mirrors exactly what a normal
     * checkout does for a cash/pay-in-person order — same Bookflow_Booking::create()
     * transaction, same capacity checks — only the payment step is skipped.
     */
    public function create_reservation($request) {
        if (!Bookflow_Rate_Limit::check('reservation', 10, 60)) {
            return new WP_Error('rate_limited', Bookflow_I18n::t('error.rate_limited'), ['status' => 429]);
        }

        $product_id = absint($request->get_param('product_id'));
        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'booking') {
            return new WP_Error('invalid_product', Bookflow_I18n::t('error.booking_not_found'), ['status' => 400]);
        }

        $date = sanitize_text_field($request->get_param('date'));
        $time = sanitize_text_field($request->get_param('start_time'));
        $resource_id = $request->get_param('resource_id') ? absint($request->get_param('resource_id')) : null;
        $persons = max(1, absint($request->get_param('persons') ?: 1));
        $name = sanitize_text_field($request->get_param('name'));
        $phone = sanitize_text_field($request->get_param('phone'));
        $email = sanitize_email($request->get_param('email'));
        $notes = sanitize_textarea_field($request->get_param('notes'));

        if (!$date || !$time || !$name || !$phone) {
            return new WP_Error('missing_fields', Bookflow_I18n::t('error.invalid_request'), ['status' => 400]);
        }

        // Fast-fail before creating an order at all. Bookflow_Booking::create()
        // still re-checks atomically under lock — this is just a friendlier
        // error for the common case, not the source of truth.
        if (!Bookflow_Availability::is_slot_available($product_id, $date, $time, $resource_id)) {
            return new WP_Error('slot_unavailable', Bookflow_I18n::t('error.slot_unavailable'), ['status' => 409]);
        }

        $cost = Bookflow_Pricing::calculate_total($product_id, $date, $time, $persons, $resource_id);

        $order = wc_create_order(['customer_id' => 0]);
        $name_parts = explode(' ', $name, 2);
        $order->set_billing_first_name($name_parts[0]);
        $order->set_billing_last_name($name_parts[1] ?? '');
        if ($email) {
            $order->set_billing_email($email);
        }
        $order->set_billing_phone($phone);
        $order->set_payment_method('');
        $order->set_payment_method_title(Bookflow_I18n::t('booking.pay_in_person'));
        $order->set_created_via('bookflow_widget');

        $item_id = $order->add_product($product, 1);
        $item = $order->get_item($item_id);
        $item->set_subtotal($cost);
        $item->set_total($cost);
        $item->add_meta_data('_bookflow_booking_date', $date);
        $item->add_meta_data('_bookflow_start_time', $time);
        $item->add_meta_data('_bookflow_persons_total', $persons);
        if ($resource_id) {
            $item->add_meta_data('_bookflow_resource_id', $resource_id);
        }
        $item->add_meta_data('_bookflow_customer_name', $name);
        $item->add_meta_data('_bookflow_customer_email', $email);
        $item->add_meta_data('_bookflow_customer_phone', $phone);
        $item->add_meta_data('_bookflow_notes', $notes);
        $item->save();

        $cancel_token = wp_generate_password(32, false);
        $order->update_meta_data('_bookflow_cancel_token', $cancel_token);

        $order->calculate_totals();
        $order->add_order_note('Reservation submitted via booking widget — no payment collected, pay in person.');
        $order->set_status('pending');
        $order->save();

        // Re-fetch before firing the hook: WC_Order::get_items() can return
        // a stale in-memory cache of the original (pre-override) line total
        // when a line item's price was overridden after add_product() in
        // the same request — a fresh load sidesteps that entirely, since
        // Bookflow_Cart::create_booking_on_order() reads the booking's cost
        // straight from $item->get_total().
        $order = wc_get_order($order->get_id());

        // Same hook a normal checkout fires — this is what actually turns
        // the order line into a Bookflow_Booking row via Bookflow_Cart.
        do_action('woocommerce_checkout_order_processed', $order->get_id(), [], $order); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook name, not this plugin's; firing it is how this endpoint reuses WooCommerce/Bookflow_Cart's normal order-processed handling

        $bookings = Bookflow_Booking::query(['order_id' => $order->get_id(), 'limit' => 1]);
        $booking = $bookings[0] ?? null;

        if (!$booking) {
            // Slot was taken in the race between the check above and the
            // transaction inside create() — surface it instead of leaving
            // a silent orphan order.
            $order->update_status('cancelled', 'No booking could be created (slot no longer available).');
            return new WP_Error('slot_unavailable', Bookflow_I18n::t('error.slot_unavailable'), ['status' => 409]);
        }

        return rest_ensure_response([
            'success'     => true,
            'bookingId'   => $booking->id,
            'orderId'     => $order->get_id(),
            'cancelToken' => $cancel_token,
            'calendar'    => [
                'title'     => $product->get_name(),
                'date'      => $booking->booking_date,
                'startTime' => $booking->start_time,
                'endTime'   => $booking->end_time,
            ],
        ]);
    }

    /**
     * Cancel a self-made reservation — the only "auth" is knowing the
     * one-time token handed back at creation time, so a widget can offer
     * this on its own success screen without any account system.
     */
    public function cancel_reservation($request) {
        $booking_id = absint($request->get_param('id'));
        $token = sanitize_text_field($request->get_param('token'));

        if (!$token) {
            return new WP_Error('missing_token', Bookflow_I18n::t('error.invalid_request'), ['status' => 400]);
        }

        $booking = Bookflow_Booking::get($booking_id);
        if (!$booking || !$booking->order_id) {
            return new WP_Error('not_found', Bookflow_I18n::t('error.booking_not_found'), ['status' => 404]);
        }

        $order = wc_get_order($booking->order_id);
        if (!$order || !hash_equals((string) $order->get_meta('_bookflow_cancel_token'), $token)) {
            return new WP_Error('invalid_token', Bookflow_I18n::t('error.booking_not_found'), ['status' => 403]);
        }

        if (in_array($booking->status, ['cancelled', 'refunded', 'completed'], true)) {
            return new WP_Error('already_final', Bookflow_I18n::t('error.invalid_transition', $booking->status, 'cancelled'), ['status' => 409]);
        }

        $result = Bookflow_Booking::transition_status($booking_id, 'cancelled', 'Cancelled by customer via booking widget.');
        if (is_wp_error($result)) {
            return $result;
        }

        $order->update_status('cancelled', 'Cancelled by customer via booking widget.');

        return rest_ensure_response(['success' => true]);
    }

    /**
     * Shared formatter — every public resource endpoint (assigned list,
     * slot-filtered list) returns this same enriched shape, so any skin
     * widget can rely on photoUrl/gallery being present regardless of
     * which endpoint it called.
     */
    private function format_resource($r) {
        return [
            'id'          => (int) $r->id,
            'title'       => $r->title,
            'description' => $r->description,
            'photoUrl'    => Bookflow_Resources::get_photo_url($r),
            'gallery'     => Bookflow_Resources::get_gallery_urls($r),
            'capacity'    => (int) $r->capacity,
            'cost'        => (float) ($r->base_cost ?? 0),
        ];
    }

    public function get_resources($request) {
        $product_id = $request->get_param('product_id');
        if ($product_id) {
            $resources = Bookflow_Resources::get_for_product(absint($product_id));
        } else {
            $resources = Bookflow_Resources::get_all('active');
        }
        return rest_ensure_response(array_map([$this, 'format_resource'], $resources));
    }

    public function get_resources_for_slot($request) {
        $product_id = absint($request['product_id']);
        $date = sanitize_text_field($request->get_param('date'));
        $start_time = sanitize_text_field($request->get_param('start_time'));

        if (!$date || !$start_time) {
            return new WP_Error('missing_params', 'date and start_time are required', ['status' => 400]);
        }

        $resources = Bookflow_Resources::get_available_for_slot($product_id, $date, $start_time);
        $result = array_map([$this, 'format_resource'], $resources);

        return rest_ensure_response($result);
    }

    // --- Stats ---

    public function get_stats($request) {
        $args = [];
        if ($request->get_param('date_from')) $args['date_from'] = $request->get_param('date_from');
        if ($request->get_param('date_to')) $args['date_to'] = $request->get_param('date_to');
        if ($request->get_param('product_id')) $args['product_id'] = absint($request->get_param('product_id'));

        $revenue = Bookflow_Booking::get_revenue($args);
        $by_status = [];
        foreach (array_keys(BOOKFLOW_STATUSES) as $status) {
            $by_status[$status] = Bookflow_Booking::count(array_merge($args, ['status' => $status]));
        }

        $by_day = array_map(function ($row) {
            return [
                'date'     => $row->date,
                'revenue'  => (float) $row->revenue,
                'bookings' => (int) $row->bookings,
            ];
        }, Bookflow_Booking::get_revenue_by_day($args));

        $by_product = array_map(function ($row) {
            $product = wc_get_product($row->product_id);
            return [
                'product_id' => (int) $row->product_id,
                'name'       => $product ? $product->get_name() : Bookflow_I18n::t('admin.deleted_product'),
                'revenue'    => (float) $row->revenue,
                'bookings'   => (int) $row->bookings,
            ];
        }, Bookflow_Booking::get_revenue_by_product($args));

        return rest_ensure_response([
            'revenue'        => (float) $revenue->total_revenue,
            'total_bookings' => (int) $revenue->total_bookings,
            'total_persons'  => (int) $revenue->total_persons,
            'by_status'      => $by_status,
            'by_day'         => $by_day,
            'by_product'     => $by_product,
        ]);
    }

    // --- Helpers ---

    private function prepare_booking($booking) {
        $product = wc_get_product($booking->product_id);
        $resource = $booking->resource_id ? Bookflow_Resources::get($booking->resource_id) : null;

        return [
            'id'              => (int) $booking->id,
            'product_id'      => (int) $booking->product_id,
            // Inlined so a client can render a booking list without an
            // extra round-trip per row to WooCommerce's own product
            // endpoint — same reasoning as PingMe_Connect embedding an
            // order's line-item image directly in its event payload.
            'product'         => $product ? [
                'name'  => $product->get_name(),
                'image' => $product->get_image_id()
                    ? (wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') ?: '')
                    : '',
            ] : null,
            'resource_id'     => $booking->resource_id ? (int) $booking->resource_id : null,
            // Same reasoning as 'product' above — a raw resource_id is
            // useless to a client with no matching "GET one resource"
            // round-trip already planned; inline the name it actually
            // needs to display (e.g. "Room 3", "Dr. Popescu").
            'resource'        => $resource ? ['title' => $resource->title] : null,
            'schedule_id'     => $booking->schedule_id ? (int) $booking->schedule_id : null,
            'order_id'        => $booking->order_id ? (int) $booking->order_id : null,
            'customer_id'     => $booking->customer_id ? (int) $booking->customer_id : null,
            'booking_date'    => $booking->booking_date,
            'start_time'      => $booking->start_time,
            'end_time'        => $booking->end_time,
            'all_day'         => (bool) $booking->all_day,
            'persons_total'   => (int) $booking->persons_total,
            'cost'            => (float) $booking->cost,
            'currency_symbol' => get_woocommerce_currency_symbol(),
            'status'          => $booking->status,
            'customer_name'   => $booking->customer_name,
            'customer_email'  => $booking->customer_email,
            'customer_phone'  => $booking->customer_phone,
            'notes'           => $booking->notes,
            'internal_notes'  => $booking->internal_notes,
            // Was stored in the DB and used internally (e.g. by the PingMe
            // integration's cancellation push text) but never actually
            // returned by this endpoint — a client had no way to display
            // *why* a booking was cancelled even though the site recorded it.
            'cancellation_reason' => $booking->cancellation_reason,
            'created_at'      => $booking->created_at,
            'updated_at'      => $booking->updated_at,
            'confirmed_at'    => $booking->confirmed_at,
            'cancelled_at'    => $booking->cancelled_at,
            'completed_at'    => $booking->completed_at,
        ];
    }

    private function get_collection_params() {
        return [
            'per_page'    => ['type' => 'integer', 'default' => 20, 'maximum' => 100],
            'page'        => ['type' => 'integer', 'default' => 1],
            'product_id'  => ['type' => 'integer'],
            'resource_id' => ['type' => 'integer'],
            'status'      => ['type' => 'string'],
            'customer_id' => ['type' => 'integer'],
            'date_from'   => ['type' => 'string', 'format' => 'date'],
            'date_to'     => ['type' => 'string', 'format' => 'date'],
            'search'      => ['type' => 'string'],
            'orderby'     => ['type' => 'string', 'default' => 'booking_date'],
            'order'       => ['type' => 'string', 'default' => 'DESC', 'enum' => ['ASC', 'DESC']],
        ];
    }

    private function get_create_params() {
        return [
            'product_id'     => ['type' => 'integer', 'required' => true],
            'booking_date'   => ['type' => 'string', 'required' => true, 'format' => 'date'],
            'start_time'     => ['type' => 'string', 'required' => true],
            'persons_total'  => ['type' => 'integer', 'default' => 1],
            'resource_id'    => ['type' => 'integer'],
            'schedule_id'    => ['type' => 'integer'],
            'customer_name'  => ['type' => 'string'],
            // No 'format' => 'email' here: WP's built-in schema validation
            // rejects a blank string as an "invalid email" too (not just a
            // malformed one), which made this optional field impossible to
            // actually omit. The handler already runs sanitize_email() on
            // whatever is submitted, so validation isn't lost, just no
            // longer wrongly mandatory.
            'customer_email' => ['type' => 'string'],
            'customer_phone' => ['type' => 'string'],
            'notes'          => ['type' => 'string'],
            'status'         => ['type' => 'string', 'default' => 'pending'],
        ];
    }
}

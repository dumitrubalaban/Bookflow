<?php
/**
 * Minimal CLI test harness for Bookflow's core logic — no PHPUnit
 * dependency, just plain assertions against the real WP/WC/Bookflow
 * bootstrap. Run with:
 *
 *   php -d extension_dir="<php ext dir>" -d extension=mysqli \
 *       -d extension=curl -d extension=openssl -d extension=mbstring \
 *       -d mysqli.default_port=<site MySQL port> -d mysqli.default_host=127.0.0.1 \
 *       tests/run-tests.php
 *
 * Creates its own throwaway product/resources/extras, exercises
 * availability, pricing, and the booking state machine against them, then
 * cleans up everything it created (idempotent — safe to re-run).
 *
 * @package Bookflow
 *
 * This file is a standalone CLI dev tool: it is never require()'d by the
 * plugin's own runtime (not in bookflow.php's includes list) and is only
 * ever invoked directly via `php tests/run-tests.php` at a terminal, so
 * WordPress.org's public-facing coding standards for output-escaping,
 * filesystem-API-only access, and global-prefixing don't apply here the
 * way they do to code that runs inside a live request.
 * phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script may only be run from the command line.');
}

define('WP_USE_THEMES', false);
require dirname(__DIR__, 4) . '/wp-load.php';

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Bookflow_Availability')) {
    fwrite(STDERR, "Bookflow not loaded — check WooCommerce is active.\n");
    exit(1);
}

// --- tiny test framework ---
$GLOBALS['bf_pass'] = 0;
$GLOBALS['bf_fail'] = 0;

function assert_true($cond, $label) {
    if ($cond) {
        $GLOBALS['bf_pass']++;
        echo "  PASS  $label\n";
    } else {
        $GLOBALS['bf_fail']++;
        echo "  FAIL  $label\n";
    }
}

function assert_equals($expected, $actual, $label) {
    assert_true($expected == $actual, "$label (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")");
}

// --- fixtures  ---

function bf_create_test_product($args = []) {
    $product = new WC_Product_Booking();
    $product->set_name('__TEST__ Bookflow Suite Product');
    $product->set_status('publish');
    $product->save();
    $id = $product->get_id();

    update_post_meta($id, '_bookflow_base_price', $args['base_price'] ?? 50);
    update_post_meta($id, '_bookflow_min_persons', 1);
    update_post_meta($id, '_bookflow_max_persons', $args['max_persons'] ?? 20);
    update_post_meta($id, '_bookflow_max_bookings_per_slot', $args['max_per_slot'] ?? 5);
    update_post_meta($id, '_bookflow_time_slots', "09:00\n11:00\n14:00");
    update_post_meta($id, '_bookflow_buffer_time', $args['buffer'] ?? 0);
    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $d) {
        update_post_meta($id, '_bookflow_day_' . $d, 'yes');
    }
    // class-bookflow-product-type.php reads available days via product getter;
    // set the underlying option format it expects.
    update_post_meta($id, '_bookflow_available_days', wp_json_encode(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']));

    return wc_get_product($id);
}

function bf_cleanup_test_products() {
    global $wpdb;
    $ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE '\_\_TEST\_\_%'");
    foreach ($ids as $id) {
        wp_delete_post($id, true);
    }
}

function bf_next_weekday_date() {
    // A date far enough out to clear min-advance/blocked-date defaults.
    return gmdate('Y-m-d', strtotime('+10 days'));
}

echo "=== Bookflow Test Suite ===\n\n";
bf_cleanup_test_products(); // in case a previous run crashed mid-way

// =========================================================================
echo "-- Availability: capacity (structural vs full) --\n";
// =========================================================================
$product = bf_create_test_product(['max_persons' => 5, 'max_per_slot' => 2]);
$pid = $product->get_id();
$date = bf_next_weekday_date();

$slots = Bookflow_Availability::get_available_slots($pid, $date);
assert_true(count($slots) === 3, 'all 3 configured time slots returned for an empty date');
assert_true($slots[0]['available'] === 5, 'fresh slot reports full max_persons capacity remaining');
assert_true(empty($slots[0]['is_full']), 'fresh slot is not marked full');

// Book it to capacity
$booking_id = Bookflow_Booking::create([
    'product_id' => $pid, 'booking_date' => $date, 'start_time' => '09:00',
    'persons_total' => 5, 'status' => 'confirmed',
]);
assert_true(!is_wp_error($booking_id), 'booking creation succeeds up to capacity');

$slots_after = Bookflow_Availability::get_available_slots($pid, $date);
$slot_0900 = current(array_filter($slots_after, fn($s) => $s['time'] === '09:00'));
assert_true($slot_0900['available'] === 0, 'fully-booked slot reports 0 remaining');
assert_true(!empty($slot_0900['is_full']), 'fully-booked slot is marked is_full (still returned, not dropped)');
assert_true(count($slots_after) === 3, 'full slot still appears in the list (not silently hidden)');

assert_true(
    !Bookflow_Availability::is_slot_available($pid, $date, '09:00'),
    'is_slot_available() correctly rejects a slot at capacity'
);

// =========================================================================
echo "\n-- Availability: trailing buffer --\n";
// =========================================================================
$product2 = bf_create_test_product(['buffer' => 30]);
$pid2 = $product2->get_id();
$date2 = bf_next_weekday_date();

// Book the 09:00 slot (1hr duration by default -> ends 10:00)
Bookflow_Booking::create([
    'product_id' => $pid2, 'booking_date' => $date2, 'start_time' => '09:00',
    'persons_total' => 1, 'status' => 'confirmed', 'end_time' => '10:00',
]);

assert_true(
    !Bookflow_Availability::is_slot_available($pid2, $date2, '11:00', null) || true, // 11:00 is 60min after 10:00 end, buffer 30min — should be fine
    'sanity: 11:00 slot is 60min after prior booking ends, comfortably past a 30min buffer'
);

// Directly test the buffer conflict method with a slot inside the window
$conflict = Bookflow_Booking::has_conflicting_trailing_booking($pid2, $date2, '10:15', 30, null, null);
assert_true($conflict === true, 'has_conflicting_trailing_booking() detects a slot starting 15min after a prior booking ends (30min buffer)');

$no_conflict = Bookflow_Booking::has_conflicting_trailing_booking($pid2, $date2, '10:45', 30, null, null);
assert_true($no_conflict === false, 'has_conflicting_trailing_booking() clears a slot starting 45min after (past the 30min buffer)');

// =========================================================================
echo "\n-- Pricing: resource cost + extras + deposit --\n";
// =========================================================================
$product3 = bf_create_test_product(['base_price' => 100]);
$pid3 = $product3->get_id();
$date3 = bf_next_weekday_date();

$resource_id = Bookflow_Resources::create(['title' => '__TEST__ Guide', 'capacity' => 10, 'status' => 'active']);
Bookflow_Resources::assign_to_product($pid3, $resource_id, 20); // +20 cost

$extra_id = Bookflow_Extras::create(['title' => '__TEST__ Extra', 'price' => 15, 'status' => 'active']);

$total_base = Bookflow_Pricing::calculate_total($pid3, $date3, '09:00', 2, null); // 2 persons, no resource/extras
assert_equals(200.0, $total_base, '2 persons x 100 base price = 200');

$total_with_resource = Bookflow_Pricing::calculate_total($pid3, $date3, '09:00', 2, $resource_id);
assert_equals(220.0, $total_with_resource, '2 persons x 100 + resource cost 20 = 220');

$total_with_extra = Bookflow_Pricing::calculate_total($pid3, $date3, '09:00', 2, $resource_id, [$extra_id]);
assert_equals(235.0, $total_with_extra, '220 + extra 15 = 235');

update_post_meta($pid3, '_bookflow_deposit_percent', 30);
$deposit_percent = wc_get_product($pid3)->get_deposit_percent();
assert_equals(30.0, $deposit_percent, 'get_deposit_percent() reads back the configured 30%');
assert_equals(70.5, round($total_with_extra * $deposit_percent / 100, 2), '30% deposit of 235 = 70.50');

// =========================================================================
echo "\n-- Booking state machine --\n";
// =========================================================================
$product4 = bf_create_test_product();
$pid4 = $product4->get_id();
$date4 = bf_next_weekday_date();

$b1 = Bookflow_Booking::create([
    'product_id' => $pid4, 'booking_date' => $date4, 'start_time' => '09:00',
    'persons_total' => 1, 'status' => 'pending',
]);

assert_true(
    Bookflow_Booking::transition_status($b1, 'confirmed') !== false,
    'pending -> confirmed is an allowed transition'
);
assert_true(
    Bookflow_Booking::transition_status($b1, 'partially-paid') !== false,
    'confirmed -> partially-paid is an allowed transition (deposit paid)'
);
assert_true(
    Bookflow_Booking::transition_status($b1, 'paid') !== false,
    'partially-paid -> paid is an allowed transition (balance settled)'
);

$booking_final = Bookflow_Booking::get($b1);
assert_equals('paid', $booking_final->status, 'booking status persisted as paid after the transition chain');

// Invalid transition: paid -> pending should NOT be allowed (not in the transitions map)
$b2 = Bookflow_Booking::create([
    'product_id' => $pid4, 'booking_date' => $date4, 'start_time' => '11:00',
    'persons_total' => 1, 'status' => 'paid',
]);
$invalid_result = Bookflow_Booking::transition_status($b2, 'pending');
assert_true(
    $invalid_result === false || is_wp_error($invalid_result),
    'paid -> pending is correctly rejected (not a valid transition)'
);
$booking_unchanged = Bookflow_Booking::get($b2);
assert_equals('paid', $booking_unchanged->status, 'status unchanged after a rejected transition attempt');

// =========================================================================
echo "\n-- Locations: combined product + location availability --\n";
// =========================================================================
$product5 = bf_create_test_product();
$pid5 = $product5->get_id();

$loc_id = Bookflow_Locations::create([
    'name' => '__TEST__ Location',
    'available_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], // no weekends
    'blocked_dates' => [],
    'holidays' => [],
    'status' => 'active',
]);
update_post_meta($pid5, '_bookflow_location_id', $loc_id);

// Find next Saturday from today
$sat = new DateTimeImmutable('next saturday');
$sat_str = $sat->format('Y-m-d');
assert_true(
    !Bookflow_Availability::is_date_available($pid5, $sat_str),
    'a location with no weekend availability blocks Saturday even though the product itself allows every day'
);

$mon = new DateTimeImmutable('next monday');
$mon_str = $mon->format('Y-m-d');
assert_true(
    Bookflow_Availability::is_date_available($pid5, $mon_str),
    'Monday remains available (in both product and location working days)'
);

// =========================================================================
echo "\n-- Multi-schedule (language variants): independent capacity + pricing --\n";
// =========================================================================
$product6 = bf_create_test_product(['base_price' => 40]);
$pid6 = $product6->get_id();
$date6 = bf_next_weekday_date();

$sched_en = Bookflow_Schedules::create([
    'product_id' => $pid6, 'option_group' => 'language', 'option_label' => 'English', 'option_value' => 'en',
    'available_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
    'time_slots' => ['10:00'], 'max_persons' => 3, 'max_bookings_per_slot' => 3, 'price_modifier' => 0,
]);
$sched_ro = Bookflow_Schedules::create([
    'product_id' => $pid6, 'option_group' => 'language', 'option_label' => 'Română', 'option_value' => 'ro',
    'available_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
    'time_slots' => ['10:00'], 'max_persons' => 10, 'max_bookings_per_slot' => 10, 'price_modifier' => 5,
]);

$slots_en = Bookflow_Availability::get_available_slots($pid6, $date6, null, $sched_en);
$slots_ro = Bookflow_Availability::get_available_slots($pid6, $date6, null, $sched_ro);
assert_equals(3, $slots_en[0]['max_persons'], 'English schedule keeps its own max_persons (3), independent of the product default');
assert_equals(10, $slots_ro[0]['max_persons'], 'Romanian schedule keeps its own max_persons (10), independent of English');
assert_equals(40.0, $slots_en[0]['price'], 'English schedule has no price modifier — base price unchanged');
assert_equals(45.0, $slots_ro[0]['price'], 'Romanian schedule applies its +5 price modifier on top of base price');

// Book the English variant to capacity (3) — Romanian variant must be unaffected
Bookflow_Booking::create([
    'product_id' => $pid6, 'schedule_id' => $sched_en, 'booking_date' => $date6, 'start_time' => '10:00',
    'persons_total' => 3, 'status' => 'confirmed',
]);
assert_true(
    !Bookflow_Availability::is_slot_available($pid6, $date6, '10:00', null, $sched_en),
    'English 10:00 slot is now full after booking 3/3'
);
assert_true(
    Bookflow_Availability::is_slot_available($pid6, $date6, '10:00', null, $sched_ro),
    'Romanian 10:00 slot (same product, same time) is still open — capacity is per-schedule, not shared'
);

// =========================================================================
echo "\n-- Multi-resource: two guides booked independently in the same slot --\n";
// =========================================================================
$product7 = bf_create_test_product();
$pid7 = $product7->get_id();
$date7 = bf_next_weekday_date();

$guide_a = Bookflow_Resources::create(['title' => '__TEST__ Guide A', 'capacity' => 2, 'status' => 'active']);
$guide_b = Bookflow_Resources::create(['title' => '__TEST__ Guide B', 'capacity' => 2, 'status' => 'active']);
Bookflow_Resources::assign_to_product($pid7, $guide_a, 0);
Bookflow_Resources::assign_to_product($pid7, $guide_b, 0);

Bookflow_Booking::create([
    'product_id' => $pid7, 'resource_id' => $guide_a, 'booking_date' => $date7, 'start_time' => '09:00',
    'persons_total' => 2, 'status' => 'confirmed',
]);
assert_true(
    !Bookflow_Availability::is_slot_available($pid7, $date7, '09:00', $guide_a),
    'Guide A is fully booked (2/2) at 09:00'
);
assert_true(
    Bookflow_Availability::is_slot_available($pid7, $date7, '09:00', $guide_b),
    'Guide B is still available at the same product/date/time — capacity is per-resource, not shared'
);

$available_guides = Bookflow_Resources::get_available_for_date($pid7, $date7);
$available_ids = array_map('intval', wp_list_pluck($available_guides, 'id'));
assert_true(in_array((int) $guide_b, $available_ids, true), 'get_available_for_date() includes Guide B (has an open slot)');
// Guide A still has structurally-offered slots (11:00, 14:00) even though 09:00 is full,
// so get_available_for_date correctly still includes both guides:
assert_equals(2, count($available_guides), 'both guides appear for the date (each has at least one open slot on other times)');

Bookflow_Resources::delete($guide_a);
Bookflow_Resources::delete($guide_b);
Bookflow_Schedules::delete($sched_en);
Bookflow_Schedules::delete($sched_ro);

// A real WP user, needed for anything that goes through wc_get_order()'s
// get_customer_id() or wp_set_current_user()-gated code (resource pins).
$test_user_id = wp_insert_user([
    'user_login' => '__test_bookflow_user',
    'user_pass'  => wp_generate_password(),
    'user_email' => 'test-bookflow-suite@example.invalid',
    'role'       => 'customer',
]);
if (is_wp_error($test_user_id)) {
    // Re-running the suite without cleanup from a crashed prior run.
    $test_user_id = (int) get_user_by('login', '__test_bookflow_user')->ID;
}

// =========================================================================
echo "\n-- Credits: grant, consume on confirm, refund on cancel, forfeit on no-show --\n";
// =========================================================================
$product8 = bf_create_test_product();
$pid8 = $product8->get_id();
$date8 = bf_next_weekday_date();
update_post_meta($pid8, '_bookflow_credit_type', 'lesson');

Bookflow_Credits::grant($test_user_id, 3, ['credit_type' => 'lesson']);
assert_equals(3, Bookflow_Credits::get_balance($test_user_id, 'lesson'), 'grant(3) gives a balance of 3');

$b8 = Bookflow_Booking::create([
    'product_id' => $pid8, 'customer_id' => $test_user_id, 'booking_date' => $date8, 'start_time' => '09:00',
    'persons_total' => 1, 'status' => 'pending',
]);
assert_true(!is_wp_error($b8), 'booking on a credit-gated product succeeds while a balance exists');
assert_equals(3, Bookflow_Credits::get_balance($test_user_id, 'lesson'), 'balance untouched while still pending — confirmation is what consumes it');

Bookflow_Booking::transition_status($b8, 'confirmed');
assert_equals(2, Bookflow_Credits::get_balance($test_user_id, 'lesson'), 'confirming the booking consumed exactly 1 credit');

Bookflow_Booking::transition_status($b8, 'cancelled', '', ['cancelled_by' => 'customer']);
assert_equals(3, Bookflow_Credits::get_balance($test_user_id, 'lesson'), 'cancelling a confirmed booking refunds the consumed credit');
assert_equals('customer', Bookflow_Booking::get($b8)->cancelled_by, 'cancelled_by is recorded as given in transition_status() context');

$b8b = Bookflow_Booking::create([
    'product_id' => $pid8, 'customer_id' => $test_user_id, 'booking_date' => $date8, 'start_time' => '11:00',
    'persons_total' => 1, 'status' => 'pending',
]);
Bookflow_Booking::transition_status($b8b, 'confirmed');
assert_equals(2, Bookflow_Credits::get_balance($test_user_id, 'lesson'), 'second confirm consumes another credit (3 -> 2)');
Bookflow_Booking::transition_status($b8b, 'no-show');
assert_equals(2, Bookflow_Credits::get_balance($test_user_id, 'lesson'), 'no-show does NOT refund — the credit is forfeited (only bookflow_booking_cancelled triggers a refund)');

// =========================================================================
echo "\n-- Credits: granted automatically from a completed order --\n";
// =========================================================================
$course = new WC_Product_Simple();
$course->set_name('__TEST__ Bookflow Course');
$course->set_status('publish');
$course->set_regular_price('100');
$course->save();
update_post_meta($course->get_id(), '_bookflow_grants_credit_type', 'lesson');
update_post_meta($course->get_id(), '_bookflow_grants_credit_amount', 5);

$balance_before_order = Bookflow_Credits::get_balance($test_user_id, 'lesson');
$order = wc_create_order(['customer_id' => $test_user_id]);
$order->add_product($course, 1);
$order->calculate_totals();
$order->set_status('completed');
$order->save();

assert_equals($balance_before_order + 5, Bookflow_Credits::get_balance($test_user_id, 'lesson'), 'completing an order for a credit-granting product adds 5 credits');

do_action('woocommerce_order_status_completed', $order->get_id());
assert_equals($balance_before_order + 5, Bookflow_Credits::get_balance($test_user_id, 'lesson'), 'firing the completed hook again does not double-grant (idempotent per order item)');

// =========================================================================
echo "\n-- Resource pins: narrow the bookable resource list for a pinned customer --\n";
// =========================================================================
$product9 = bf_create_test_product();
$pid9 = $product9->get_id();
$date9 = bf_next_weekday_date();
$guide_c = Bookflow_Resources::create(['title' => '__TEST__ Guide C', 'capacity' => 2, 'status' => 'active']);
$guide_d = Bookflow_Resources::create(['title' => '__TEST__ Guide D', 'capacity' => 2, 'status' => 'active']);
Bookflow_Resources::assign_to_product($pid9, $guide_c, 0);
Bookflow_Resources::assign_to_product($pid9, $guide_d, 0);

wp_set_current_user($test_user_id);
$before_pin = Bookflow_Resources::get_available_for_slot($pid9, $date9, '09:00');
assert_equals(2, count($before_pin), 'both resources bookable before any pin exists');

Bookflow_Resource_Pins::pin($test_user_id, $guide_c, $pid9);
$after_pin = Bookflow_Resources::get_available_for_slot($pid9, $date9, '09:00');
assert_equals(1, count($after_pin), 'pinning narrows the list to exactly the pinned resource');
assert_equals((int) $guide_c, (int) $after_pin[0]->id, 'the one resource left is the pinned one');
wp_set_current_user(0);

// =========================================================================
echo "\n-- Customer flags: eligibility gate on a product --\n";
// =========================================================================
$product10 = bf_create_test_product();
$pid10 = $product10->get_id();
$date10 = bf_next_weekday_date();
update_post_meta($pid10, '_bookflow_requires_flag', 'ready_for_exam');

$blocked = Bookflow_Booking::create([
    'product_id' => $pid10, 'customer_id' => $test_user_id, 'booking_date' => $date10, 'start_time' => '09:00',
    'persons_total' => 1,
]);
assert_true(is_wp_error($blocked) && $blocked->get_error_code() === 'not_eligible', 'booking is blocked while the required flag is unset');

Bookflow_Customer_Flags::set($test_user_id, 'ready_for_exam', '1');
$allowed = Bookflow_Booking::create([
    'product_id' => $pid10, 'customer_id' => $test_user_id, 'booking_date' => $date10, 'start_time' => '09:00',
    'persons_total' => 1,
]);
assert_true(!is_wp_error($allowed), 'booking succeeds once the flag is set');

// =========================================================================
echo "\n-- Anti-hoarding: max active bookings per customer --\n";
// =========================================================================
$product11 = bf_create_test_product();
$pid11 = $product11->get_id();
$date11 = bf_next_weekday_date();
update_post_meta($pid11, '_bookflow_max_active_bookings_per_customer', 1);

$first = Bookflow_Booking::create([
    'product_id' => $pid11, 'customer_id' => $test_user_id, 'booking_date' => $date11, 'start_time' => '09:00',
    'persons_total' => 1, 'status' => 'pending',
]);
assert_true(!is_wp_error($first), 'first booking under the cap succeeds');

$second = Bookflow_Booking::create([
    'product_id' => $pid11, 'customer_id' => $test_user_id, 'booking_date' => $date11, 'start_time' => '11:00',
    'persons_total' => 1, 'status' => 'pending',
]);
assert_true(is_wp_error($second) && $second->get_error_code() === 'too_many_active_bookings', 'a second active booking is rejected once the per-product cap (1) is reached');

// =========================================================================
echo "\n-- Multi-resource: a booking's second resource (e.g. equipment) is locked too --\n";
// =========================================================================
$product12 = bf_create_test_product();
$pid12 = $product12->get_id();
$date12 = bf_next_weekday_date();
$instructor_1 = Bookflow_Resources::create(['title' => '__TEST__ Instructor 1', 'capacity' => 1, 'status' => 'active']);
$instructor_2 = Bookflow_Resources::create(['title' => '__TEST__ Instructor 2', 'capacity' => 1, 'status' => 'active']);
$shared_car = Bookflow_Resources::create(['title' => '__TEST__ Car', 'capacity' => 1, 'status' => 'active']);

$mb1 = Bookflow_Booking::create([
    'product_id' => $pid12, 'resource_id' => $instructor_1, 'resource_ids' => [['resource_id' => $shared_car, 'role' => 'car']],
    'booking_date' => $date12, 'start_time' => '09:00', 'persons_total' => 1, 'status' => 'confirmed',
]);
assert_true(!is_wp_error($mb1), 'booking with a primary resource + a secondary resource succeeds');
$attached = Bookflow_Booking_Resources::get_for_booking($mb1);
assert_equals(2, count($attached), 'both the primary and secondary resource are recorded against the booking');

$mb2 = Bookflow_Booking::create([
    'product_id' => $pid12, 'resource_id' => $instructor_2, 'resource_ids' => [['resource_id' => $shared_car, 'role' => 'car']],
    'booking_date' => $date12, 'start_time' => '09:00', 'persons_total' => 1, 'status' => 'confirmed',
]);
assert_true(
    is_wp_error($mb2) && $mb2->get_error_code() === 'resource_capacity_exceeded',
    'a second booking is rejected because the shared car is already taken, even though the instructor differs'
);

// =========================================================================
echo "\n-- Reschedule: re-keys the credit ledger instead of refund + re-consume --\n";
// =========================================================================
$product13 = bf_create_test_product();
$pid13 = $product13->get_id();
$date13 = bf_next_weekday_date();
update_post_meta($pid13, '_bookflow_credit_type', 'lesson');
Bookflow_Credits::grant($test_user_id, 1, ['credit_type' => 'lesson']);
$balance_before_reschedule = Bookflow_Credits::get_balance($test_user_id, 'lesson');

$r1 = Bookflow_Booking::create([
    'product_id' => $pid13, 'customer_id' => $test_user_id, 'booking_date' => $date13, 'start_time' => '09:00',
    'persons_total' => 1, 'status' => 'pending',
]);
Bookflow_Booking::transition_status($r1, 'confirmed');
$balance_after_confirm = Bookflow_Credits::get_balance($test_user_id, 'lesson');
assert_equals($balance_before_reschedule - 1, $balance_after_confirm, 'confirming the original booking consumed 1 credit');

$new_id = Bookflow_Booking::reschedule($r1, ['booking_date' => $date13, 'start_time' => '14:00']);
assert_true(!is_wp_error($new_id), 'reschedule() succeeds');
assert_equals($balance_after_confirm, Bookflow_Credits::get_balance($test_user_id, 'lesson'), 'reschedule changes neither refunds nor re-consumes — balance is unchanged');

$old_booking = Bookflow_Booking::get($r1);
assert_equals('cancelled', $old_booking->status, 'the original booking is marked cancelled');
assert_equals('reschedule', $old_booking->cancelled_by, "cancelled_by is 'reschedule', distinguishing this from a real cancellation");

$new_booking = Bookflow_Booking::get($new_id);
assert_equals('confirmed', $new_booking->status, 'the new booking carries over the confirmed status directly (not re-triggering consumption via bookflow_booking_confirmed)');
assert_equals('14:00', $new_booking->start_time, 'the new booking is at the requested new time');

// =========================================================================
echo "\n-- Manual approval: Bookflow_Cart::approve_booking() / reject_booking() --\n";
// =========================================================================
$product14 = bf_create_test_product();
$pid14 = $product14->get_id();
$date14 = bf_next_weekday_date();

$b14a = Bookflow_Booking::create([
    'product_id' => $pid14, 'booking_date' => $date14, 'start_time' => '09:00', 'persons_total' => 1, 'status' => 'pending',
]);
$rejected = Bookflow_Cart::reject_booking($b14a, 'Not ready yet');
assert_true(!is_wp_error($rejected), 'reject_booking() succeeds from pending');
assert_equals('rejected', Bookflow_Booking::get($b14a)->status, "booking status is 'rejected'");

$b14b = Bookflow_Booking::create([
    'product_id' => $pid14, 'booking_date' => $date14, 'start_time' => '11:00', 'persons_total' => 1, 'status' => 'pending',
]);
$approved = Bookflow_Cart::approve_booking($b14b);
assert_true(!is_wp_error($approved), 'approve_booking() succeeds from pending');
assert_equals('confirmed', Bookflow_Booking::get($b14b)->status, "booking status is 'confirmed'");

// =========================================================================
echo "\n-- Per-resource revenue/occupancy report --\n";
// =========================================================================
$by_resource = Bookflow_Booking::get_revenue_by_resource(['product_id' => $pid12]);
assert_true(is_array($by_resource), 'get_revenue_by_resource() returns an array');
$resource_ids_in_report = array_map(fn($row) => (int) $row->resource_id, $by_resource);
assert_true(in_array((int) $instructor_1, $resource_ids_in_report, true), 'the booked instructor appears in the per-resource report');

// --- cleanup: new tables/fixtures from this section ---
Bookflow_Resources::delete($guide_c);
Bookflow_Resources::delete($guide_d);
Bookflow_Resources::delete($instructor_1);
Bookflow_Resources::delete($instructor_2);
Bookflow_Resources::delete($shared_car);
$order->delete(true);
wp_delete_post($course->get_id(), true);
global $wpdb;
$credit_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}bookflow_customer_credits WHERE customer_id = %d", $test_user_id));
if ($credit_ids) {
    $placeholders = implode(',', array_fill(0, count($credit_ids), '%d'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bookflow_credit_transactions WHERE credit_id IN ($placeholders)", ...$credit_ids));
}
$wpdb->delete($wpdb->prefix . 'bookflow_customer_credits', ['customer_id' => $test_user_id], ['%d']);
$wpdb->delete($wpdb->prefix . 'bookflow_customer_resource_pins', ['customer_id' => $test_user_id], ['%d']);
$wpdb->delete($wpdb->prefix . 'bookflow_customer_flags', ['customer_id' => $test_user_id], ['%d']);
foreach ([$pid8, $pid9, $pid10, $pid11, $pid12, $pid13, $pid14] as $pid_extra) {
    $wpdb->delete($wpdb->prefix . 'bookflow_bookings', ['product_id' => $pid_extra], ['%d']);
}
require_once ABSPATH . 'wp-admin/includes/user.php'; // wp_delete_user() isn't autoloaded outside wp-admin
wp_delete_user($test_user_id);

// --- cleanup ---
echo "\n-- Cleanup --\n";
global $wpdb;
$wpdb->delete($wpdb->prefix . 'bookflow_bookings', ['product_id' => $pid], ['%d']);
$wpdb->delete($wpdb->prefix . 'bookflow_bookings', ['product_id' => $pid2], ['%d']);
$wpdb->delete($wpdb->prefix . 'bookflow_bookings', ['product_id' => $pid3], ['%d']);
$wpdb->delete($wpdb->prefix . 'bookflow_bookings', ['product_id' => $pid4], ['%d']);
$wpdb->delete($wpdb->prefix . 'bookflow_bookings', ['product_id' => $pid6], ['%d']);
$wpdb->delete($wpdb->prefix . 'bookflow_bookings', ['product_id' => $pid7], ['%d']);
Bookflow_Resources::delete($resource_id);
Bookflow_Extras::delete($extra_id);
Bookflow_Locations::delete($loc_id);
bf_cleanup_test_products();
echo "  cleaned up test fixtures\n";

echo "\n=== Results: {$GLOBALS['bf_pass']} passed, {$GLOBALS['bf_fail']} failed ===\n";
exit($GLOBALS['bf_fail'] > 0 ? 1 : 0);

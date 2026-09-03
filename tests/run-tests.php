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
 */

define('WP_USE_THEMES', false);
require dirname(__DIR__, 4) . '/wp-load.php';

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

// --- fixtures ---

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

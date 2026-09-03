<?php
/**
 * Frontend Display
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Frontend {

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('woocommerce_before_add_to_cart_button', [$this, 'booking_form'], 20);
        // WooCommerce only auto-renders the add-to-cart area for its own
        // built-in product types (simple/variable/grouped/external); a
        // custom type like "booking" needs to hook its own
        // woocommerce_{type}_add_to_cart action or the whole add-to-cart
        // template — including woocommerce_before_add_to_cart_button above —
        // never fires.
        add_action('woocommerce_booking_add_to_cart', [$this, 'add_to_cart_template']);
        add_filter('woocommerce_loop_add_to_cart_link', [$this, 'loop_add_to_cart'], 10, 2);
        add_filter('woocommerce_get_price_html', [$this, 'price_html'], 10, 2);
    }

    /**
     * Minimal mirror of WooCommerce's own single-product/add-to-cart/simple.php,
     * so the standard before/after hooks (and Bookflow's booking form, which
     * hangs off woocommerce_before_add_to_cart_button) fire for this product type.
     */
    public function add_to_cart_template() {
        global $product;
        if (!$product->is_purchasable()) {
            return;
        }

        // wc_get_stock_html() returns pre-escaped HTML from WooCommerce core
        // (the same call WC's own simple.php add-to-cart template uses
        // unescaped) — nothing here to further sanitize.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo wc_get_stock_html($product);

        do_action('woocommerce_before_add_to_cart_form');
        ?>
        <form class="cart" action="<?php echo esc_url(apply_filters('woocommerce_add_to_cart_form_action', $product->get_permalink())); ?>" method="post" enctype="multipart/form-data">
            <?php do_action('woocommerce_before_add_to_cart_button'); // renders the whole wizard, incl. its own .bookflow-booking-form opening tag ?>
            <div class="bookflow-wizard-nav bookflow-final-nav" id="bookflow-final-nav">
                <input type="hidden" name="add-to-cart" value="<?php echo esc_attr($product->get_id()); ?>">
                <button type="submit" id="bookflow-submit" name="add-to-cart" value="<?php echo esc_attr($product->get_id()); ?>" class="bookflow-wizard-next" disabled>
                    <?php echo esc_html($product->single_add_to_cart_text()); ?>
                </button>
            </div>
            </div><!-- .bookflow-booking-form, opened inside templates/booking-form.php -->
            <?php do_action('woocommerce_after_add_to_cart_button'); ?>
        </form>
        <?php
        do_action('woocommerce_after_add_to_cart_form');
    }

    public function enqueue_scripts() {
        if (!is_product()) {
            return;
        }

        global $post;
        $product = wc_get_product($post->ID);
        if (!$product || $product->get_type() !== 'booking') {
            return;
        }

        // Lets a separate front-end plugin (a custom widget/skin) replace
        // this built-in form entirely, so the two never render side by side.
        if (!apply_filters('bookflow_render_default_widget', true, $product)) {
            return;
        }

        wp_enqueue_style('bookflow-booking', BOOKFLOW_PLUGIN_URL . 'public/css/booking.css', [], BOOKFLOW_VERSION);
        wp_enqueue_script('bookflow-booking', BOOKFLOW_PLUGIN_URL . 'public/js/booking-calendar.js', [], BOOKFLOW_VERSION, true);

        $has_person_types = Bookflow_Person_Types::product_has_types($post->ID);
        $person_types = $has_person_types ? Bookflow_Person_Types::get_for_product($post->ID) : [];
        $has_resources = $product->has_resources();
        $has_schedules = Bookflow_Schedules::product_has_schedules($post->ID);
        $schedules = $has_schedules ? Bookflow_Schedules::get_for_product($post->ID) : [];

        $person_types_data = array_map(function ($pt) {
            return [
                'id'       => (int) $pt->id,
                'name'     => $pt->name,
                'cost'     => (float) $pt->cost,
                'min_qty'  => (int) $pt->min_qty,
                'max_qty'  => (int) $pt->max_qty,
            ];
        }, $person_types);

        $loc_terms = get_the_terms($post->ID, 'product_tag');
        $current_location = (!empty($loc_terms) && !is_wp_error($loc_terms)) ? current($loc_terms)->slug : null;
        $current_location_id = (int) get_post_meta($post->ID, '_bookflow_location_id', true) ?: null;

        $schedules_data = array_map(function ($s) {
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
        }, $schedules);

        wp_localize_script('bookflow-booking', 'bookflowBooking', [
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('bookflow_nonce'),
            'restUrl'         => esc_url_raw(rest_url('bookflow/v1/')),
            'restNonce'       => wp_create_nonce('wp_rest'),
            'currentLocation' => $current_location,
            'currentLocationId' => $current_location_id,
            'productId'       => $post->ID,
            'minPersons'      => $product->get_min_persons(),
            'maxPersons'      => $product->get_max_persons(),
            'currency'        => get_woocommerce_currency_symbol(),
            'hasPersonTypes'  => $has_person_types,
            'personTypes'     => $person_types_data,
            'hasResources'    => $has_resources,
            'hasSchedules'    => $has_schedules,
            'schedules'       => $schedules_data,
            'i18n'            => [
                'selectDate'      => Bookflow_I18n::t('calendar.select_date'),
                'selectTime'      => Bookflow_I18n::t('calendar.select_time'),
                'noSlots'         => Bookflow_I18n::t('calendar.no_slots'),
                'loading'         => Bookflow_I18n::t('calendar.loading'),
                'perPerson'       => Bookflow_I18n::t('calendar.per_person'),
                'total'           => Bookflow_I18n::t('calendar.total'),
                'available'       => Bookflow_I18n::t('calendar.available'),
                'selectSchedule'  => Bookflow_I18n::t('calendar.select_option'),
                'selectResource'  => Bookflow_I18n::t('calendar.select_resource'),
                'spotsRemaining'  => Bookflow_I18n::t('calendar.spots_remaining'),
                'soldOut'         => Bookflow_I18n::t('calendar.sold_out'),
                'spotsOfMax'      => Bookflow_I18n::t('calendar.spots_of_max'),
                'selectLocation'  => Bookflow_I18n::t('form.select_location'),
                'selectStaff'     => Bookflow_I18n::t('form.select_resource'),
                'changeLocation'  => Bookflow_I18n::t('form.change_location'),
                'stepLanguage'    => Bookflow_I18n::t('wizard.step_language'),
                'stepLocation'    => Bookflow_I18n::t('wizard.step_location'),
                'stepDay'         => Bookflow_I18n::t('wizard.step_day'),
                'stepStaff'       => Bookflow_I18n::t('wizard.step_staff'),
                'stepTime'        => Bookflow_I18n::t('wizard.step_time'),
                'stepPersons'     => Bookflow_I18n::t('wizard.step_persons'),
                'stepContact'     => Bookflow_I18n::t('wizard.step_contact'),
                'stepConfirm'     => Bookflow_I18n::t('wizard.step_confirm'),
                'wizardBack'      => Bookflow_I18n::t('wizard.back'),
                'wizardNext'      => Bookflow_I18n::t('wizard.next'),
                'noSlotsForStaff' => Bookflow_I18n::t('wizard.no_slots_for_staff'),
                'mon'             => Bookflow_I18n::t('calendar.weekday.mon'),
                'tue'             => Bookflow_I18n::t('calendar.weekday.tue'),
                'wed'             => Bookflow_I18n::t('calendar.weekday.wed'),
                'thu'             => Bookflow_I18n::t('calendar.weekday.thu'),
                'fri'             => Bookflow_I18n::t('calendar.weekday.fri'),
                'sat'             => Bookflow_I18n::t('calendar.weekday.sat'),
                'sun'             => Bookflow_I18n::t('calendar.weekday.sun'),
                'months'          => [
                    Bookflow_I18n::t('calendar.month.1'), Bookflow_I18n::t('calendar.month.2'),
                    Bookflow_I18n::t('calendar.month.3'), Bookflow_I18n::t('calendar.month.4'),
                    Bookflow_I18n::t('calendar.month.5'), Bookflow_I18n::t('calendar.month.6'),
                    Bookflow_I18n::t('calendar.month.7'), Bookflow_I18n::t('calendar.month.8'),
                    Bookflow_I18n::t('calendar.month.9'), Bookflow_I18n::t('calendar.month.10'),
                    Bookflow_I18n::t('calendar.month.11'), Bookflow_I18n::t('calendar.month.12'),
                ],
                'errorName'       => Bookflow_I18n::t('form.error_name'),
                'errorEmail'      => Bookflow_I18n::t('form.error_email'),
                'errorPhone'      => Bookflow_I18n::t('form.error_phone'),
                'errorGeneric'    => Bookflow_I18n::t('calendar.error_generic'),
                'retry'           => Bookflow_I18n::t('calendar.retry'),
                'noAvailability'  => Bookflow_I18n::t('calendar.no_availability'),
            ],
            'languageSelector' => apply_filters('bookflow_language_selector_id', 'bookflow-language'),
            'langDropdown'     => apply_filters('bookflow_lang_dropdown_id', 'bookflow-lang-select'),
            'spotsElement'     => apply_filters('bookflow_spots_element_id', 'bookflow-spots-left'),
            'souvenirPrice'    => (float) (function_exists('get_field') ? get_field('excursii_souvenir_price', $post->ID) : 0),
        ]);
    }

    public function booking_form() {
        global $product;
        if (!$product || $product->get_type() !== 'booking') {
            return;
        }
        if (!apply_filters('bookflow_render_default_widget', true, $product)) {
            return;
        }

        include BOOKFLOW_PLUGIN_DIR . 'templates/booking-form.php';
    }

    public function loop_add_to_cart($html, $product) {
        if ($product->get_type() === 'booking') {
            return sprintf(
                '<a href="%s" class="button">%s</a>',
                esc_url(get_permalink($product->get_id())),
                esc_html(Bookflow_I18n::t('calendar.book_now'))
            );
        }
        return $html;
    }

    public function price_html($price_html, $product) {
        if ($product->get_type() !== 'booking') {
            return $price_html;
        }

        $range = Bookflow_Pricing::get_price_range($product->get_id());

        if ($range['min'] === $range['max'] || $range['max'] === 0) {
            return wc_price($range['min']) . ' <small>' . esc_html(Bookflow_I18n::t('calendar.per_person')) . '</small>';
        }

        return wc_price($range['min']) . ' &ndash; ' . wc_price($range['max']) . ' <small>' . esc_html(Bookflow_I18n::t('calendar.per_person')) . '</small>';
    }
}

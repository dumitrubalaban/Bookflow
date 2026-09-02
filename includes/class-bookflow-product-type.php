<?php
/**
 * Bookable Product Type for WooCommerce
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Product_Type {

    public function __construct() {
        add_filter('product_type_selector', [$this, 'add_product_type']);
        add_action('init', [$this, 'register_product_type']);
        add_filter('woocommerce_product_data_tabs', [$this, 'add_product_tab']);
        add_action('woocommerce_product_data_panels', [$this, 'product_tab_content']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_meta']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
    }

    public function register_product_type() {
        if (!class_exists('WC_Product_Booking')) {
            require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-wc-product-booking.php';
        }
    }

    public function add_product_type($types) {
        $types['booking'] = Bookflow_I18n::t('product.bookable_product');
        return $types;
    }

    public function add_product_tab($tabs) {
        $tabs['bookflow_booking'] = [
            'label'    => Bookflow_I18n::t('product.booking_tab'),
            'target'   => 'bookflow_booking_data',
            'class'    => ['show_if_booking'],
            'priority' => 21,
        ];

        $tabs['bookflow_pricing_rules'] = [
            'label'    => Bookflow_I18n::t('product.pricing_tab'),
            'target'   => 'bookflow_pricing_rules_data',
            'class'    => ['show_if_booking'],
            'priority' => 22,
        ];

        $tabs['bookflow_availability'] = [
            'label'    => Bookflow_I18n::t('product.availability_tab'),
            'target'   => 'bookflow_availability_data',
            'class'    => ['show_if_booking'],
            'priority' => 23,
        ];

        $tabs['bookflow_resources'] = [
            'label'    => Bookflow_I18n::t('product.resources_tab'),
            'target'   => 'bookflow_resources_data',
            'class'    => ['show_if_booking'],
            'priority' => 24,
        ];

        return $tabs;
    }

    public function product_tab_content() {
        global $post;
        $product_id = $post->ID;

        // ---- Booking Data Panel ----
        echo '<div id="bookflow_booking_data" class="panel woocommerce_options_panel">';

        woocommerce_wp_text_input([
            'id'          => '_bookflow_duration',
            'label'       => Bookflow_I18n::t('product.duration'),
            'desc_tip'    => true,
            'description' => Bookflow_I18n::t('product.duration_desc'),
            'type'        => 'number',
            'value'       => get_post_meta($product_id, '_bookflow_duration', true) ?: '60',
        ]);

        woocommerce_wp_text_input([
            'id'          => '_bookflow_min_persons',
            'label'       => Bookflow_I18n::t('product.min_persons'),
            'type'        => 'number',
            'value'       => get_post_meta($product_id, '_bookflow_min_persons', true) ?: '1',
        ]);

        woocommerce_wp_text_input([
            'id'          => '_bookflow_max_persons',
            'label'       => Bookflow_I18n::t('product.max_persons'),
            'type'        => 'number',
            'value'       => get_post_meta($product_id, '_bookflow_max_persons', true) ?: '20',
        ]);

        woocommerce_wp_text_input([
            'id'          => '_bookflow_buffer_time',
            'label'       => Bookflow_I18n::t('product.buffer_time'),
            'desc_tip'    => true,
            'description' => Bookflow_I18n::t('product.buffer_time_desc'),
            'type'        => 'number',
            'value'       => get_post_meta($product_id, '_bookflow_buffer_time', true) ?: '0',
        ]);

        woocommerce_wp_text_input([
            'id'          => '_bookflow_max_bookings_per_slot',
            'label'       => Bookflow_I18n::t('product.max_bookings_per_slot'),
            'type'        => 'number',
            'value'       => get_post_meta($product_id, '_bookflow_max_bookings_per_slot', true) ?: '1',
        ]);

        woocommerce_wp_text_input([
            'id'          => '_bookflow_min_advance',
            'label'       => Bookflow_I18n::t('product.min_advance'),
            'desc_tip'    => true,
            'description' => Bookflow_I18n::t('product.min_advance_desc'),
            'type'        => 'number',
            'value'       => get_post_meta($product_id, '_bookflow_min_advance', true) ?: '0',
        ]);

        woocommerce_wp_text_input([
            'id'          => '_bookflow_max_advance',
            'label'       => Bookflow_I18n::t('product.max_advance'),
            'desc_tip'    => true,
            'description' => Bookflow_I18n::t('product.max_advance_desc'),
            'type'        => 'number',
            'value'       => get_post_meta($product_id, '_bookflow_max_advance', true) ?: '365',
        ]);

        woocommerce_wp_text_input([
            'id'          => '_bookflow_cancel_before_hours',
            'label'       => Bookflow_I18n::t('product.cancellation_window'),
            'desc_tip'    => true,
            'description' => Bookflow_I18n::t('product.cancellation_window_desc'),
            'type'        => 'number',
            'value'       => get_post_meta($product_id, '_bookflow_cancel_before_hours', true) ?: '24',
        ]);

        // Time Slots
        echo '<div class="options_group">';
        echo '<h4 style="padding-left:12px;">' . esc_html(Bookflow_I18n::t('product.time_slots_title')) . '</h4>';
        echo '<p style="padding-left:12px;" class="description">' . esc_html(Bookflow_I18n::t('product.time_slots_desc')) . '</p>';

        $time_slots = get_post_meta($product_id, '_bookflow_time_slots', true);
        if (empty($time_slots)) {
            $time_slots = "09:00\n10:00\n11:00\n12:00\n13:00\n14:00\n15:00\n16:00\n17:00\n18:00";
        }

        woocommerce_wp_textarea_input([
            'id'    => '_bookflow_time_slots',
            'label' => Bookflow_I18n::t('product.time_slots_label'),
            'value' => $time_slots,
            'style' => 'height: 150px;',
        ]);

        echo '</div>';

        // Available Days
        echo '<div class="options_group">';
        echo '<h4 style="padding-left:12px;">' . esc_html(Bookflow_I18n::t('product.available_days_title')) . '</h4>';

        echo '<input type="hidden" name="_bookflow_booking_tab_present" value="1">';

        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $available_days = get_post_meta($product_id, '_bookflow_available_days', true);
        if (empty($available_days)) {
            $available_days = $days;
        }

        foreach ($days as $day) {
            woocommerce_wp_checkbox([
                'id'      => '_bookflow_day_' . $day,
                'label'   => ucfirst($day),
                'value'   => in_array($day, (array) $available_days) ? 'yes' : 'no',
                'cbvalue' => 'yes',
            ]);
        }

        echo '</div>';

        // Person Types info
        echo '<div class="options_group">';
        echo '<h4 style="padding-left:12px;">' . esc_html(Bookflow_I18n::t('product.person_types_title')) . '</h4>';
        echo '<p style="padding-left:12px;" class="description">' . esc_html(Bookflow_I18n::t('product.person_types_desc')) . '</p>';

        $has_types = Bookflow_Person_Types::product_has_types($product_id);
        if ($has_types) {
            $types = Bookflow_Person_Types::get_for_product($product_id);
            echo '<div style="padding-left:12px; margin-bottom:10px;">';
            echo '<strong>' . esc_html(Bookflow_I18n::t('product.active_types')) . '</strong><br>';
            foreach ($types as $t) {
                echo esc_html($t->name) . ' (' . wp_kses_post(wc_price($t->cost)) . ')<br>';
            }
            echo '</div>';
        } else {
            echo '<p style="padding-left:12px;"><em>' . esc_html(Bookflow_I18n::t('product.no_person_types')) . '</em></p>';
        }

        echo '</div>';
        echo '</div>';

        // ---- Pricing Rules Panel ----
        echo '<div id="bookflow_pricing_rules_data" class="panel woocommerce_options_panel">';

        woocommerce_wp_text_input([
            'id'        => '_bookflow_base_price',
            'label'     => Bookflow_I18n::t('product.base_price'),
            'type'      => 'text',
            'data_type' => 'price',
            'value'     => get_post_meta($product_id, '_bookflow_base_price', true) ?: '',
        ]);

        woocommerce_wp_text_input([
            'id'          => '_bookflow_deposit_percent',
            'label'       => Bookflow_I18n::t('product.deposit_percent'),
            'type'        => 'number',
            'custom_attributes' => ['min' => '0', 'max' => '100', 'step' => '1'],
            'desc_tip'    => true,
            'description' => Bookflow_I18n::t('product.deposit_percent_desc'),
            'value'       => get_post_meta($product_id, '_bookflow_deposit_percent', true) ?: '0',
        ]);

        echo '<div class="options_group">';
        echo '<h4 style="padding-left:12px;">' . esc_html(Bookflow_I18n::t('product.time_pricing_title')) . '</h4>';
        echo '<p style="padding-left:12px;" class="description">' . esc_html(Bookflow_I18n::t('product.time_pricing_desc')) . '</p>';

        $pricing_rules = get_post_meta($product_id, '_bookflow_pricing_rules', true);
        if (!is_array($pricing_rules)) {
            $pricing_rules = [];
        }

        echo '<div id="bookflow-pricing-rules-container">';
        for ($i = 0; $i < 5; $i++) {
            $rule = isset($pricing_rules[$i]) ? $pricing_rules[$i] : ['from' => '', 'to' => '', 'price' => '', 'label' => ''];
            echo '<div class="bookflow-pricing-rule options_group" style="border-top: 1px solid #eee; padding-top: 10px; margin-top: 10px;">';
            echo '<h4 style="padding-left:12px;">' . esc_html(sprintf(Bookflow_I18n::t('product.rule_number'), $i + 1)) . '</h4>';

            woocommerce_wp_text_input([
                'id'          => "_bookflow_pricing_rules_{$i}_label",
                'label'       => Bookflow_I18n::t('product.rule_label'),
                'value'       => $rule['label'],
                'placeholder' => Bookflow_I18n::t('product.rule_label_placeholder'),
            ]);

            woocommerce_wp_text_input([
                'id'          => "_bookflow_pricing_rules_{$i}_from",
                'label'       => Bookflow_I18n::t('product.from_time'),
                'value'       => $rule['from'],
                'placeholder' => '16:00',
            ]);

            woocommerce_wp_text_input([
                'id'          => "_bookflow_pricing_rules_{$i}_to",
                'label'       => Bookflow_I18n::t('product.to_time'),
                'value'       => $rule['to'],
                'placeholder' => '20:00',
            ]);

            woocommerce_wp_text_input([
                'id'        => "_bookflow_pricing_rules_{$i}_price",
                'label'     => Bookflow_I18n::t('product.price_per_person'),
                'value'     => $rule['price'],
                'data_type' => 'price',
            ]);

            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // ---- Availability Panel ----
        echo '<div id="bookflow_availability_data" class="panel woocommerce_options_panel">';

        echo '<div class="options_group">';
        echo '<h4 style="padding-left:12px;">' . esc_html(Bookflow_I18n::t('product.blocked_dates_title')) . '</h4>';
        echo '<p style="padding-left:12px;" class="description">' . esc_html(Bookflow_I18n::t('product.blocked_dates_desc')) . '</p>';

        woocommerce_wp_textarea_input([
            'id'    => '_bookflow_blocked_dates',
            'label' => Bookflow_I18n::t('product.blocked_dates_label'),
            'value' => get_post_meta($product_id, '_bookflow_blocked_dates', true),
            'style' => 'height: 120px;',
        ]);
        echo '</div>';

        echo '<div class="options_group">';
        echo '<h4 style="padding-left:12px;">' . esc_html(Bookflow_I18n::t('product.date_ranges_title')) . '</h4>';
        echo '<p style="padding-left:12px;" class="description">' . esc_html(Bookflow_I18n::t('product.date_ranges_desc')) . '</p>';

        woocommerce_wp_text_input([
            'id'    => '_bookflow_date_range_from',
            'label' => Bookflow_I18n::t('product.available_from'),
            'type'  => 'date',
            'value' => get_post_meta($product_id, '_bookflow_date_range_from', true),
        ]);

        woocommerce_wp_text_input([
            'id'    => '_bookflow_date_range_to',
            'label' => Bookflow_I18n::t('product.available_to'),
            'type'  => 'date',
            'value' => get_post_meta($product_id, '_bookflow_date_range_to', true),
        ]);

        echo '</div>';

        echo '<div class="options_group">';
        echo '<h4 style="padding-left:12px;">' . esc_html(Bookflow_I18n::t('product.terms_title')) . '</h4>';
        echo '<p style="padding-left:12px;" class="description">' . esc_html(Bookflow_I18n::t('product.terms_desc')) . '</p>';

        woocommerce_wp_textarea_input([
            'id'          => '_bookflow_terms_text',
            'label'       => Bookflow_I18n::t('product.terms_label'),
            'value'       => get_post_meta($product_id, '_bookflow_terms_text', true),
            'style'       => 'height: 100px;',
            'desc_tip'    => true,
            'description' => Bookflow_I18n::t('product.terms_field_desc'),
        ]);
        echo '</div>';

        echo '</div>';

        // ---- Resources Panel ----
        echo '<div id="bookflow_resources_data" class="panel woocommerce_options_panel">';
        echo '<div class="options_group">';
        echo '<h4 style="padding-left:12px;">' . esc_html(Bookflow_I18n::t('product.assigned_resources')) . '</h4>';
        echo '<p style="padding-left:12px;" class="description">' . esc_html(Bookflow_I18n::t('product.assigned_resources_desc')) . '</p>';

        $resources = Bookflow_Resources::get_for_product($product_id);
        $all_resources = Bookflow_Resources::get_all('active');

        if (!empty($resources)) {
            echo '<table style="width:95%; margin:10px 12px;" class="widefat">';
            echo '<thead><tr><th>' . esc_html(Bookflow_I18n::t('product.resource')) . '</th><th>' . esc_html(Bookflow_I18n::t('product.capacity')) . '</th><th>' . esc_html(Bookflow_I18n::t('product.cost')) . '</th><th></th></tr></thead><tbody>';
            foreach ($resources as $r) {
                echo '<tr>';
                echo '<td>' . esc_html($r->title) . '</td>';
                echo '<td>' . esc_html($r->capacity) . '</td>';
                echo '<td>' . wp_kses_post(wc_price($r->base_cost)) . '</td>';
                echo '<td><label><input type="checkbox" name="bookflow_unassign_resources[]" value="' . esc_attr($r->id) . '"> ' . esc_html(Bookflow_I18n::t('product.remove')) . '</label></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p style="padding-left:12px;"><em>' . esc_html(Bookflow_I18n::t('product.no_resources_assigned')) . '</em></p>';
        }

        // Add resource selector
        $assigned_ids = array_map(function ($r) { return (int) $r->id; }, $resources);
        $unassigned = array_filter($all_resources, function ($r) use ($assigned_ids) {
            return !in_array((int) $r->id, $assigned_ids);
        });

        if (!empty($unassigned)) {
            echo '<div style="padding:10px 12px;">';
            echo '<label><strong>' . esc_html(Bookflow_I18n::t('product.add_resource_label')) . '</strong></label><br>';
            echo '<select name="bookflow_assign_resource" style="margin-top:5px;">';
            echo '<option value="">' . esc_html(Bookflow_I18n::t('product.select_default')) . '</option>';
            foreach ($unassigned as $r) {
                echo '<option value="' . esc_attr($r->id) . '">' . esc_html($r->title) . ' (cap: ' . esc_html($r->capacity) . ')</option>';
            }
            echo '</select>';
            echo '</div>';
        }

        echo '</div>';
        echo '</div>';
    }

    public function save_product_meta($product_id) {
        // WooCommerce's own product-data save box already verifies this
        // nonce before woocommerce_process_product_meta fires; checking it
        // again here is cheap defense-in-depth against this handler ever
        // being reachable some other way.
        if (!isset($_POST['woocommerce_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['woocommerce_meta_nonce'])), 'woocommerce_save_data')) {
            return;
        }

        // This fires on every product save WooCommerce processes, not just
        // booking-type ones. Bail immediately for anything else so a save
        // elsewhere in the store can never touch Bookflow meta.
        $product_type = sanitize_key($_POST['product-type'] ?? '');
        if ($product_type !== 'booking') {
            return;
        }

        $fields = [
            '_bookflow_duration', '_bookflow_min_persons', '_bookflow_max_persons',
            '_bookflow_buffer_time', '_bookflow_max_bookings_per_slot', '_bookflow_min_advance',
            '_bookflow_max_advance', '_bookflow_cancel_before_hours', '_bookflow_time_slots',
            '_bookflow_base_price', '_bookflow_blocked_dates', '_bookflow_date_range_from',
            '_bookflow_date_range_to', '_bookflow_terms_text', '_bookflow_deposit_percent',
        ];

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($product_id, $field, sanitize_textarea_field(wp_unslash($_POST[$field])));
            }
        }

        // Save available days — only touch this if the Booking tab's
        // checkboxes were actually part of the submitted form (the marker
        // hidden field below). Otherwise "no boxes checked" is
        // indistinguishable from "this tab wasn't submitted at all", and
        // the latter must never silently wipe existing availability.
        if (isset($_POST['_bookflow_booking_tab_present'])) {
            $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            $available_days = [];
            foreach ($days as $day) {
                if (isset($_POST['_bookflow_day_' . $day]) && sanitize_text_field(wp_unslash($_POST['_bookflow_day_' . $day])) === 'yes') {
                    $available_days[] = $day;
                }
            }
            update_post_meta($product_id, '_bookflow_available_days', $available_days);
        }

        // Save pricing rules
        $pricing_rules = [];
        for ($i = 0; $i < 5; $i++) {
            $from  = sanitize_text_field(wp_unslash($_POST["_bookflow_pricing_rules_{$i}_from"] ?? ''));
            $to    = sanitize_text_field(wp_unslash($_POST["_bookflow_pricing_rules_{$i}_to"] ?? ''));
            $price = sanitize_text_field(wp_unslash($_POST["_bookflow_pricing_rules_{$i}_price"] ?? ''));
            $label = sanitize_text_field(wp_unslash($_POST["_bookflow_pricing_rules_{$i}_label"] ?? ''));

            if (!empty($from) && !empty($to) && !empty($price)) {
                $pricing_rules[] = compact('from', 'to', 'price', 'label');
            }
        }
        update_post_meta($product_id, '_bookflow_pricing_rules', $pricing_rules);

        // Handle resource assignment
        if (!empty($_POST['bookflow_assign_resource'])) {
            $resource_id = absint($_POST['bookflow_assign_resource']);
            if ($resource_id) {
                Bookflow_Resources::assign_to_product($resource_id, $product_id);
            }
        }

        // Handle resource unassignment
        if (!empty($_POST['bookflow_unassign_resources']) && is_array($_POST['bookflow_unassign_resources'])) {
            foreach (wp_unslash($_POST['bookflow_unassign_resources']) as $rid) {
                Bookflow_Resources::unassign_from_product(absint($rid), $product_id);
            }
        }
    }

    public function admin_scripts($hook) {
        global $post_type;
        if ($post_type !== 'product') {
            return;
        }

        wp_enqueue_style('bookflow-admin', BOOKFLOW_PLUGIN_URL . 'admin/css/admin.css', [], BOOKFLOW_VERSION);
        wp_enqueue_script('bookflow-admin', BOOKFLOW_PLUGIN_URL . 'admin/js/admin.js', ['jquery'], BOOKFLOW_VERSION, true);
        wp_localize_script('bookflow-admin', 'bookflowAdminI18n', [
            'type_name'              => Bookflow_I18n::t('product.type_name'),
            'type_name_placeholder'  => Bookflow_I18n::t('product.type_name_placeholder'),
            'cost_per_person'        => Bookflow_I18n::t('product.cost_per_person'),
            'min_qty'                => Bookflow_I18n::t('product.min_qty'),
            'max_qty'                => Bookflow_I18n::t('product.max_qty'),
            'remove'                 => Bookflow_I18n::t('product.remove'),
        ]);
    }
}

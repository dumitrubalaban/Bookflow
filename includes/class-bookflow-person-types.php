<?php
/**
 * Person Types (Adults, Children, Seniors, etc.)
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Person_Types {

    public function __construct() {
        add_action('woocommerce_product_data_panels', [$this, 'render_panel']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_meta']);
    }

    /**
     * Get person types for a product
     */
    public static function get_for_product($product_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bookflow_person_types WHERE product_id = %d ORDER BY sort_order ASC",
            absint($product_id)
        ));
    }

    /**
     * Get a single person type
     */
    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bookflow_person_types WHERE id = %d",
            absint($id)
        ));
    }

    /**
     * Save person types for a product
     */
    public static function save_for_product($product_id, $types) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_person_types';

        // Get existing IDs
        $existing = wp_list_pluck(self::get_for_product($product_id), 'id');
        $updated_ids = [];

        foreach ($types as $i => $type) {
            if (empty($type['name'])) {
                continue;
            }

            $data = [
                'product_id'  => absint($product_id),
                'name'        => sanitize_text_field($type['name']),
                'description' => sanitize_text_field($type['description'] ?? ''),
                'cost'        => (float) ($type['cost'] ?? 0),
                'min_qty'     => absint($type['min_qty'] ?? 0),
                'max_qty'     => absint($type['max_qty'] ?? 0),
                'sort_order'  => $i,
            ];

            if (!empty($type['id']) && in_array($type['id'], $existing)) {
                $wpdb->update($table, $data, ['id' => absint($type['id'])]);
                $updated_ids[] = (int) $type['id'];
            } else {
                $wpdb->insert($table, $data);
                $updated_ids[] = $wpdb->insert_id;
            }
        }

        // Delete removed types
        $to_delete = array_diff($existing, $updated_ids);
        foreach ($to_delete as $del_id) {
            $wpdb->delete($table, ['id' => absint($del_id)], ['%d']);
        }
    }

    /**
     * Validate a person-type quantity selection against each type's min/max.
     *
     * @param int   $product_id
     * @param array $persons_data  [ person_type_id => quantity, ... ]
     * @return true|WP_Error
     */
    public static function validate($product_id, $persons_data) {
        if (!self::product_has_types($product_id)) {
            return true; // simple-persons product — nothing to validate here
        }
        $persons_data = is_array($persons_data) ? $persons_data : [];
        $total = 0;
        foreach (self::get_for_product($product_id) as $type) {
            $qty = isset($persons_data[$type->id]) ? max(0, (int) $persons_data[$type->id]) : 0;
            $min = (int) $type->min_qty;
            $max = (int) $type->max_qty;
            if ($qty < $min) {
                return new WP_Error('person_type_min', Bookflow_I18n::t('error.person_type_min', $type->name, $min));
            }
            if ($max > 0 && $qty > $max) {
                return new WP_Error('person_type_max', Bookflow_I18n::t('error.person_type_max', $type->name, $max));
            }
            $total += $qty;
        }
        if ($total < 1) {
            return new WP_Error('person_type_empty', Bookflow_I18n::t('error.select_at_least_one_person'));
        }
        return true;
    }

    /**
     * Check if product uses person types (vs simple person count)
     */
    public static function product_has_types($product_id) {
        $enabled = get_post_meta($product_id, '_bookflow_enable_person_types', true);
        if ($enabled !== 'yes') {
            return false;
        }
        $types = self::get_for_product($product_id);
        return !empty($types);
    }

    /**
     * Render person types panel in product editor
     */
    public function render_panel() {
        global $post;
        $product_id = $post->ID;
        $enabled = get_post_meta($product_id, '_bookflow_enable_person_types', true);
        $types = self::get_for_product($product_id);

        echo '<div id="bookflow_person_types_data" class="panel woocommerce_options_panel" style="display:none;">';

        woocommerce_wp_checkbox([
            'id'      => '_bookflow_enable_person_types',
            'label'   => Bookflow_I18n::t('product.enable_person_types'),
            'value'   => $enabled,
            'description' => Bookflow_I18n::t('product.enable_person_types_desc'),
        ]);

        echo '<div class="options_group bookflow-person-types-group" ' . ($enabled !== 'yes' ? 'style="display:none;"' : '') . '>';
        echo '<h4 style="padding-left:12px;">' . esc_html(Bookflow_I18n::t('product.person_types_title')) . '</h4>';

        echo '<div id="bookflow-person-types-list">';
        $count = max(count($types), 1);
        for ($i = 0; $i < $count; $i++) {
            $type = $types[$i] ?? ['id' => '', 'name' => '', 'description' => '', 'cost' => '', 'min_qty' => '0', 'max_qty' => '10'];
            self::render_type_row($i, $type);
        }
        echo '</div>';

        echo '<p style="padding-left:12px;"><button type="button" class="button bookflow-add-person-type">' . esc_html(Bookflow_I18n::t('product.add_person_type')) . '</button></p>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Render a single person type row
     */
    private static function render_type_row($index, $type) {
        $type = (array) $type;
        echo '<div class="bookflow-person-type-row options_group" style="border-top:1px solid #eee; padding-top:10px;">';
        echo '<input type="hidden" name="bookflow_person_types[' . esc_attr($index) . '][id]" value="' . esc_attr($type['id'] ?? '') . '">';

        woocommerce_wp_text_input([
            'id'    => "bookflow_person_types_{$index}_name",
            'name'  => "bookflow_person_types[{$index}][name]",
            'label' => Bookflow_I18n::t('product.type_name'),
            'value' => $type['name'] ?? '',
            'placeholder' => Bookflow_I18n::t('product.type_name_placeholder'),
        ]);

        woocommerce_wp_text_input([
            'id'        => "bookflow_person_types_{$index}_cost",
            'name'      => "bookflow_person_types[{$index}][cost]",
            'label'     => Bookflow_I18n::t('product.cost_per_person'),
            'value'     => $type['cost'] ?? '',
            'data_type' => 'price',
        ]);

        woocommerce_wp_text_input([
            'id'    => "bookflow_person_types_{$index}_min",
            'name'  => "bookflow_person_types[{$index}][min_qty]",
            'label' => Bookflow_I18n::t('product.min_qty'),
            'type'  => 'number',
            'value' => $type['min_qty'] ?? 0,
        ]);

        woocommerce_wp_text_input([
            'id'    => "bookflow_person_types_{$index}_max",
            'name'  => "bookflow_person_types[{$index}][max_qty]",
            'label' => Bookflow_I18n::t('product.max_qty'),
            'type'  => 'number',
            'value' => $type['max_qty'] ?? 10,
        ]);

        echo '<p style="padding-left:12px;"><button type="button" class="button bookflow-remove-person-type" style="color:#d63638;">' . esc_html(Bookflow_I18n::t('product.remove')) . '</button></p>';
        echo '</div>';
    }

    /**
     * Save person types on product save
     */
    public function save_product_meta($product_id) {
        // WooCommerce's own product-data save box already verifies this
        // nonce before woocommerce_process_product_meta fires; checking it
        // again here is cheap defense-in-depth against this handler ever
        // being reachable some other way.
        if (!isset($_POST['woocommerce_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['woocommerce_meta_nonce'])), 'woocommerce_save_data')) {
            return;
        }

        $enabled = isset($_POST['_bookflow_enable_person_types']) ? 'yes' : 'no';
        update_post_meta($product_id, '_bookflow_enable_person_types', $enabled);

        if ($enabled === 'yes' && !empty($_POST['bookflow_person_types']) && is_array($_POST['bookflow_person_types'])) {
            self::save_for_product($product_id, wp_unslash($_POST['bookflow_person_types']));
        }
    }
}

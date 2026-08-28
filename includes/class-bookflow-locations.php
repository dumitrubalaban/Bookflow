<?php
/**
 * Location Metadata (Address / Coordinates)
 *
 * Locations in Bookflow are plain WooCommerce product tags (see
 * Bookflow_REST_API::get_locations()) so store owners manage them with the tag
 * UI they already know. This class only adds three extra term-meta fields
 * on that same tag screen — address, latitude, longitude — so a location
 * step can show a map preview and address line instead of a bare name.
 * Every product_tag gets these fields; harmless for tags that aren't used
 * as a location.
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Locations {

    public function __construct() {
        add_action('product_tag_add_form_fields', [$this, 'add_fields']);
        add_action('product_tag_edit_form_fields', [$this, 'edit_fields']);
        add_action('created_product_tag', [$this, 'save_fields']);
        add_action('edited_product_tag', [$this, 'save_fields']);
    }

    public function add_fields() {
        ?>
        <div class="form-field">
            <label for="bookflow_location_address"><?php Bookflow_I18n::te('location.address'); ?></label>
            <input type="text" name="bookflow_location_address" id="bookflow_location_address" value="">
            <p><?php Bookflow_I18n::te('location.address_desc'); ?></p>
        </div>
        <div class="form-field">
            <label for="bookflow_location_lat"><?php Bookflow_I18n::te('location.coordinates'); ?></label>
            <input type="text" name="bookflow_location_lat" id="bookflow_location_lat" value="" placeholder="47.0245" style="width:48%;">
            <input type="text" name="bookflow_location_lng" id="bookflow_location_lng" value="" placeholder="28.8322" style="width:48%;">
            <p><?php Bookflow_I18n::te('location.coordinates_desc'); ?></p>
        </div>
        <?php
    }

    public function edit_fields($term) {
        $address = get_term_meta($term->term_id, '_bookflow_location_address', true);
        $lat     = get_term_meta($term->term_id, '_bookflow_location_lat', true);
        $lng     = get_term_meta($term->term_id, '_bookflow_location_lng', true);
        ?>
        <tr class="form-field">
            <th scope="row"><label for="bookflow_location_address"><?php Bookflow_I18n::te('location.address'); ?></label></th>
            <td>
                <input type="text" name="bookflow_location_address" id="bookflow_location_address" value="<?php echo esc_attr($address); ?>">
                <p class="description"><?php Bookflow_I18n::te('location.address_desc'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="bookflow_location_lat"><?php Bookflow_I18n::te('location.coordinates'); ?></label></th>
            <td>
                <input type="text" name="bookflow_location_lat" id="bookflow_location_lat" value="<?php echo esc_attr($lat); ?>" placeholder="47.0245" style="width:48%;">
                <input type="text" name="bookflow_location_lng" id="bookflow_location_lng" value="<?php echo esc_attr($lng); ?>" placeholder="28.8322" style="width:48%;">
                <p class="description"><?php Bookflow_I18n::te('location.coordinates_desc'); ?></p>
            </td>
        </tr>
        <?php
    }

    public function save_fields($term_id) {
        if (!isset($_POST['bookflow_location_address']) && !isset($_POST['bookflow_location_lat']) && !isset($_POST['bookflow_location_lng'])) {
            return;
        }
        // Term add/edit screens are already nonce-gated by WordPress core
        // (edit-tags.php verifies 'add-tag' / 'update-tag-{$tag_id}' before
        // these hooks ever fire) — sanitize only.
        if (isset($_POST['bookflow_location_address'])) {
            update_term_meta($term_id, '_bookflow_location_address', sanitize_text_field(wp_unslash($_POST['bookflow_location_address'])));
        }
        if (isset($_POST['bookflow_location_lat'])) {
            $lat = sanitize_text_field(wp_unslash($_POST['bookflow_location_lat']));
            update_term_meta($term_id, '_bookflow_location_lat', $lat === '' ? '' : (string) (float) $lat);
        }
        if (isset($_POST['bookflow_location_lng'])) {
            $lng = sanitize_text_field(wp_unslash($_POST['bookflow_location_lng']));
            update_term_meta($term_id, '_bookflow_location_lng', $lng === '' ? '' : (string) (float) $lng);
        }
    }

    /**
     * A no-API-key static map thumbnail URL for a location, or null when
     * it has no coordinates yet. Any storefront can swap the provider via
     * the bookflow_location_map_url filter without touching this class.
     */
    public static function get_map_url($lat, $lng) {
        if ($lat === '' || $lng === '' || $lat === null || $lng === null) {
            return null;
        }
        $url = sprintf(
            'https://staticmap.openstreetmap.de/staticmap.php?center=%s,%s&zoom=15&size=400x200&markers=%s,%s,red-pushpin',
            $lat, $lng, $lat, $lng
        );
        return apply_filters('bookflow_location_map_url', $url, $lat, $lng);
    }
}

<?php
/**
 * Locations (first-class entity)
 *
 * Each location has its own working days, blocked dates, and holidays —
 * a product assigned to a location (via `_bookflow_location_id` post meta)
 * must satisfy BOTH the product's own availability rules and the
 * location's, checked in Bookflow_Availability::is_date_available().
 *
 * A matching WooCommerce product_tag is kept in sync (by slug) purely so
 * the existing `/services?location=` catalog filter keeps working — the
 * tag itself is no longer read for scheduling.
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Locations {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_submenu']);
        add_action('wp_ajax_bookflow_save_location', [$this, 'ajax_save']);
        add_action('wp_ajax_bookflow_delete_location', [$this, 'ajax_delete']);
    }

    public function add_submenu() {
        add_submenu_page(
            'bookflow-bookings',
            Bookflow_I18n::t('admin.locations'),
            Bookflow_I18n::t('admin.locations'),
            'manage_woocommerce',
            'bookflow-locations',
            [$this, 'render_page']
        );
    }

    // --- CRUD ---

    public static function create($data) {
        global $wpdb;
        $term_id = self::sync_tag(null, $data['name']);
        $wpdb->insert($wpdb->prefix . 'bookflow_locations', [
            'name'           => sanitize_text_field($data['name']),
            'term_id'        => $term_id,
            'address'        => sanitize_text_field($data['address'] ?? ''),
            'lat'            => self::sanitize_coord($data['lat'] ?? ''),
            'lng'            => self::sanitize_coord($data['lng'] ?? ''),
            'available_days' => wp_json_encode($data['available_days'] ?? []),
            'blocked_dates'  => wp_json_encode($data['blocked_dates'] ?? []),
            'holidays'       => wp_json_encode($data['holidays'] ?? []),
            'sort_order'     => absint($data['sort_order'] ?? 0),
            'status'         => sanitize_text_field($data['status'] ?? 'active'),
        ]);
        return $wpdb->insert_id;
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bookflow_locations WHERE id = %d",
            absint($id)
        ));
    }

    public static function get_all($status = null) {
        global $wpdb;
        $sql = "SELECT * FROM {$wpdb->prefix}bookflow_locations";
        if ($status) {
            $sql = $wpdb->prepare($sql . " WHERE status = %s", $status);
        }
        $sql .= " ORDER BY sort_order ASC, name ASC";
        return $wpdb->get_results($sql);
    }

    public static function update($id, $data) {
        global $wpdb;
        $location = self::get($id);
        $update = [];
        if (isset($data['name'])) {
            $update['name'] = sanitize_text_field($data['name']);
            $update['term_id'] = self::sync_tag($location ? $location->term_id : null, $data['name']);
        }
        if (isset($data['address']))        $update['address'] = sanitize_text_field($data['address']);
        if (isset($data['lat']))            $update['lat'] = self::sanitize_coord($data['lat']);
        if (isset($data['lng']))            $update['lng'] = self::sanitize_coord($data['lng']);
        if (isset($data['available_days'])) $update['available_days'] = wp_json_encode($data['available_days']);
        if (isset($data['blocked_dates']))  $update['blocked_dates'] = wp_json_encode($data['blocked_dates']);
        if (isset($data['holidays']))       $update['holidays'] = wp_json_encode($data['holidays']);
        if (isset($data['sort_order']))     $update['sort_order'] = absint($data['sort_order']);
        if (isset($data['status']))         $update['status'] = sanitize_text_field($data['status']);

        return $wpdb->update($wpdb->prefix . 'bookflow_locations', $update, ['id' => absint($id)]);
    }

    public static function delete($id) {
        global $wpdb;
        return $wpdb->delete($wpdb->prefix . 'bookflow_locations', ['id' => absint($id)], ['%d']);
    }

    private static function sanitize_coord($val) {
        $val = sanitize_text_field((string) $val);
        return $val === '' ? '' : (string) (float) $val;
    }

    /**
     * Find-or-create a product_tag with this name and return its term_id,
     * so `/services?location=` (tag-slug based) keeps working unchanged.
     */
    private static function sync_tag($term_id, $name) {
        $name = sanitize_text_field($name);
        if ($term_id) {
            $term = get_term($term_id, 'product_tag');
            if ($term && !is_wp_error($term)) {
                if ($term->name !== $name) {
                    wp_update_term($term_id, 'product_tag', ['name' => $name]);
                }
                return $term_id;
            }
        }
        $existing = get_term_by('name', $name, 'product_tag');
        if ($existing) {
            return $existing->term_id;
        }
        $created = wp_insert_term($name, 'product_tag');
        return is_wp_error($created) ? null : $created['term_id'];
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

    // --- AJAX ---

    public function ajax_save() {
        check_ajax_referer('bookflow_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $id = absint($_POST['id'] ?? 0);
        $data = self::collect_post_data();

        if ($id) {
            self::update($id, $data);
        } else {
            $id = self::create($data);
        }

        wp_send_json_success(['id' => $id]);
    }

    public function ajax_delete() {
        check_ajax_referer('bookflow_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        self::delete(absint($_POST['id'] ?? 0));
        wp_send_json_success();
    }

    /**
     * "monday,tuesday" -> ['monday','tuesday']. Same convention as the
     * product-level available-days checkboxes.
     */
    /**
     * Called only from render_page() and ajax_save(), both of which verify
     * a nonce (wp_verify_nonce()/check_ajax_referer()) before reaching this
     * point — no nonce check needed here too.
     */
    private static function collect_post_data() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $days = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            if (!empty($_POST['day_' . $day])) {
                $days[] = $day;
            }
        }
        $parse_dates = function ($raw) {
            return array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string) $raw))));
        };

        return [
            'name'           => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'address'        => sanitize_text_field(wp_unslash($_POST['address'] ?? '')),
            'lat'            => sanitize_text_field(wp_unslash($_POST['lat'] ?? '')),
            'lng'            => sanitize_text_field(wp_unslash($_POST['lng'] ?? '')),
            'available_days' => $days,
            'blocked_dates'  => $parse_dates(wp_unslash($_POST['blocked_dates'] ?? '')),
            'holidays'       => $parse_dates(wp_unslash($_POST['holidays'] ?? '')),
            'sort_order'     => absint($_POST['sort_order'] ?? 0),
            'status'         => sanitize_text_field(wp_unslash($_POST['status'] ?? 'active')),
        ];
        // phpcs:enable
    }

    /**
     * Render locations admin page
     */
    public function render_page() {
        if (isset($_POST['bookflow_save_location'], $_POST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'bookflow_save_location')) {
            $data = self::collect_post_data();
            $id = absint($_POST['location_id'] ?? 0);
            if ($id) {
                self::update($id, $data);
            } else {
                self::create($data);
            }
            echo '<div class="notice notice-success"><p>' . esc_html(Bookflow_I18n::t('admin.location_saved')) . '</p></div>';
        }

        if (isset($_GET['delete'], $_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'bookflow_delete_location')) {
            self::delete(absint($_GET['delete']));
            echo '<div class="notice notice-success"><p>' . esc_html(Bookflow_I18n::t('admin.location_deleted')) . '</p></div>';
        }

        $locations = self::get_all();
        $editing = null;
        if (isset($_GET['edit'])) {
            $editing = self::get(absint($_GET['edit']));
        }

        $editing_days = $editing ? (array) json_decode($editing->available_days, true) : [];
        $editing_blocked = $editing ? implode("\n", (array) json_decode($editing->blocked_dates, true)) : '';
        $editing_holidays = $editing ? implode("\n", (array) json_decode($editing->holidays, true)) : '';

        $weekdays = [
            'monday' => Bookflow_I18n::t('weekday.monday'), 'tuesday' => Bookflow_I18n::t('weekday.tuesday'),
            'wednesday' => Bookflow_I18n::t('weekday.wednesday'), 'thursday' => Bookflow_I18n::t('weekday.thursday'),
            'friday' => Bookflow_I18n::t('weekday.friday'), 'saturday' => Bookflow_I18n::t('weekday.saturday'),
            'sunday' => Bookflow_I18n::t('weekday.sunday'),
        ];

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php Bookflow_I18n::te('admin.locations'); ?></h1>
            <hr class="wp-header-end">
            <p class="description"><?php Bookflow_I18n::te('admin.locations_desc'); ?></p>

            <div id="col-container" class="wp-clearfix">
                <div id="col-left">
                    <div class="col-wrap">
                        <div class="form-wrap">
                            <h2><?php echo $editing ? esc_html(Bookflow_I18n::t('admin.edit_location')) : esc_html(Bookflow_I18n::t('admin.add_location')); ?></h2>
                            <form method="post">
                                <?php wp_nonce_field('bookflow_save_location'); ?>
                                <input type="hidden" name="location_id" value="<?php echo esc_attr($editing->id ?? 0); ?>">

                                <div class="form-field form-required">
                                    <label for="bookflow-loc-name"><?php Bookflow_I18n::te('admin.title'); ?></label>
                                    <input type="text" name="name" id="bookflow-loc-name" value="<?php echo esc_attr($editing->name ?? ''); ?>" size="40" required>
                                </div>

                                <div class="form-field">
                                    <label for="bookflow-loc-address"><?php Bookflow_I18n::te('location.address'); ?></label>
                                    <input type="text" name="address" id="bookflow-loc-address" value="<?php echo esc_attr($editing->address ?? ''); ?>" size="40">
                                </div>

                                <div class="form-field">
                                    <label><?php Bookflow_I18n::te('location.coordinates'); ?></label>
                                    <input type="text" name="lat" value="<?php echo esc_attr($editing->lat ?? ''); ?>" placeholder="47.0245" style="width:48%;">
                                    <input type="text" name="lng" value="<?php echo esc_attr($editing->lng ?? ''); ?>" placeholder="28.8322" style="width:48%;">
                                </div>

                                <div class="form-field">
                                    <label><?php Bookflow_I18n::te('admin.available_days'); ?></label>
                                    <?php foreach ($weekdays as $slug => $label) : ?>
                                        <label style="margin-right:10px;"><input type="checkbox" name="day_<?php echo esc_attr($slug); ?>" value="1" <?php checked(in_array($slug, $editing_days, true)); ?>> <?php echo esc_html($label); ?></label>
                                    <?php endforeach; ?>
                                </div>

                                <div class="form-field">
                                    <label for="bookflow-loc-blocked"><?php Bookflow_I18n::te('product.blocked_dates_label'); ?></label>
                                    <textarea name="blocked_dates" id="bookflow-loc-blocked" rows="3" cols="40" placeholder="YYYY-MM-DD"><?php echo esc_textarea($editing_blocked); ?></textarea>
                                </div>

                                <div class="form-field">
                                    <label for="bookflow-loc-holidays"><?php Bookflow_I18n::te('admin.holidays'); ?></label>
                                    <textarea name="holidays" id="bookflow-loc-holidays" rows="3" cols="40" placeholder="YYYY-MM-DD"><?php echo esc_textarea($editing_holidays); ?></textarea>
                                    <p class="description"><?php Bookflow_I18n::te('admin.holidays_desc'); ?></p>
                                </div>

                                <div class="form-field">
                                    <label for="bookflow-loc-sort"><?php Bookflow_I18n::te('admin.sort_order'); ?></label>
                                    <input type="number" name="sort_order" id="bookflow-loc-sort" value="<?php echo esc_attr($editing->sort_order ?? 0); ?>" min="0">
                                </div>

                                <div class="form-field">
                                    <label for="bookflow-loc-status"><?php Bookflow_I18n::te('admin.status'); ?></label>
                                    <select name="status" id="bookflow-loc-status">
                                        <option value="active" <?php selected($editing->status ?? 'active', 'active'); ?>><?php Bookflow_I18n::te('admin.active'); ?></option>
                                        <option value="inactive" <?php selected($editing->status ?? '', 'inactive'); ?>><?php Bookflow_I18n::te('admin.inactive'); ?></option>
                                    </select>
                                </div>

                                <p class="submit">
                                    <button type="submit" name="bookflow_save_location" class="button button-primary"><?php Bookflow_I18n::te('admin.save_location'); ?></button>
                                    <?php if ($editing) : ?>
                                        <a href="<?php echo esc_url(remove_query_arg('edit')); ?>" class="button"><?php Bookflow_I18n::te('admin.cancel_edit'); ?></a>
                                    <?php endif; ?>
                                </p>
                            </form>
                        </div>
                    </div>
                </div>

                <div id="col-right">
                    <div class="col-wrap">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th><?php Bookflow_I18n::te('admin.title'); ?></th>
                                    <th><?php Bookflow_I18n::te('location.address'); ?></th>
                                    <th><?php Bookflow_I18n::te('admin.status'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($locations)) : ?>
                                    <tr><td colspan="4"><?php Bookflow_I18n::te('admin.no_locations'); ?></td></tr>
                                <?php else : foreach ($locations as $loc) : ?>
                                    <tr>
                                        <td><?php echo esc_html($loc->id); ?></td>
                                        <td>
                                            <strong><a href="<?php echo esc_url(add_query_arg('edit', $loc->id)); ?>"><?php echo esc_html($loc->name); ?></a></strong>
                                            <div class="row-actions">
                                                <span class="edit"><a href="<?php echo esc_url(add_query_arg('edit', $loc->id)); ?>"><?php Bookflow_I18n::te('admin.edit'); ?></a> | </span>
                                                <span class="delete"><a href="<?php echo esc_url(wp_nonce_url(add_query_arg('delete', $loc->id), 'bookflow_delete_location')); ?>" class="submitdelete" onclick="return confirm('<?php echo esc_attr(Bookflow_I18n::t('admin.delete_location_confirm')); ?>')"><?php Bookflow_I18n::te('admin.delete'); ?></a></span>
                                            </div>
                                        </td>
                                        <td><?php echo esc_html($loc->address ?: '&mdash;'); ?></td>
                                        <td><?php echo esc_html(ucfirst($loc->status)); ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

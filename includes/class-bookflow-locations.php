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
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_bookflow_save_location', [$this, 'ajax_save']);
        add_action('wp_ajax_bookflow_delete_location', [$this, 'ajax_delete']);
        add_action('wp_ajax_bookflow_list_locations', [$this, 'ajax_list']);
    }

    const DAY_NAMES = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function enqueue($hook) {
        if (strpos($hook, 'bookflow-locations') === false) {
            return;
        }
        $bundle_js = BOOKFLOW_PLUGIN_DIR . 'admin/dist/admin-locations.js';
        if (!file_exists($bundle_js)) {
            return;
        }
        $bundle_css = BOOKFLOW_PLUGIN_DIR . 'admin/dist/admin-locations.css';
        if (file_exists($bundle_css)) {
            wp_enqueue_style('bookflow-admin-locations', BOOKFLOW_PLUGIN_URL . 'admin/dist/admin-locations.css', [], BOOKFLOW_VERSION);
        }
        wp_enqueue_script('bookflow-admin-locations', BOOKFLOW_PLUGIN_URL . 'admin/dist/admin-locations.js', [], BOOKFLOW_VERSION, true);

        $day_labels = [];
        foreach (self::DAY_NAMES as $d) {
            $day_labels[] = Bookflow_I18n::t('calendar.weekday.' . substr($d, 0, 3));
        }

        wp_localize_script('bookflow-admin-locations', 'bookflowAdminLocations', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('bookflow_admin_nonce'),
            'dayNames'  => self::DAY_NAMES,
            'dayLabels' => $day_labels,
            'i18n'      => [
                'name'          => Bookflow_I18n::t('admin.name'),
                'address'       => Bookflow_I18n::t('admin.address'),
                'lat'           => Bookflow_I18n::t('admin.lat'),
                'lng'           => Bookflow_I18n::t('admin.lng'),
                'availableDays' => Bookflow_I18n::t('admin.available_days'),
                'blockedDates'  => Bookflow_I18n::t('product.blocked_dates_label'),
                'holidays'      => Bookflow_I18n::t('admin.holidays'),
                'sortOrder'     => Bookflow_I18n::t('admin.sort_order'),
                'status'        => Bookflow_I18n::t('admin.status'),
                'active'        => Bookflow_I18n::t('admin.active'),
                'inactive'      => Bookflow_I18n::t('admin.inactive'),
                'addNew'        => Bookflow_I18n::t('admin.add_new'),
                'edit'          => Bookflow_I18n::t('admin.edit'),
                'delete'        => Bookflow_I18n::t('admin.delete'),
                'save'          => Bookflow_I18n::t('admin.save'),
                'cancel'        => Bookflow_I18n::t('admin.cancel_edit'),
                'confirmDelete' => Bookflow_I18n::t('admin.confirm_delete'),
                'noItems'       => Bookflow_I18n::t('admin.no_items'),
                'errorGeneric'  => Bookflow_I18n::t('calendar.error_generic'),
            ],
        ]);
    }

    public function ajax_list() {
        check_ajax_referer('bookflow_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $rows = self::get_all();
        $result = array_map(function ($l) {
            return [
                'id'             => (int) $l->id,
                'name'           => $l->name,
                'address'        => $l->address,
                'lat'            => $l->lat,
                'lng'            => $l->lng,
                'available_days' => (array) json_decode($l->available_days, true),
                'blocked_dates'  => implode("\n", (array) json_decode($l->blocked_dates, true)),
                'holidays'       => implode("\n", (array) json_decode($l->holidays, true)),
                'sort_order'     => (int) $l->sort_order,
                'status'         => $l->status,
            ];
        }, $rows);
        wp_send_json_success(['items' => $result]);
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
        // One YYYY-MM-DD per line; sanitize each line and drop anything
        // that isn't actually a date rather than trusting free-form input.
        $parse_dates = function ($raw) {
            $lines = preg_split('/[\r\n]+/', sanitize_textarea_field((string) $raw));
            $lines = array_map('trim', $lines);
            return array_values(array_filter($lines, function ($line) {
                return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $line);
            }));
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
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php Bookflow_I18n::te('admin.locations'); ?></h1>
            <hr class="wp-header-end">
            <div id="bookflow-admin-locations-root" style="margin-top:16px;">
                <?php if (!file_exists(BOOKFLOW_PLUGIN_DIR . 'admin/dist/admin-locations.js')) : ?>
                    <p><em>Run <code>npm run build</code> in <code>svelte-src/</code> to build this page.</em></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

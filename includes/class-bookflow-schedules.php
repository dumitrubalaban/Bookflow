<?php
/**
 * Product Schedule Variants Management
 *
 * Manages per-product schedule options (e.g., different languages with
 * distinct available days, time slots, and capacity).
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Schedules {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_submenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_bookflow_save_schedule', [$this, 'ajax_save']);
        add_action('wp_ajax_bookflow_delete_schedule', [$this, 'ajax_delete']);
        add_action('wp_ajax_bookflow_list_schedules', [$this, 'ajax_list']);
    }

    const DAY_NAMES = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function enqueue($hook) {
        if (strpos($hook, 'bookflow-schedules') === false) {
            return;
        }
        $bundle_js = BOOKFLOW_PLUGIN_DIR . 'admin/dist/admin-schedules.js';
        if (!file_exists($bundle_js)) {
            return;
        }
        $bundle_css = BOOKFLOW_PLUGIN_DIR . 'admin/dist/admin-schedules.css';
        if (file_exists($bundle_css)) {
            wp_enqueue_style('bookflow-admin-schedules', BOOKFLOW_PLUGIN_URL . 'admin/dist/admin-schedules.css', [], BOOKFLOW_VERSION);
        }
        wp_enqueue_script('bookflow-admin-schedules', BOOKFLOW_PLUGIN_URL . 'admin/dist/admin-schedules.js', [], BOOKFLOW_VERSION, true);

        $products = [];
        if (function_exists('wc_get_products')) {
            $wc_products = wc_get_products([
                'limit' => -1, 'status' => 'publish', 'type' => ['simple', 'booking'],
                'orderby' => 'title', 'order' => 'ASC',
            ]);
            foreach ($wc_products as $p) {
                $products[] = ['id' => $p->get_id(), 'name' => $p->get_name() . ' (#' . $p->get_id() . ')'];
            }
        }

        $day_labels = [];
        foreach (self::DAY_NAMES as $d) {
            $day_labels[] = Bookflow_I18n::t('calendar.weekday.' . substr($d, 0, 3));
        }

        wp_localize_script('bookflow-admin-schedules', 'bookflowAdminSchedules', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('bookflow_admin_nonce'),
            'products'  => $products,
            'dayNames'  => self::DAY_NAMES,
            'dayLabels' => $day_labels,
            'i18n'      => [
                'product'            => Bookflow_I18n::t('admin.product'),
                'selectProduct'      => Bookflow_I18n::t('admin.select_product'),
                'optionGroup'        => Bookflow_I18n::t('admin.option_group'),
                'optionLabel'        => Bookflow_I18n::t('admin.option_label'),
                'optionValue'        => Bookflow_I18n::t('admin.option_value'),
                'availableDays'      => Bookflow_I18n::t('admin.available_days'),
                'timeSlots'          => Bookflow_I18n::t('admin.time_slots'),
                'maxPersons'         => Bookflow_I18n::t('admin.max_persons'),
                'maxBookingsPerSlot' => Bookflow_I18n::t('admin.max_bookings_per_slot'),
                'priceModifier'      => Bookflow_I18n::t('admin.price_modifier'),
                'sortOrder'          => Bookflow_I18n::t('admin.sort_order'),
                'status'             => Bookflow_I18n::t('admin.status'),
                'active'             => Bookflow_I18n::t('admin.active'),
                'inactive'           => Bookflow_I18n::t('admin.inactive'),
                'addNew'             => Bookflow_I18n::t('admin.add_new'),
                'edit'               => Bookflow_I18n::t('admin.edit'),
                'delete'             => Bookflow_I18n::t('admin.delete'),
                'save'               => Bookflow_I18n::t('admin.save'),
                'cancel'             => Bookflow_I18n::t('admin.cancel_edit'),
                'confirmDelete'      => Bookflow_I18n::t('admin.confirm_delete'),
                'noItems'            => Bookflow_I18n::t('admin.no_items'),
                'errorGeneric'       => Bookflow_I18n::t('calendar.error_generic'),
            ],
        ]);
    }

    public function ajax_list() {
        check_ajax_referer('bookflow_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $rows = self::get_all();
        $result = array_map(function ($s) {
            $product_name = '#' . $s->product_id;
            if (function_exists('wc_get_product')) {
                $p = wc_get_product($s->product_id);
                if ($p) {
                    $product_name = $p->get_name() . ' (#' . $s->product_id . ')';
                }
            }
            $days = json_decode($s->available_days, true);
            $slots = json_decode($s->time_slots, true);
            return [
                'id'                    => (int) $s->id,
                'product_id'            => (int) $s->product_id,
                'product_name'          => $product_name,
                'option_group'          => $s->option_group,
                'option_label'          => $s->option_label,
                'option_value'          => $s->option_value,
                'available_days'        => is_array($days) ? $days : [],
                'time_slots'            => is_array($slots) ? implode("\n", $slots) : '',
                'max_persons'           => (int) $s->max_persons,
                'max_bookings_per_slot' => (int) $s->max_bookings_per_slot,
                'price_modifier'        => (float) $s->price_modifier,
                'sort_order'            => (int) $s->sort_order,
                'status'                => $s->status,
            ];
        }, $rows);
        wp_send_json_success(['items' => $result]);
    }

    /**
     * Add schedules submenu
     */
    public function add_submenu() {
        add_submenu_page(
            'bookflow-bookings',
            Bookflow_I18n::t('admin.schedules'),
            Bookflow_I18n::t('admin.schedules'),
            'manage_woocommerce',
            'bookflow-schedules',
            [$this, 'render_page']
        );
    }

    // ---------------------------------------------------------------
    //  CRUD
    // ---------------------------------------------------------------

    /**
     * Create a new product schedule.
     *
     * @param array $data Schedule data.
     * @return int|false Insert ID on success, false on failure.
     */
    public static function create($data) {
        global $wpdb;

        $available_days = isset($data['available_days']) && is_array($data['available_days'])
            ? wp_json_encode(array_map('sanitize_text_field', $data['available_days']))
            : '[]';

        $time_slots = isset($data['time_slots']) && is_array($data['time_slots'])
            ? wp_json_encode(array_map('sanitize_text_field', $data['time_slots']))
            : '[]';

        $result = $wpdb->insert($wpdb->prefix . 'bookflow_product_schedules', [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
            'product_id'            => absint($data['product_id']),
            'option_group'          => sanitize_text_field($data['option_group'] ?? 'language'),
            'option_label'          => sanitize_text_field($data['option_label'] ?? ''),
            'option_value'          => sanitize_text_field($data['option_value'] ?? ''),
            'available_days'        => $available_days,
            'time_slots'            => $time_slots,
            'max_persons'           => absint($data['max_persons'] ?? 0),
            'max_bookings_per_slot' => absint($data['max_bookings_per_slot'] ?? 0),
            'price_modifier'        => floatval($data['price_modifier'] ?? 0),
            'sort_order'            => absint($data['sort_order'] ?? 0),
            'status'                => sanitize_text_field($data['status'] ?? 'active'),
        ]);

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get a single schedule by ID.
     *
     * @param int $id Schedule ID.
     * @return object|null
     */
    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
            "SELECT * FROM {$wpdb->prefix}bookflow_product_schedules WHERE id = %d",
            absint($id)
        ));
    }

    /**
     * Get all schedules, optionally filtered by product.
     *
     * @param int|null $product_id Optional product ID filter.
     * @return array
     */
    public static function get_all($product_id = null) {
        global $wpdb;
        // $sql is only ever extended with fixed literal fragments — the
        // one dynamic value ($product_id) already went through %d +
        // prepare() above before the literal ORDER BY is appended.
        $sql = "SELECT * FROM {$wpdb->prefix}bookflow_product_schedules";
        if ($product_id) {
            $sql = $wpdb->prepare($sql . " WHERE product_id = %d", absint($product_id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        $sql .= " ORDER BY product_id ASC, sort_order ASC, id ASC";
        return $wpdb->get_results($sql); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- $sql is either the fixed base query or that query with $product_id already substituted via prepare() above
    }

    /**
     * Get active schedules for a product, optionally filtered by option_group.
     *
     * @param int         $product_id  Product ID.
     * @param string|null $option_group Optional group filter (e.g. 'language').
     * @return array
     */
    public static function get_for_product($product_id, $option_group = null) {
        global $wpdb;
        if ($option_group) {
            return $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
                "SELECT * FROM {$wpdb->prefix}bookflow_product_schedules
                 WHERE product_id = %d AND option_group = %s AND status = 'active'
                 ORDER BY sort_order ASC, id ASC",
                absint($product_id),
                sanitize_text_field($option_group)
            ));
        }
        return $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
            "SELECT * FROM {$wpdb->prefix}bookflow_product_schedules
             WHERE product_id = %d AND status = 'active'
             ORDER BY sort_order ASC, id ASC",
            absint($product_id)
        ));
    }

    /**
     * Update schedule fields.
     *
     * @param int   $id   Schedule ID.
     * @param array $data Fields to update.
     * @return int|false Number of rows updated, or false on error.
     */
    public static function update($id, $data) {
        global $wpdb;
        $update = [];

        if (isset($data['product_id']))            $update['product_id']            = absint($data['product_id']);
        if (isset($data['option_group']))           $update['option_group']          = sanitize_text_field($data['option_group']);
        if (isset($data['option_label']))           $update['option_label']          = sanitize_text_field($data['option_label']);
        if (isset($data['option_value']))           $update['option_value']          = sanitize_text_field($data['option_value']);
        if (isset($data['max_persons']))            $update['max_persons']           = absint($data['max_persons']);
        if (isset($data['max_bookings_per_slot']))  $update['max_bookings_per_slot'] = absint($data['max_bookings_per_slot']);
        if (isset($data['price_modifier']))         $update['price_modifier']        = floatval($data['price_modifier']);
        if (isset($data['sort_order']))             $update['sort_order']            = absint($data['sort_order']);
        if (isset($data['status']))                 $update['status']                = sanitize_text_field($data['status']);

        if (isset($data['available_days']) && is_array($data['available_days'])) {
            $update['available_days'] = wp_json_encode(array_map('sanitize_text_field', $data['available_days']));
        }
        if (isset($data['time_slots']) && is_array($data['time_slots'])) {
            $update['time_slots'] = wp_json_encode(array_map('sanitize_text_field', $data['time_slots']));
        }

        if (empty($update)) {
            return false;
        }

        return $wpdb->update($wpdb->prefix . 'bookflow_product_schedules', $update, ['id' => absint($id)]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
    }

    /**
     * Delete a schedule.
     *
     * @param int $id Schedule ID.
     * @return int|false Number of rows deleted, or false on error.
     */
    public static function delete($id) {
        global $wpdb;
        return $wpdb->delete($wpdb->prefix . 'bookflow_product_schedules', ['id' => absint($id)], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
    }

    // ---------------------------------------------------------------
    //  Helpers
    // ---------------------------------------------------------------

    /**
     * Get distinct option_group values for a product.
     *
     * @param int $product_id Product ID.
     * @return array List of option_group strings.
     */
    public static function get_option_groups($product_id) {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
            "SELECT DISTINCT option_group FROM {$wpdb->prefix}bookflow_product_schedules
             WHERE product_id = %d ORDER BY option_group ASC",
            absint($product_id)
        ));
    }

    /**
     * Get decoded available_days array for a schedule.
     *
     * @param int $schedule_id Schedule ID.
     * @return array
     */
    public static function get_available_days($schedule_id) {
        $schedule = self::get($schedule_id);
        if (!$schedule || empty($schedule->available_days)) {
            return [];
        }
        $days = json_decode($schedule->available_days, true);
        return is_array($days) ? $days : [];
    }

    /**
     * Get decoded time_slots array for a schedule.
     *
     * @param int $schedule_id Schedule ID.
     * @return array
     */
    public static function get_time_slots($schedule_id) {
        $schedule = self::get($schedule_id);
        if (!$schedule || empty($schedule->time_slots)) {
            return [];
        }
        $slots = json_decode($schedule->time_slots, true);
        return is_array($slots) ? $slots : [];
    }

    /**
     * Check whether a product has any schedules.
     *
     * @param int $product_id Product ID.
     * @return bool
     */
    public static function product_has_schedules($product_id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
            "SELECT COUNT(*) FROM {$wpdb->prefix}bookflow_product_schedules WHERE product_id = %d",
            absint($product_id)
        ));
    }

    // ---------------------------------------------------------------
    //  AJAX handlers
    // ---------------------------------------------------------------

    /**
     * AJAX: Save (create or update) a schedule.
     */
    public function ajax_save() {
        check_ajax_referer('bookflow_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $id = absint($_POST['id'] ?? 0);

        // Parse available_days (array of checkboxes)
        $available_days = isset($_POST['available_days']) && is_array($_POST['available_days'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['available_days']))
            : [];

        // Parse time_slots (textarea, one per line)
        $time_slots_raw = sanitize_textarea_field(wp_unslash($_POST['time_slots'] ?? ''));
        $time_slots = array_values(array_filter(array_map('trim', explode("\n", $time_slots_raw))));

        $data = [
            'product_id'            => absint($_POST['product_id'] ?? 0),
            'option_group'          => sanitize_text_field(wp_unslash($_POST['option_group'] ?? 'language')),
            'option_label'          => sanitize_text_field(wp_unslash($_POST['option_label'] ?? '')),
            'option_value'          => sanitize_text_field(wp_unslash($_POST['option_value'] ?? '')),
            'available_days'        => $available_days,
            'time_slots'            => $time_slots,
            'max_persons'           => absint($_POST['max_persons'] ?? 0),
            'max_bookings_per_slot' => absint($_POST['max_bookings_per_slot'] ?? 0),
            'price_modifier'        => floatval($_POST['price_modifier'] ?? 0),
            'sort_order'            => absint($_POST['sort_order'] ?? 0),
            'status'                => sanitize_text_field(wp_unslash($_POST['status'] ?? 'active')),
        ];

        if ($id) {
            self::update($id, $data);
        } else {
            $id = self::create($data);
        }

        wp_send_json_success(['id' => $id]);
    }

    /**
     * AJAX: Delete a schedule.
     */
    public function ajax_delete() {
        check_ajax_referer('bookflow_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $id = absint($_POST['id'] ?? 0);
        self::delete($id);
        wp_send_json_success();
    }

    // ---------------------------------------------------------------
    //  Admin page
    // ---------------------------------------------------------------

    /**
     * Render the Schedules admin page.
     */
    public function render_page() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php Bookflow_I18n::te('admin.schedules'); ?></h1>
            <hr class="wp-header-end">
            <div id="bookflow-admin-schedules-root" style="margin-top:16px;">
                <?php if (!file_exists(BOOKFLOW_PLUGIN_DIR . 'admin/dist/admin-schedules.js')) : ?>
                    <p><em>Run <code>npm run build</code> in <code>svelte-src/</code> to build this page.</em></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

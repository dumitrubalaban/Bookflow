<?php
/**
 * Resources Management (Rooms, Guides, Vehicles)
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Resources {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_submenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_bookflow_save_resource', [$this, 'ajax_save']);
        add_action('wp_ajax_bookflow_delete_resource', [$this, 'ajax_delete']);
        add_action('wp_ajax_bookflow_list_resources', [$this, 'ajax_list']);
    }

    /**
     * Add resources submenu
     */
    public function add_submenu() {
        $hook = add_submenu_page(
            'bookflow-bookings',
            Bookflow_I18n::t('admin.resources'),
            Bookflow_I18n::t('admin.resources'),
            'manage_woocommerce',
            'bookflow-resources',
            [$this, 'render_page']
        );
        add_action("admin_enqueue_scripts", function ($current_hook) use ($hook) {
            if ($current_hook === $hook) {
                wp_enqueue_media();
            }
        });
    }

    // --- CRUD ---

    public static function create($data) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'bookflow_resources', [
            'title'       => sanitize_text_field($data['title']),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'capacity'    => absint($data['capacity'] ?? 0),
            'sort_order'  => absint($data['sort_order'] ?? 0),
            'status'      => sanitize_text_field($data['status'] ?? 'active'),
            'meta'        => isset($data['meta']) ? wp_json_encode($data['meta']) : null,
        ]);
        return $wpdb->insert_id;
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bookflow_resources WHERE id = %d",
            absint($id)
        ));
    }

    /**
     * Optional photo for a resource (staff member, room, vehicle, ...),
     * stored as an attachment ID in the generic `meta` JSON column so it
     * applies to any resource type, not just people.
     */
    public static function get_photo_url($resource) {
        if (empty($resource->meta)) {
            return '';
        }
        $meta = json_decode($resource->meta, true);
        $attachment_id = absint($meta['photo_id'] ?? 0);
        if (!$attachment_id) {
            return '';
        }
        $url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
        return $url ?: '';
    }

    /**
     * Optional portfolio/example photos for a resource (a barber's past
     * work, a room's other angles, a vehicle's interior, ...) — same
     * generic `meta` column, an array of attachment IDs this time.
     */
    public static function get_gallery_urls($resource) {
        if (empty($resource->meta)) {
            return [];
        }
        $meta = json_decode($resource->meta, true);
        $ids = array_filter(array_map('absint', (array) ($meta['gallery_ids'] ?? [])));
        $urls = [];
        foreach ($ids as $id) {
            $url = wp_get_attachment_image_url($id, 'thumbnail');
            if ($url) {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    public static function get_all($status = null) {
        global $wpdb;
        $sql = "SELECT * FROM {$wpdb->prefix}bookflow_resources";
        if ($status) {
            $sql = $wpdb->prepare($sql . " WHERE status = %s", $status);
        }
        $sql .= " ORDER BY sort_order ASC, title ASC";
        return $wpdb->get_results($sql);
    }

    public static function update($id, $data) {
        global $wpdb;
        $update = [];
        if (isset($data['title']))       $update['title'] = sanitize_text_field($data['title']);
        if (isset($data['description'])) $update['description'] = sanitize_textarea_field($data['description']);
        if (isset($data['capacity']))    $update['capacity'] = absint($data['capacity']);
        if (isset($data['sort_order']))  $update['sort_order'] = absint($data['sort_order']);
        if (isset($data['status']))      $update['status'] = sanitize_text_field($data['status']);
        if (isset($data['meta']))        $update['meta'] = wp_json_encode($data['meta']);

        return $wpdb->update($wpdb->prefix . 'bookflow_resources', $update, ['id' => absint($id)]);
    }

    public static function delete($id) {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'bookflow_product_resources', ['resource_id' => absint($id)], ['%d']);
        return $wpdb->delete($wpdb->prefix . 'bookflow_resources', ['id' => absint($id)], ['%d']);
    }

    // --- Product-Resource assignments ---

    public static function assign_to_product($product_id, $resource_id, $base_cost = 0) {
        global $wpdb;
        $wpdb->replace($wpdb->prefix . 'bookflow_product_resources', [
            'product_id'  => absint($product_id),
            'resource_id' => absint($resource_id),
            'base_cost'   => (float) $base_cost,
        ], ['%d', '%d', '%f']);
    }

    public static function unassign_from_product($product_id, $resource_id) {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'bookflow_product_resources', [
            'product_id'  => absint($product_id),
            'resource_id' => absint($resource_id),
        ], ['%d', '%d']);
    }

    public static function get_for_product($product_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, pr.base_cost
             FROM {$wpdb->prefix}bookflow_resources r
             INNER JOIN {$wpdb->prefix}bookflow_product_resources pr ON r.id = pr.resource_id
             WHERE pr.product_id = %d AND r.status = 'active'
             ORDER BY r.sort_order ASC",
            absint($product_id)
        ));
    }

    /**
     * Check resource availability for a slot
     */
    public static function is_resource_available($resource_id, $product_id, $date, $start_time) {
        $resource = self::get($resource_id);
        if (!$resource || $resource->status !== 'active') {
            return false;
        }

        $booked_persons = Bookflow_Booking::persons_for_slot($product_id, $date, $start_time, $resource_id);
        return $booked_persons < (int) $resource->capacity;
    }

    public static function remaining_capacity($resource_id, $product_id, $date, $start_time) {
        $resource = self::get($resource_id);
        if (!$resource) {
            return 0;
        }

        $booked = Bookflow_Booking::persons_for_slot($product_id, $date, $start_time, $resource_id);
        return max(0, (int) $resource->capacity - $booked);
    }

    /**
     * Get available resources for a slot
     */
    public static function get_available_for_slot($product_id, $date, $start_time) {
        $resources = self::get_for_product($product_id);
        $available = [];

        foreach ($resources as $resource) {
            if (self::is_resource_available($resource->id, $product_id, $date, $start_time)) {
                $available[] = $resource;
            }
        }

        return $available;
    }

    /**
     * Get resources with at least one open (structurally offered, non-full)
     * slot on the given date — used by the "choose your guide" wizard step,
     * which runs before a specific time is picked.
     */
    public static function get_available_for_date($product_id, $date, $schedule_id = null) {
        $resources = self::get_for_product($product_id);
        $available = [];

        foreach ($resources as $resource) {
            $slots = Bookflow_Availability::get_available_slots($product_id, $date, $resource->id, $schedule_id);
            $has_open_slot = false;
            foreach ($slots as $slot) {
                if (empty($slot['is_full'])) {
                    $has_open_slot = true;
                    break;
                }
            }
            if ($has_open_slot) {
                $available[] = $resource;
            }
        }

        return $available;
    }

    public function enqueue($hook) {
        if (strpos($hook, 'bookflow-resources') === false) {
            return;
        }
        $bundle_js = BOOKFLOW_PLUGIN_DIR . 'admin/dist/admin-resources.js';
        if (!file_exists($bundle_js)) {
            return;
        }
        wp_enqueue_media();
        $bundle_css = BOOKFLOW_PLUGIN_DIR . 'admin/dist/admin-resources.css';
        if (file_exists($bundle_css)) {
            wp_enqueue_style('bookflow-admin-resources', BOOKFLOW_PLUGIN_URL . 'admin/dist/admin-resources.css', [], BOOKFLOW_VERSION);
        }
        wp_enqueue_script('bookflow-admin-resources', BOOKFLOW_PLUGIN_URL . 'admin/dist/admin-resources.js', [], BOOKFLOW_VERSION, true);
        wp_localize_script('bookflow-admin-resources', 'bookflowAdminResources', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('bookflow_admin_nonce'),
            'i18n'    => [
                'title'         => Bookflow_I18n::t('admin.title'),
                'description'   => Bookflow_I18n::t('admin.description'),
                'capacity'      => Bookflow_I18n::t('admin.capacity'),
                'sortOrder'     => Bookflow_I18n::t('admin.sort_order'),
                'status'        => Bookflow_I18n::t('admin.status'),
                'active'        => Bookflow_I18n::t('admin.active'),
                'inactive'      => Bookflow_I18n::t('admin.inactive'),
                'photo'         => Bookflow_I18n::t('admin.photo'),
                'chooseImage'   => Bookflow_I18n::t('admin.choose_image'),
                'removeImage'   => Bookflow_I18n::t('admin.remove_image'),
                'addNew'        => Bookflow_I18n::t('admin.add_new'),
                'edit'          => Bookflow_I18n::t('admin.edit'),
                'delete'        => Bookflow_I18n::t('admin.delete'),
                'save'          => Bookflow_I18n::t('admin.save'),
                'cancel'        => Bookflow_I18n::t('admin.cancel_edit'),
                'confirmDelete' => Bookflow_I18n::t('admin.confirm_delete'),
                'noItems'       => Bookflow_I18n::t('admin.no_items'),
                'pageTitle'     => Bookflow_I18n::t('admin.resources'),
                'errorGeneric'  => Bookflow_I18n::t('calendar.error_generic'),
            ],
        ]);
    }

    // --- AJAX ---

    public function ajax_list() {
        check_ajax_referer('bookflow_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $rows = self::get_all();
        $result = array_map(function ($r) {
            $meta = $r->meta ? json_decode($r->meta, true) : [];
            return [
                'id'          => (int) $r->id,
                'title'       => $r->title,
                'description' => $r->description,
                'capacity'    => (int) $r->capacity,
                'sort_order'  => (int) $r->sort_order,
                'status'      => $r->status,
                'photo_id'    => (int) ($meta['photo_id'] ?? 0),
                'photo_id_url' => self::get_photo_url($r),
                'gallery_ids' => (array) ($meta['gallery_ids'] ?? []),
            ];
        }, $rows);

        wp_send_json_success(['items' => $result]);
    }

    public function ajax_save() {
        check_ajax_referer('bookflow_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $id = absint($_POST['id'] ?? 0);
        $data = [
            'title'       => sanitize_text_field($_POST['title'] ?? ''),
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'capacity'    => absint($_POST['capacity'] ?? 0),
            'sort_order'  => absint($_POST['sort_order'] ?? 0),
            'status'      => sanitize_text_field($_POST['status'] ?? 'active'),
        ];
        if (isset($_POST['photo_id']) || isset($_POST['gallery_ids'])) {
            // gallery_ids (the portfolio picker) isn't sent by every caller
            // of this endpoint — preserve whatever the resource already had
            // instead of silently wiping it when only photo_id is posted.
            $existing = $id ? self::get($id) : null;
            $existing_meta = $existing && $existing->meta ? json_decode($existing->meta, true) : [];
            $data['meta'] = [
                'photo_id'    => absint($_POST['photo_id'] ?? 0),
                'gallery_ids' => isset($_POST['gallery_ids'])
                    ? self::parse_gallery_ids($_POST['gallery_ids'])
                    : (array) ($existing_meta['gallery_ids'] ?? []),
            ];
        }

        if ($id) {
            self::update($id, $data);
        } else {
            $id = self::create($data);
        }

        wp_send_json_success(['id' => $id]);
    }

    /**
     * "12,45,90" -> [12, 45, 90] — how the gallery picker's hidden field
     * hands its selection back to PHP.
     */
    private static function parse_gallery_ids($raw) {
        return array_values(array_filter(array_map('absint', explode(',', (string) $raw))));
    }

    public function ajax_delete() {
        check_ajax_referer('bookflow_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $id = absint($_POST['id'] ?? 0);
        self::delete($id);
        wp_send_json_success();
    }

    /**
     * Render resources admin page
     */
    /**
     * Render resources admin page
     */
    public function render_page() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php Bookflow_I18n::te('admin.resources'); ?></h1>
            <hr class="wp-header-end">
            <div id="bookflow-admin-resources-root" style="margin-top:16px;">
                <?php if (!file_exists(BOOKFLOW_PLUGIN_DIR . 'admin/dist/admin-resources.js')) : ?>
                    <p><em>Run <code>npm run build</code> in <code>svelte-src/</code> to build this page.</em></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

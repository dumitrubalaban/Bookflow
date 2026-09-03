<?php
/**
 * Extras (cart-level upsells: souvenir bottle, tasting kit, transport, ...)
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Extras {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_submenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_bookflow_save_extra', [$this, 'ajax_save']);
        add_action('wp_ajax_bookflow_delete_extra', [$this, 'ajax_delete']);
        add_action('wp_ajax_bookflow_list_extras', [$this, 'ajax_list']);
    }

    public function enqueue($hook) {
        if (strpos($hook, 'bookflow-extras') === false) {
            return;
        }
        $bundle_js = BOOKFLOW_PLUGIN_DIR . 'admin/dist/admin-extras.js';
        if (!file_exists($bundle_js)) {
            return;
        }
        $bundle_css = BOOKFLOW_PLUGIN_DIR . 'admin/dist/admin-extras.css';
        if (file_exists($bundle_css)) {
            wp_enqueue_style('bookflow-admin-extras', BOOKFLOW_PLUGIN_URL . 'admin/dist/admin-extras.css', [], BOOKFLOW_VERSION);
        }
        wp_enqueue_script('bookflow-admin-extras', BOOKFLOW_PLUGIN_URL . 'admin/dist/admin-extras.js', [], BOOKFLOW_VERSION, true);
        wp_localize_script('bookflow-admin-extras', 'bookflowAdminExtras', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('bookflow_admin_nonce'),
            'i18n'    => [
                'title'         => Bookflow_I18n::t('admin.title'),
                'description'   => Bookflow_I18n::t('admin.description'),
                'price'         => Bookflow_I18n::t('admin.price'),
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
        $result = array_map(function ($e) {
            return [
                'id'          => (int) $e->id,
                'title'       => $e->title,
                'description' => $e->description,
                'price'       => (float) $e->price,
                'sort_order'  => (int) $e->sort_order,
                'status'      => $e->status,
            ];
        }, $rows);
        wp_send_json_success(['items' => $result]);
    }

    public function add_submenu() {
        add_submenu_page(
            'bookflow-bookings',
            Bookflow_I18n::t('admin.extras'),
            Bookflow_I18n::t('admin.extras'),
            'manage_woocommerce',
            'bookflow-extras',
            [$this, 'render_page']
        );
    }

    // --- CRUD ---

    public static function create($data) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'bookflow_extras', [
            'title'       => sanitize_text_field($data['title']),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'price'       => (float) ($data['price'] ?? 0),
            'sort_order'  => absint($data['sort_order'] ?? 0),
            'status'      => sanitize_text_field($data['status'] ?? 'active'),
        ]);
        return $wpdb->insert_id;
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bookflow_extras WHERE id = %d",
            absint($id)
        ));
    }

    public static function get_all($status = null) {
        global $wpdb;
        $sql = "SELECT * FROM {$wpdb->prefix}bookflow_extras";
        if ($status) {
            $sql = $wpdb->prepare($sql . " WHERE status = %s", $status);
        }
        $sql .= " ORDER BY sort_order ASC, title ASC";
        return $wpdb->get_results($sql);
    }

    /**
     * Fetch multiple extras by ID, preserving only active ones — used to
     * price a cart item's selected extras server-side (never trust the
     * client-submitted price).
     */
    public static function get_many($ids) {
        global $wpdb;
        $ids = array_values(array_filter(array_map('absint', (array) $ids)));
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bookflow_extras WHERE status = 'active' AND id IN ($placeholders)",
            ...$ids
        ));
    }

    public static function update($id, $data) {
        global $wpdb;
        $update = [];
        if (isset($data['title']))       $update['title'] = sanitize_text_field($data['title']);
        if (isset($data['description'])) $update['description'] = sanitize_textarea_field($data['description']);
        if (isset($data['price']))       $update['price'] = (float) $data['price'];
        if (isset($data['sort_order']))  $update['sort_order'] = absint($data['sort_order']);
        if (isset($data['status']))      $update['status'] = sanitize_text_field($data['status']);

        return $wpdb->update($wpdb->prefix . 'bookflow_extras', $update, ['id' => absint($id)]);
    }

    public static function delete($id) {
        global $wpdb;
        return $wpdb->delete($wpdb->prefix . 'bookflow_extras', ['id' => absint($id)], ['%d']);
    }

    // --- AJAX ---

    public function ajax_save() {
        check_ajax_referer('bookflow_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $id = absint($_POST['id'] ?? 0);
        $data = [
            'title'       => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
            'description' => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
            'price'       => (float) sanitize_text_field(wp_unslash($_POST['price'] ?? '')),
            'sort_order'  => absint($_POST['sort_order'] ?? 0),
            'status'      => sanitize_text_field(wp_unslash($_POST['status'] ?? 'active')),
        ];

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
     * Render extras admin page
     */
    /**
     * Render extras admin page
     */
    public function render_page() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php Bookflow_I18n::te('admin.extras'); ?></h1>
            <hr class="wp-header-end">
            <div id="bookflow-admin-extras-root" style="margin-top:16px;">
                <?php if (!file_exists(BOOKFLOW_PLUGIN_DIR . 'admin/dist/admin-extras.js')) : ?>
                    <p><em>Run <code>npm run build</code> in <code>svelte-src/</code> to build this page.</em></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

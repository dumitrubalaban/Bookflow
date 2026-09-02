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
        add_action('wp_ajax_bookflow_save_extra', [$this, 'ajax_save']);
        add_action('wp_ajax_bookflow_delete_extra', [$this, 'ajax_delete']);
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
            'title'       => sanitize_text_field($_POST['title'] ?? ''),
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'price'       => (float) ($_POST['price'] ?? 0),
            'sort_order'  => absint($_POST['sort_order'] ?? 0),
            'status'      => sanitize_text_field($_POST['status'] ?? 'active'),
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
    public function render_page() {
        if (isset($_POST['bookflow_save_extra'], $_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'bookflow_save_extra')) {
            $data = [
                'title'       => sanitize_text_field($_POST['title'] ?? ''),
                'description' => sanitize_textarea_field($_POST['description'] ?? ''),
                'price'       => (float) ($_POST['price'] ?? 0),
                'sort_order'  => absint($_POST['sort_order'] ?? 0),
                'status'      => sanitize_text_field($_POST['status'] ?? 'active'),
            ];
            $id = absint($_POST['extra_id'] ?? 0);
            if ($id) {
                self::update($id, $data);
            } else {
                self::create($data);
            }
            echo '<div class="notice notice-success"><p>' . esc_html(Bookflow_I18n::t('admin.extra_saved')) . '</p></div>';
        }

        if (isset($_GET['delete'], $_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'bookflow_delete_extra')) {
            self::delete(absint($_GET['delete']));
            echo '<div class="notice notice-success"><p>' . esc_html(Bookflow_I18n::t('admin.extra_deleted')) . '</p></div>';
        }

        $extras = self::get_all();
        $editing = null;
        if (isset($_GET['edit'])) {
            $editing = self::get(absint($_GET['edit']));
        }

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php Bookflow_I18n::te('admin.extras'); ?></h1>
            <hr class="wp-header-end">
            <p class="description"><?php Bookflow_I18n::te('admin.extras_desc'); ?></p>

            <div id="col-container" class="wp-clearfix">
                <div id="col-left">
                    <div class="col-wrap">
                        <div class="form-wrap">
                            <h2><?php echo $editing ? esc_html(Bookflow_I18n::t('admin.edit_extra')) : esc_html(Bookflow_I18n::t('admin.add_extra')); ?></h2>
                            <form method="post">
                                <?php wp_nonce_field('bookflow_save_extra'); ?>
                                <input type="hidden" name="extra_id" value="<?php echo esc_attr($editing->id ?? 0); ?>">

                                <div class="form-field form-required">
                                    <label for="bookflow-extra-title"><?php Bookflow_I18n::te('admin.title'); ?></label>
                                    <input type="text" name="title" id="bookflow-extra-title" value="<?php echo esc_attr($editing->title ?? ''); ?>" size="40" required>
                                </div>

                                <div class="form-field">
                                    <label for="bookflow-extra-description"><?php Bookflow_I18n::te('admin.description'); ?></label>
                                    <textarea name="description" id="bookflow-extra-description" rows="3" cols="40"><?php echo esc_textarea($editing->description ?? ''); ?></textarea>
                                </div>

                                <div class="form-field">
                                    <label for="bookflow-extra-price"><?php Bookflow_I18n::te('admin.price'); ?></label>
                                    <input type="number" step="0.01" min="0" name="price" id="bookflow-extra-price" value="<?php echo esc_attr($editing->price ?? 0); ?>">
                                </div>

                                <div class="form-field">
                                    <label for="bookflow-extra-sort"><?php Bookflow_I18n::te('admin.sort_order'); ?></label>
                                    <input type="number" name="sort_order" id="bookflow-extra-sort" value="<?php echo esc_attr($editing->sort_order ?? 0); ?>" min="0">
                                </div>

                                <div class="form-field">
                                    <label for="bookflow-extra-status"><?php Bookflow_I18n::te('admin.status'); ?></label>
                                    <select name="status" id="bookflow-extra-status">
                                        <option value="active" <?php selected($editing->status ?? 'active', 'active'); ?>><?php Bookflow_I18n::te('admin.active'); ?></option>
                                        <option value="inactive" <?php selected($editing->status ?? '', 'inactive'); ?>><?php Bookflow_I18n::te('admin.inactive'); ?></option>
                                    </select>
                                </div>

                                <p class="submit">
                                    <button type="submit" name="bookflow_save_extra" class="button button-primary"><?php Bookflow_I18n::te('admin.save_extra'); ?></button>
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
                                    <th><?php Bookflow_I18n::te('admin.price'); ?></th>
                                    <th><?php Bookflow_I18n::te('admin.status'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($extras)) : ?>
                                    <tr><td colspan="4"><?php Bookflow_I18n::te('admin.no_extras'); ?></td></tr>
                                <?php else : foreach ($extras as $e) : ?>
                                    <tr>
                                        <td><?php echo esc_html($e->id); ?></td>
                                        <td>
                                            <strong><a href="<?php echo esc_url(add_query_arg('edit', $e->id)); ?>"><?php echo esc_html($e->title); ?></a></strong>
                                            <div class="row-actions">
                                                <span class="edit"><a href="<?php echo esc_url(add_query_arg('edit', $e->id)); ?>"><?php Bookflow_I18n::te('admin.edit'); ?></a> | </span>
                                                <span class="delete"><a href="<?php echo esc_url(wp_nonce_url(add_query_arg('delete', $e->id), 'bookflow_delete_extra')); ?>" class="submitdelete" onclick="return confirm('<?php echo esc_attr(Bookflow_I18n::t('admin.delete_extra_confirm')); ?>')"><?php Bookflow_I18n::te('admin.delete'); ?></a></span>
                                            </div>
                                        </td>
                                        <td><?php echo wp_kses_post(wc_price($e->price)); ?></td>
                                        <td><?php echo esc_html(ucfirst($e->status)); ?></td>
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

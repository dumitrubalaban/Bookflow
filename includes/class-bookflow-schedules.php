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
        add_action('wp_ajax_bookflow_save_schedule', [$this, 'ajax_save']);
        add_action('wp_ajax_bookflow_delete_schedule', [$this, 'ajax_delete']);
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

        $result = $wpdb->insert($wpdb->prefix . 'bookflow_product_schedules', [
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
        return $wpdb->get_row($wpdb->prepare(
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
        $sql = "SELECT * FROM {$wpdb->prefix}bookflow_product_schedules";
        if ($product_id) {
            $sql = $wpdb->prepare($sql . " WHERE product_id = %d", absint($product_id));
        }
        $sql .= " ORDER BY product_id ASC, sort_order ASC, id ASC";
        return $wpdb->get_results($sql);
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
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}bookflow_product_schedules
                 WHERE product_id = %d AND option_group = %s AND status = 'active'
                 ORDER BY sort_order ASC, id ASC",
                absint($product_id),
                sanitize_text_field($option_group)
            ));
        }
        return $wpdb->get_results($wpdb->prepare(
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

        return $wpdb->update($wpdb->prefix . 'bookflow_product_schedules', $update, ['id' => absint($id)]);
    }

    /**
     * Delete a schedule.
     *
     * @param int $id Schedule ID.
     * @return int|false Number of rows deleted, or false on error.
     */
    public static function delete($id) {
        global $wpdb;
        return $wpdb->delete($wpdb->prefix . 'bookflow_product_schedules', ['id' => absint($id)], ['%d']);
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
        return $wpdb->get_col($wpdb->prepare(
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
        return (bool) $wpdb->get_var($wpdb->prepare(
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
            ? array_map('sanitize_text_field', $_POST['available_days'])
            : [];

        // Parse time_slots (textarea, one per line)
        $time_slots_raw = sanitize_textarea_field($_POST['time_slots'] ?? '');
        $time_slots = array_values(array_filter(array_map('trim', explode("\n", $time_slots_raw))));

        $data = [
            'product_id'            => absint($_POST['product_id'] ?? 0),
            'option_group'          => sanitize_text_field($_POST['option_group'] ?? 'language'),
            'option_label'          => sanitize_text_field($_POST['option_label'] ?? ''),
            'option_value'          => sanitize_text_field($_POST['option_value'] ?? ''),
            'available_days'        => $available_days,
            'time_slots'            => $time_slots,
            'max_persons'           => absint($_POST['max_persons'] ?? 0),
            'max_bookings_per_slot' => absint($_POST['max_bookings_per_slot'] ?? 0),
            'price_modifier'        => floatval($_POST['price_modifier'] ?? 0),
            'sort_order'            => absint($_POST['sort_order'] ?? 0),
            'status'                => sanitize_text_field($_POST['status'] ?? 'active'),
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
        // Handle form POST submission
        if (isset($_POST['bookflow_save_schedule'], $_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'bookflow_save_schedule')) {
            $available_days = isset($_POST['available_days']) && is_array($_POST['available_days'])
                ? array_map('sanitize_text_field', $_POST['available_days'])
                : [];

            $time_slots_raw = sanitize_textarea_field($_POST['time_slots'] ?? '');
            $time_slots = array_values(array_filter(array_map('trim', explode("\n", $time_slots_raw))));

            $data = [
                'product_id'            => absint($_POST['product_id'] ?? 0),
                'option_group'          => sanitize_text_field($_POST['option_group'] ?? 'language'),
                'option_label'          => sanitize_text_field($_POST['option_label'] ?? ''),
                'option_value'          => sanitize_text_field($_POST['option_value'] ?? ''),
                'available_days'        => $available_days,
                'time_slots'            => $time_slots,
                'max_persons'           => absint($_POST['max_persons'] ?? 0),
                'max_bookings_per_slot' => absint($_POST['max_bookings_per_slot'] ?? 0),
                'price_modifier'        => floatval($_POST['price_modifier'] ?? 0),
                'sort_order'            => absint($_POST['sort_order'] ?? 0),
                'status'                => sanitize_text_field($_POST['status'] ?? 'active'),
            ];

            $id = absint($_POST['schedule_id'] ?? 0);
            if ($id) {
                self::update($id, $data);
            } else {
                self::create($data);
            }
            echo '<div class="notice notice-success"><p>' . esc_html(Bookflow_I18n::t('admin.schedule_saved')) . '</p></div>';
        }

        // Handle GET delete
        if (isset($_GET['delete'], $_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'bookflow_delete_schedule')) {
            self::delete(absint($_GET['delete']));
            echo '<div class="notice notice-success"><p>' . esc_html(Bookflow_I18n::t('admin.schedule_deleted')) . '</p></div>';
        }

        $schedules = self::get_all();
        $editing   = null;
        if (isset($_GET['edit'])) {
            $editing = self::get(absint($_GET['edit']));
        }

        // Group schedules by product for display
        $grouped = [];
        foreach ($schedules as $s) {
            $grouped[$s->product_id][] = $s;
        }

        // Get booking products for the dropdown
        $products = [];
        if (function_exists('wc_get_products')) {
            $products = wc_get_products([
                'limit'  => -1,
                'status' => 'publish',
                'type'   => ['simple', 'booking'],
                'orderby' => 'title',
                'order'   => 'ASC',
            ]);
        }

        $weekdays = [
            'monday'    => Bookflow_I18n::t('weekday.monday'),
            'tuesday'   => Bookflow_I18n::t('weekday.tuesday'),
            'wednesday' => Bookflow_I18n::t('weekday.wednesday'),
            'thursday'  => Bookflow_I18n::t('weekday.thursday'),
            'friday'    => Bookflow_I18n::t('weekday.friday'),
            'saturday'  => Bookflow_I18n::t('weekday.saturday'),
            'sunday'    => Bookflow_I18n::t('weekday.sunday'),
        ];

        // Decode editing values
        $editing_days  = [];
        $editing_slots = '';
        if ($editing) {
            $decoded_days = json_decode($editing->available_days, true);
            $editing_days = is_array($decoded_days) ? $decoded_days : [];

            $decoded_slots = json_decode($editing->time_slots, true);
            $editing_slots = is_array($decoded_slots) ? implode("\n", $decoded_slots) : '';
        }

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php Bookflow_I18n::te('admin.schedules'); ?></h1>
            <hr class="wp-header-end">
            <p class="description"><?php Bookflow_I18n::te('admin.schedules_desc'); ?></p>

            <div id="col-container" class="wp-clearfix">
                <div id="col-left">
                    <div class="col-wrap">
                        <div class="form-wrap">
                            <h2><?php echo $editing ? esc_html(Bookflow_I18n::t('admin.edit_schedule')) : esc_html(Bookflow_I18n::t('admin.add_schedule')); ?></h2>
                            <form method="post">
                                <?php wp_nonce_field('bookflow_save_schedule'); ?>
                                <input type="hidden" name="schedule_id" value="<?php echo esc_attr($editing->id ?? 0); ?>">

                                <div class="form-field form-required">
                                    <label for="bookflow-sch-product"><?php Bookflow_I18n::te('admin.product'); ?></label>
                                    <select name="product_id" id="bookflow-sch-product" required>
                                        <option value=""><?php Bookflow_I18n::te('admin.select_product'); ?></option>
                                        <?php foreach ($products as $prod) : ?>
                                            <option value="<?php echo esc_attr($prod->get_id()); ?>" <?php selected($editing->product_id ?? '', $prod->get_id()); ?>>
                                                <?php echo esc_html($prod->get_name()); ?> (#<?php echo esc_html($prod->get_id()); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-field">
                                    <label for="bookflow-sch-group"><?php Bookflow_I18n::te('admin.option_group'); ?></label>
                                    <input type="text" name="option_group" id="bookflow-sch-group" value="<?php echo esc_attr($editing->option_group ?? 'language'); ?>" placeholder="language">
                                    <p><?php Bookflow_I18n::te('admin.option_group_placeholder'); ?></p>
                                </div>

                                <div class="form-field form-required">
                                    <label for="bookflow-sch-label"><?php Bookflow_I18n::te('admin.option_label'); ?></label>
                                    <input type="text" name="option_label" id="bookflow-sch-label" value="<?php echo esc_attr($editing->option_label ?? ''); ?>" placeholder="Română" required>
                                    <p><?php Bookflow_I18n::te('admin.option_label_placeholder'); ?></p>
                                </div>

                                <div class="form-field form-required">
                                    <label for="bookflow-sch-value"><?php Bookflow_I18n::te('admin.option_value'); ?></label>
                                    <input type="text" name="option_value" id="bookflow-sch-value" value="<?php echo esc_attr($editing->option_value ?? ''); ?>" placeholder="ro" required>
                                    <p><?php Bookflow_I18n::te('admin.option_value_placeholder'); ?></p>
                                </div>

                                <div class="form-field">
                                    <label><?php Bookflow_I18n::te('admin.available_days'); ?></label>
                                    <?php foreach ($weekdays as $day_key => $day_label) : ?>
                                        <label style="display:inline-block; margin-right:10px; font-weight:normal;">
                                            <input type="checkbox" name="available_days[]" value="<?php echo esc_attr($day_key); ?>" <?php checked(in_array($day_key, $editing_days, true)); ?>>
                                            <?php echo esc_html($day_label); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>

                                <div class="form-field">
                                    <label for="bookflow-sch-slots"><?php Bookflow_I18n::te('admin.time_slots'); ?></label>
                                    <textarea name="time_slots" id="bookflow-sch-slots" rows="4" cols="40" placeholder="10:00&#10;13:00&#10;16:00"><?php echo esc_textarea($editing_slots); ?></textarea>
                                    <p><?php Bookflow_I18n::te('admin.time_slots_placeholder'); ?></p>
                                </div>

                                <div class="form-field">
                                    <label for="bookflow-sch-maxp"><?php Bookflow_I18n::te('admin.max_persons'); ?></label>
                                    <input type="number" name="max_persons" id="bookflow-sch-maxp" value="<?php echo esc_attr($editing->max_persons ?? 0); ?>" min="0">
                                    <p><?php Bookflow_I18n::te('admin.max_persons_note'); ?></p>
                                </div>

                                <div class="form-field">
                                    <label for="bookflow-sch-maxslot"><?php Bookflow_I18n::te('admin.max_bookings_per_slot'); ?></label>
                                    <input type="number" name="max_bookings_per_slot" id="bookflow-sch-maxslot" value="<?php echo esc_attr($editing->max_bookings_per_slot ?? 0); ?>" min="0">
                                    <p><?php Bookflow_I18n::te('admin.max_persons_note'); ?></p>
                                </div>

                                <div class="form-field">
                                    <label for="bookflow-sch-price"><?php Bookflow_I18n::te('admin.price_modifier'); ?></label>
                                    <input type="number" name="price_modifier" id="bookflow-sch-price" value="<?php echo esc_attr($editing->price_modifier ?? '0.00'); ?>" step="0.01">
                                    <p><?php Bookflow_I18n::te('admin.price_modifier_note'); ?></p>
                                </div>

                                <div class="form-field">
                                    <label for="bookflow-sch-sort"><?php Bookflow_I18n::te('admin.sort_order'); ?></label>
                                    <input type="number" name="sort_order" id="bookflow-sch-sort" value="<?php echo esc_attr($editing->sort_order ?? 0); ?>" min="0">
                                </div>

                                <div class="form-field">
                                    <label for="bookflow-sch-status"><?php Bookflow_I18n::te('admin.status'); ?></label>
                                    <select name="status" id="bookflow-sch-status">
                                        <option value="active" <?php selected($editing->status ?? 'active', 'active'); ?>><?php Bookflow_I18n::te('admin.active'); ?></option>
                                        <option value="inactive" <?php selected($editing->status ?? '', 'inactive'); ?>><?php Bookflow_I18n::te('admin.inactive'); ?></option>
                                    </select>
                                </div>

                                <p class="submit">
                                    <button type="submit" name="bookflow_save_schedule" class="button button-primary"><?php Bookflow_I18n::te('admin.save_schedule'); ?></button>
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
                        <?php if (empty($grouped)) : ?>
                            <p><?php Bookflow_I18n::te('admin.no_schedules'); ?></p>
                        <?php else : foreach ($grouped as $pid => $product_schedules) :
                            $product_title = $pid;
                            if (function_exists('wc_get_product')) {
                                $p = wc_get_product($pid);
                                if ($p) {
                                    $product_title = $p->get_name() . ' (#' . $pid . ')';
                                }
                            }
                        ?>
                            <h2 class="title"><?php echo esc_html($product_title); ?></h2>
                            <table class="wp-list-table widefat fixed striped" style="margin-bottom:20px;">
                                <thead>
                                    <tr>
                                        <th style="width:40px;">ID</th>
                                        <th><?php Bookflow_I18n::te('admin.group'); ?></th>
                                        <th><?php Bookflow_I18n::te('admin.label'); ?></th>
                                        <th><?php Bookflow_I18n::te('admin.value'); ?></th>
                                        <th><?php Bookflow_I18n::te('admin.days'); ?></th>
                                        <th><?php Bookflow_I18n::te('admin.slots'); ?></th>
                                        <th><?php Bookflow_I18n::te('admin.status'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($product_schedules as $s) :
                                        $days_arr  = json_decode($s->available_days, true);
                                        $slots_arr = json_decode($s->time_slots, true);
                                        $days_str  = is_array($days_arr) ? implode(', ', array_map('ucfirst', $days_arr)) : '&mdash;';
                                        $slots_str = is_array($slots_arr) ? implode(', ', $slots_arr) : '&mdash;';
                                    ?>
                                        <tr>
                                            <td><?php echo esc_html($s->id); ?></td>
                                            <td><?php echo esc_html($s->option_group); ?></td>
                                            <td>
                                                <strong><a href="<?php echo esc_url(add_query_arg('edit', $s->id)); ?>"><?php echo esc_html($s->option_label); ?></a></strong>
                                                <div class="row-actions">
                                                    <span class="edit"><a href="<?php echo esc_url(add_query_arg('edit', $s->id)); ?>"><?php Bookflow_I18n::te('admin.edit'); ?></a> | </span>
                                                    <span class="delete"><a href="<?php echo esc_url(wp_nonce_url(add_query_arg('delete', $s->id), 'bookflow_delete_schedule')); ?>" class="submitdelete" onclick="return confirm('<?php echo esc_attr(Bookflow_I18n::t('admin.delete_schedule_confirm')); ?>')"><?php Bookflow_I18n::te('admin.delete'); ?></a></span>
                                                </div>
                                            </td>
                                            <td><?php echo esc_html($s->option_value); ?></td>
                                            <td><?php echo esc_html($days_str); ?></td>
                                            <td><?php echo esc_html($slots_str); ?></td>
                                            <td><?php echo esc_html(ucfirst($s->status)); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

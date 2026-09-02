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
        add_action('wp_ajax_bookflow_save_resource', [$this, 'ajax_save']);
        add_action('wp_ajax_bookflow_delete_resource', [$this, 'ajax_delete']);
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
            'capacity'    => absint($_POST['capacity'] ?? 0),
            'sort_order'  => absint($_POST['sort_order'] ?? 0),
            'status'      => sanitize_text_field($_POST['status'] ?? 'active'),
        ];
        if (isset($_POST['photo_id']) || isset($_POST['gallery_ids'])) {
            $data['meta'] = [
                'photo_id'    => absint($_POST['photo_id'] ?? 0),
                'gallery_ids' => self::parse_gallery_ids($_POST['gallery_ids'] ?? ''),
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
    public function render_page() {
        // Handle form submission
        if (isset($_POST['bookflow_save_resource'], $_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'bookflow_save_resource')) {
            $data = [
                'title'       => sanitize_text_field($_POST['title'] ?? ''),
                'description' => sanitize_textarea_field($_POST['description'] ?? ''),
                'capacity'    => absint($_POST['capacity'] ?? 0),
                'sort_order'  => absint($_POST['sort_order'] ?? 0),
                'status'      => sanitize_text_field($_POST['status'] ?? 'active'),
                'meta'        => [
                    'photo_id'    => absint($_POST['photo_id'] ?? 0),
                    'gallery_ids' => self::parse_gallery_ids($_POST['gallery_ids'] ?? ''),
                ],
            ];
            $id = absint($_POST['resource_id'] ?? 0);
            if ($id) {
                self::update($id, $data);
            } else {
                self::create($data);
            }
            echo '<div class="notice notice-success"><p>' . esc_html(Bookflow_I18n::t('admin.resource_saved')) . '</p></div>';
        }

        // Handle delete
        if (isset($_GET['delete'], $_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'bookflow_delete_resource')) {
            self::delete(absint($_GET['delete']));
            echo '<div class="notice notice-success"><p>' . esc_html(Bookflow_I18n::t('admin.resource_deleted')) . '</p></div>';
        }

        $resources = self::get_all();
        $editing = null;
        if (isset($_GET['edit'])) {
            $editing = self::get(absint($_GET['edit']));
        }

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php Bookflow_I18n::te('admin.resources'); ?></h1>
            <hr class="wp-header-end">
            <p class="description"><?php Bookflow_I18n::te('admin.resources_desc'); ?></p>

            <div id="col-container" class="wp-clearfix">
                <div id="col-left">
                    <div class="col-wrap">
                        <div class="form-wrap">
                            <h2><?php echo $editing ? esc_html(Bookflow_I18n::t('admin.edit_resource')) : esc_html(Bookflow_I18n::t('admin.add_resource')); ?></h2>
                            <form method="post">
                                <?php wp_nonce_field('bookflow_save_resource'); ?>
                                <input type="hidden" name="resource_id" value="<?php echo esc_attr($editing->id ?? 0); ?>">

                                <div class="form-field form-required">
                                    <label for="bookflow-res-title"><?php Bookflow_I18n::te('admin.title'); ?></label>
                                    <input type="text" name="title" id="bookflow-res-title" value="<?php echo esc_attr($editing->title ?? ''); ?>" size="40" required>
                                </div>

                                <div class="form-field">
                                    <label for="bookflow-res-description"><?php Bookflow_I18n::te('admin.description'); ?></label>
                                    <textarea name="description" id="bookflow-res-description" rows="3" cols="40"><?php echo esc_textarea($editing->description ?? ''); ?></textarea>
                                </div>

                                <?php $photo_id = 0; $photo_url = ''; if ($editing) {
                                    $meta = $editing->meta ? json_decode($editing->meta, true) : [];
                                    $photo_id = absint($meta['photo_id'] ?? 0);
                                    $photo_url = self::get_photo_url($editing);
                                } ?>
                                <div class="form-field">
                                    <label><?php Bookflow_I18n::te('admin.photo'); ?></label>
                                    <div>
                                        <img id="bookflow-res-photo-preview" src="<?php echo esc_url($photo_url); ?>" style="max-width:100px; max-height:100px; display:<?php echo $photo_url ? 'block' : 'none'; ?>; margin-bottom:8px; border-radius:4px;">
                                        <input type="hidden" name="photo_id" id="bookflow-res-photo-id" value="<?php echo esc_attr($photo_id); ?>">
                                        <button type="button" class="button" id="bookflow-res-photo-select"><?php Bookflow_I18n::te('admin.select_image'); ?></button>
                                        <button type="button" class="button" id="bookflow-res-photo-remove" style="<?php echo $photo_url ? '' : 'display:none;'; ?>"><?php Bookflow_I18n::te('admin.remove'); ?></button>
                                    </div>
                                </div>

                                <?php
                                $gallery_ids = $editing ? array_filter(array_map('absint', (array) ($meta['gallery_ids'] ?? []))) : [];
                                $gallery_urls = $editing ? self::get_gallery_urls($editing) : [];
                                ?>
                                <div class="form-field">
                                    <label><?php Bookflow_I18n::te('admin.portfolio_photos'); ?></label>
                                    <p class="description" style="margin-top:0;"><?php Bookflow_I18n::te('admin.portfolio_photos_desc'); ?></p>
                                    <div id="bookflow-res-gallery-preview" style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:8px;">
                                        <?php foreach ($gallery_urls as $i => $url) : ?>
                                            <div class="bookflow-gallery-thumb" data-id="<?php echo esc_attr($gallery_ids[$i] ?? ''); ?>" style="position:relative;">
                                                <img src="<?php echo esc_url($url); ?>" style="width:60px; height:60px; object-fit:cover; border-radius:4px;">
                                                <a href="#" class="bookflow-gallery-remove" style="position:absolute; top:-6px; right:-6px; background:#d63638; color:#fff; border-radius:50%; width:18px; height:18px; line-height:18px; text-align:center; font-size:12px; text-decoration:none;">&times;</a>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" name="gallery_ids" id="bookflow-res-gallery-ids" value="<?php echo esc_attr(implode(',', $gallery_ids)); ?>">
                                    <button type="button" class="button" id="bookflow-res-gallery-select"><?php Bookflow_I18n::te('admin.add_photos'); ?></button>
                                </div>

                                <div class="form-field">
                                    <label for="bookflow-res-capacity"><?php Bookflow_I18n::te('admin.capacity'); ?></label>
                                    <input type="number" name="capacity" id="bookflow-res-capacity" value="<?php echo esc_attr($editing->capacity ?? 0); ?>" min="0">
                                </div>

                                <div class="form-field">
                                    <label for="bookflow-res-sort"><?php Bookflow_I18n::te('admin.sort_order'); ?></label>
                                    <input type="number" name="sort_order" id="bookflow-res-sort" value="<?php echo esc_attr($editing->sort_order ?? 0); ?>" min="0">
                                </div>

                                <div class="form-field">
                                    <label for="bookflow-res-status"><?php Bookflow_I18n::te('admin.status'); ?></label>
                                    <select name="status" id="bookflow-res-status">
                                        <option value="active" <?php selected($editing->status ?? 'active', 'active'); ?>><?php Bookflow_I18n::te('admin.active'); ?></option>
                                        <option value="inactive" <?php selected($editing->status ?? '', 'inactive'); ?>><?php Bookflow_I18n::te('admin.inactive'); ?></option>
                                    </select>
                                </div>

                                <p class="submit">
                                    <button type="submit" name="bookflow_save_resource" class="button button-primary"><?php Bookflow_I18n::te('admin.save_resource'); ?></button>
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
                                    <th><?php Bookflow_I18n::te('admin.capacity'); ?></th>
                                    <th><?php Bookflow_I18n::te('admin.status'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($resources)) : ?>
                                    <tr><td colspan="4"><?php Bookflow_I18n::te('admin.no_resources'); ?></td></tr>
                                <?php else : foreach ($resources as $r) : ?>
                                    <tr>
                                        <td><?php echo esc_html($r->id); ?></td>
                                        <td>
                                            <strong><a href="<?php echo esc_url(add_query_arg('edit', $r->id)); ?>"><?php echo esc_html($r->title); ?></a></strong>
                                            <div class="row-actions">
                                                <span class="edit"><a href="<?php echo esc_url(add_query_arg('edit', $r->id)); ?>"><?php Bookflow_I18n::te('admin.edit'); ?></a> | </span>
                                                <span class="delete"><a href="<?php echo esc_url(wp_nonce_url(add_query_arg('delete', $r->id), 'bookflow_delete_resource')); ?>" class="submitdelete" onclick="return confirm('<?php echo esc_attr(Bookflow_I18n::t('admin.delete_resource_confirm')); ?>')"><?php Bookflow_I18n::te('admin.delete'); ?></a></span>
                                            </div>
                                        </td>
                                        <td><?php echo esc_html($r->capacity ?: '&mdash;'); ?></td>
                                        <td><?php echo esc_html(ucfirst($r->status)); ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <script>
        (function () {
            var frame;
            document.getElementById('bookflow-res-photo-select').addEventListener('click', function (e) {
                e.preventDefault();
                if (frame) { frame.open(); return; }
                frame = wp.media({
                    title: <?php echo wp_json_encode(Bookflow_I18n::t('admin.select_a_photo')); ?>,
                    button: { text: <?php echo wp_json_encode(Bookflow_I18n::t('admin.use_this_photo')); ?> },
                    library: { type: 'image' },
                    multiple: false,
                });
                frame.on('select', function () {
                    var attachment = frame.state().get('selection').first().toJSON();
                    document.getElementById('bookflow-res-photo-id').value = attachment.id;
                    var preview = document.getElementById('bookflow-res-photo-preview');
                    preview.src = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;
                    preview.style.display = 'block';
                    document.getElementById('bookflow-res-photo-remove').style.display = 'inline-block';
                });
                frame.open();
            });
            document.getElementById('bookflow-res-photo-remove').addEventListener('click', function (e) {
                e.preventDefault();
                document.getElementById('bookflow-res-photo-id').value = 0;
                var preview = document.getElementById('bookflow-res-photo-preview');
                preview.style.display = 'none';
                preview.src = '';
                this.style.display = 'none';
            });

            var galleryFrame;
            var galleryInput = document.getElementById('bookflow-res-gallery-ids');
            var galleryPreview = document.getElementById('bookflow-res-gallery-preview');

            function galleryIds() {
                return galleryInput.value ? galleryInput.value.split(',').filter(Boolean) : [];
            }

            function addGalleryThumb(id, url) {
                var div = document.createElement('div');
                div.className = 'bookflow-gallery-thumb';
                div.dataset.id = id;
                div.style.position = 'relative';
                div.innerHTML = '<img src="' + url + '" style="width:60px; height:60px; object-fit:cover; border-radius:4px;">' +
                    '<a href="#" class="bookflow-gallery-remove" style="position:absolute; top:-6px; right:-6px; background:#d63638; color:#fff; border-radius:50%; width:18px; height:18px; line-height:18px; text-align:center; font-size:12px; text-decoration:none;">&times;</a>';
                galleryPreview.appendChild(div);
            }

            document.getElementById('bookflow-res-gallery-select').addEventListener('click', function (e) {
                e.preventDefault();
                galleryFrame = wp.media({
                    title: <?php echo wp_json_encode(Bookflow_I18n::t('admin.add_portfolio_photos')); ?>,
                    button: { text: <?php echo wp_json_encode(Bookflow_I18n::t('admin.add_selected')); ?> },
                    library: { type: 'image' },
                    multiple: true,
                });
                galleryFrame.on('select', function () {
                    var selection = galleryFrame.state().get('selection').toJSON();
                    var ids = galleryIds();
                    selection.forEach(function (attachment) {
                        var id = String(attachment.id);
                        if (ids.indexOf(id) !== -1) return;
                        ids.push(id);
                        var url = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;
                        addGalleryThumb(id, url);
                    });
                    galleryInput.value = ids.join(',');
                });
                galleryFrame.open();
            });

            galleryPreview.addEventListener('click', function (e) {
                if (!e.target.classList.contains('bookflow-gallery-remove')) return;
                e.preventDefault();
                var thumb = e.target.closest('.bookflow-gallery-thumb');
                var id = thumb.dataset.id;
                galleryInput.value = galleryIds().filter(function (x) { return x !== id; }).join(',');
                thumb.remove();
            });
        })();
        </script>
        <?php
    }
}

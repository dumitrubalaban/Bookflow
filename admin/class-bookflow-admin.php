<?php
/**
 * Admin Panel for Bookings
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        add_filter('set_screen_option_bookflow_bookings_per_page', [$this, 'set_screen_option'], 10, 3);
    }

    public function add_admin_menu() {
        $hook = add_menu_page(
            Bookflow_I18n::t('admin.bookings'),
            // The sidebar's top-level label is the plugin/brand name (like
            // "WooCommerce" or "Yoast SEO" do), not translated — it's an
            // identifier for which plugin's menu this is, not UI copy.
            'Bookflow',
            'manage_woocommerce',
            'bookflow-bookings',
            [$this, 'bookings_page'],
            'dashicons-calendar-alt',
            56
        );
        // add_menu_page() auto-adds a first submenu item that mirrors the
        // *menu* title (now "Bookflow") rather than the page title
        // ("Bookings") — without this, the first submenu row would
        // redundantly repeat the top-level "Bookflow" label instead of
        // naming what the page actually shows.
        add_submenu_page(
            'bookflow-bookings',
            Bookflow_I18n::t('admin.bookings'),
            Bookflow_I18n::t('admin.bookings'),
            'manage_woocommerce',
            'bookflow-bookings'
        );
        add_action("load-$hook", [$this, 'screen_options']);
        add_filter("manage_{$hook}_columns", function () {
            return (new Bookflow_Bookings_List_Table())->get_columns();
        });
    }

    /**
     * Native Screen Options tab (top-right, collapsible) — "Bookings per
     * page" and per-column show/hide checkboxes, same UI WordPress already
     * gives Posts/Pages, wired through the same core APIs rather than a
     * custom control.
     */
    public function screen_options() {
        if (!empty($_GET['view'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-mode switch, no state change
            return; // no screen options on the single-booking detail view
        }
        add_screen_option('per_page', [
            'label'   => Bookflow_I18n::t('admin.bookings_per_page'),
            'default' => 20,
            'option'  => 'bookflow_bookings_per_page',
        ]);
    }

    public function set_screen_option($status, $option, $value) {
        return $option === 'bookflow_bookings_per_page' ? (int) $value : $status;
    }

    public function admin_scripts($hook) {
        if (strpos($hook, 'bookflow-bookings') !== false || $hook === 'toplevel_page_bookflow-bookings') {
            wp_enqueue_style('bookflow-admin-bookings', BOOKFLOW_PLUGIN_URL . 'admin/css/admin.css', [], BOOKFLOW_VERSION);
        }
    }

    public function bookings_page() {
        // Detail view
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view-mode switch, no state change
        if (!empty($_GET['view'])) {
            $this->booking_detail_page(absint($_GET['view']));
            return;
        }
        // phpcs:enable

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        $view = (isset($_GET['booking_view']) && $_GET['booking_view'] === 'trash') ? 'trash' : 'all';

        // Handle status updates (detail page also posts here in some flows)
        if (isset($_POST['bookflow_update_status'], $_POST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'bookflow_update_booking')) {
            $booking_id = absint($_POST['booking_id'] ?? 0);
            $new_status = sanitize_text_field(wp_unslash($_POST['new_status'] ?? ''));
            $note = sanitize_textarea_field(wp_unslash($_POST['status_note'] ?? ''));
            $result = Bookflow_Booking::transition_status($booking_id, $new_status, $note);
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>' . esc_html(Bookflow_I18n::t('admin.booking_updated')) . '</p></div>';
            }
        }

        // Single-row action from the row-actions links (View | Trash, or Restore | Delete Permanently)
        if (!empty($_GET['row_action']) && !empty($_GET['booking'])) {
            $booking_id = absint($_GET['booking']);
            if (wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'bookflow_row_action_' . $booking_id)) {
                switch ($_GET['row_action']) {
                    case 'trash':
                        Bookflow_Booking::trash($booking_id);
                        echo '<div class="notice notice-success is-dismissible"><p>Booking moved to Trash.</p></div>';
                        break;
                    case 'restore':
                        Bookflow_Booking::restore($booking_id);
                        echo '<div class="notice notice-success is-dismissible"><p>Booking restored.</p></div>';
                        break;
                    case 'delete':
                        Bookflow_Booking::delete($booking_id);
                        echo '<div class="notice notice-success is-dismissible"><p>Booking permanently deleted.</p></div>';
                        break;
                }
            }
        }

        $list_table = new Bookflow_Bookings_List_Table($view);

        // Bulk action from the list table's native checkbox + "Bulk Actions" dropdown.
        $bulk_action = $list_table->current_action();
        if ($bulk_action && !empty($_POST['booking_ids']) && check_admin_referer('bulk-bookings')) {
            $ids = array_filter(array_map('absint', (array) $_POST['booking_ids']));
            $count = 0;
            foreach ($ids as $id) {
                $ok = match ($bulk_action) {
                    'trash'   => Bookflow_Booking::trash($id),
                    'restore' => Bookflow_Booking::restore($id),
                    'delete'  => Bookflow_Booking::delete($id),
                    default   => false,
                };
                if ($ok) $count++;
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf('%d booking(s) updated.', $count)) . '</p></div>';
        }

        $list_table->prepare_items();

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php Bookflow_I18n::te('admin.bookings'); ?></h1>
            <hr class="wp-header-end">

            <?php $list_table->views(); ?>

            <form method="post">
                <?php wp_nonce_field('bulk-bookings'); ?>
                <input type="hidden" name="page" value="bookflow-bookings">
                <?php if ($view === 'trash') : ?><input type="hidden" name="booking_view" value="trash"><?php endif; ?>
                <?php $list_table->search_box(Bookflow_I18n::t('admin.search'), 'booking'); ?>
                <?php $list_table->display(); ?>
            </form>
        </div>
        <?php
    }

    private function booking_detail_page($booking_id) {
        $booking = Bookflow_Booking::get($booking_id);
        if (!$booking) {
            echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html(Bookflow_I18n::t('admin.booking_not_found')) . '</p></div></div>';
            return;
        }

        // Handle status update on detail page
        if (isset($_POST['bookflow_update_status'], $_POST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'bookflow_update_booking_' . $booking_id)) {
            $new_status = sanitize_text_field(wp_unslash($_POST['new_status'] ?? ''));
            $note = sanitize_textarea_field(wp_unslash($_POST['status_note'] ?? ''));
            $result = Bookflow_Booking::transition_status($booking_id, $new_status, $note);
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>' . esc_html(Bookflow_I18n::t('admin.status_updated')) . '</p></div>';
                $booking = Bookflow_Booking::get($booking_id);
            }
        }

        // Handle notes update
        if (isset($_POST['bookflow_save_notes'], $_POST['_wpnonce_notes']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce_notes'])), 'bookflow_save_notes_' . $booking_id)) {
            Bookflow_Booking::update($booking_id, [
                'internal_notes' => sanitize_textarea_field(wp_unslash($_POST['internal_notes'] ?? '')),
            ]);
            echo '<div class="notice notice-success"><p>' . esc_html(Bookflow_I18n::t('admin.notes_saved')) . '</p></div>';
            $booking = Bookflow_Booking::get($booking_id);
        }

        $product  = wc_get_product($booking->product_id);
        $resource = $booking->resource_id ? Bookflow_Resources::get($booking->resource_id) : null;
        $person_types = Bookflow_Booking::get_person_types($booking_id);
        $logs = Bookflow_Logger::get_booking_logs($booking_id, 30);

        $status_colors = [
            'pending' => '#f0ad4e', 'confirmed' => '#5cb85c', 'paid' => '#337ab7',
            'partially-paid' => '#f0ad4e', 'in-progress' => '#5bc0de', 'completed' => '#6c757d',
            'cancelled' => '#d9534f', 'refunded' => '#999', 'no-show' => '#d9534f',
        ];
        $color = $status_colors[$booking->status] ?? '#999';

        // Get allowed transitions
        $allowed_transitions = BOOKFLOW_STATUS_TRANSITIONS[$booking->status] ?? [];

        ?>
        <div class="wrap">
            <h1>
                <a href="<?php echo esc_url(admin_url('admin.php?page=bookflow-bookings')); ?>">&larr; <?php Bookflow_I18n::te('admin.bookings'); ?></a>
                &nbsp;/&nbsp;
                <?php echo esc_html(Bookflow_I18n::t('admin.booking_number', $booking->id)); ?>
                <span style="background:<?php echo esc_attr($color); ?>; color:#fff; padding:3px 10px; border-radius:3px; font-size:13px; margin-left:10px;">
                    <?php echo esc_html(Bookflow_I18n::status($booking->status)); ?>
                </span>
            </h1>

            <div style="display:flex; gap:20px; margin-top:20px; flex-wrap:wrap;">
                <!-- Left Column: Booking Details -->
                <div style="flex:2; min-width:400px;">
                    <div class="postbox">
                        <h2 class="hndle" style="padding:10px 15px; margin:0;"><?php Bookflow_I18n::te('admin.booking_details'); ?></h2>
                        <div class="inside" style="padding:15px;">
                            <table class="form-table" style="margin:0;">
                                <tr>
                                    <th><?php Bookflow_I18n::te('admin.product'); ?></th>
                                    <td><?php echo esc_html($product ? $product->get_name() : '#' . $booking->product_id); ?></td>
                                </tr>
                                <?php if ($resource) : ?>
                                <tr>
                                    <th><?php Bookflow_I18n::te('product.resource'); ?></th>
                                    <td><?php echo esc_html($resource->title); ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <th><?php Bookflow_I18n::te('admin.date'); ?></th>
                                    <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($booking->booking_date))); ?></td>
                                </tr>
                                <tr>
                                    <th><?php Bookflow_I18n::te('admin.time'); ?></th>
                                    <td>
                                        <?php echo esc_html($booking->start_time); ?>
                                        <?php if ($booking->end_time) : ?>
                                            &ndash; <?php echo esc_html($booking->end_time); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php Bookflow_I18n::te('admin.persons'); ?></th>
                                    <td>
                                        <?php echo esc_html($booking->persons_total); ?>
                                        <?php if (!empty($person_types)) : ?>
                                            <ul style="margin:5px 0 0 15px; list-style:disc;">
                                                <?php foreach ($person_types as $pt) : ?>
                                                    <li><?php echo esc_html($pt->person_type_name . ': ' . $pt->quantity); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php Bookflow_I18n::te('admin.cost'); ?></th>
                                    <td><strong><?php echo wp_kses_post(wc_price($booking->cost)); ?></strong></td>
                                </tr>
                                <?php if (!empty($booking->deposit_amount) && (float) $booking->deposit_amount > 0) : ?>
                                <tr>
                                    <th><?php Bookflow_I18n::te('cart.deposit_paid_now'); ?></th>
                                    <td><?php echo wp_kses_post(wc_price($booking->deposit_amount)); ?></td>
                                </tr>
                                <tr>
                                    <th><?php Bookflow_I18n::te('cart.balance_due'); ?></th>
                                    <td><strong style="color:#d9534f;"><?php echo wp_kses_post(wc_price($booking->full_total - $booking->deposit_amount)); ?></strong></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($booking->order_id) : ?>
                                <tr>
                                    <th><?php Bookflow_I18n::te('admin.order'); ?></th>
                                    <td>
                                        <a href="<?php echo esc_url(admin_url('post.php?post=' . $booking->order_id . '&action=edit')); ?>">
                                            #<?php echo esc_html($booking->order_id); ?>
                                        </a>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($booking->notes) : ?>
                                <tr>
                                    <th><?php Bookflow_I18n::te('admin.customer_notes'); ?></th>
                                    <td><?php echo esc_html($booking->notes); ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <th><?php Bookflow_I18n::te('admin.created'); ?></th>
                                    <td><?php echo esc_html($booking->created_at); ?></td>
                                </tr>
                                <?php if ($booking->confirmed_at) : ?>
                                <tr>
                                    <th><?php Bookflow_I18n::te('admin.confirmed'); ?></th>
                                    <td><?php echo esc_html($booking->confirmed_at); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($booking->cancelled_at) : ?>
                                <tr>
                                    <th><?php Bookflow_I18n::te('admin.cancelled'); ?></th>
                                    <td><?php echo esc_html($booking->cancelled_at); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($booking->completed_at) : ?>
                                <tr>
                                    <th><?php Bookflow_I18n::te('admin.completed'); ?></th>
                                    <td><?php echo esc_html($booking->completed_at); ?></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>

                    <!-- Customer Info -->
                    <div class="postbox">
                        <h2 class="hndle" style="padding:10px 15px; margin:0;"><?php Bookflow_I18n::te('admin.customer'); ?></h2>
                        <div class="inside" style="padding:15px;">
                            <p><strong><?php echo esc_html($booking->customer_name); ?></strong></p>
                            <?php if ($booking->customer_email) : ?>
                                <p><a href="mailto:<?php echo esc_attr($booking->customer_email); ?>"><?php echo esc_html($booking->customer_email); ?></a></p>
                            <?php endif; ?>
                            <?php if ($booking->customer_phone) : ?>
                                <p><a href="tel:<?php echo esc_attr($booking->customer_phone); ?>"><?php echo esc_html($booking->customer_phone); ?></a></p>
                            <?php endif; ?>
                            <?php if ($booking->ip_address) : ?>
                                <p style="color:#999; font-size:12px;">IP: <?php echo esc_html($booking->ip_address); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Activity Log -->
                    <?php if (!empty($logs)) : ?>
                    <div class="postbox">
                        <h2 class="hndle" style="padding:10px 15px; margin:0;"><?php Bookflow_I18n::te('admin.activity_log'); ?></h2>
                        <div class="inside" style="padding:15px; max-height:300px; overflow-y:auto;">
                            <?php foreach ($logs as $log) : $entry = $this->format_log_entry($log); ?>
                                <div style="border-bottom:1px solid #eee; padding:8px 0; font-size:13px;">
                                    <strong><?php echo esc_html($entry['label']); ?></strong>
                                    <?php if ($entry['detail']) : ?>
                                        <div style="color:#666; margin-top:2px;"><?php echo wp_kses_post($entry['detail']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($entry['note']) : ?>
                                        <br><em style="color:#888;"><?php echo esc_html($entry['note']); ?></em>
                                    <?php endif; ?>
                                    <br><small style="color:#999;"><?php echo esc_html($log->created_at); ?>
                                        <?php if ($log->user_id) : ?>
                                            — <?php
                                            $user = get_userdata($log->user_id);
                                            echo esc_html($user ? $user->display_name : '#' . $log->user_id);
                                            ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Right Column: Actions -->
                <div style="flex:1; min-width:280px;">
                    <!-- Status Change -->
                    <?php if (!empty($allowed_transitions)) : ?>
                    <div class="postbox">
                        <h2 class="hndle" style="padding:10px 15px; margin:0;"><?php Bookflow_I18n::te('admin.change_status'); ?></h2>
                        <div class="inside" style="padding:15px;">
                            <form method="post">
                                <?php wp_nonce_field('bookflow_update_booking_' . $booking_id); ?>
                                <input type="hidden" name="booking_id" value="<?php echo esc_attr($booking_id); ?>">

                                <select name="new_status" style="width:100%; margin-bottom:10px;">
                                    <?php foreach ($allowed_transitions as $status) : ?>
                                        <option value="<?php echo esc_attr($status); ?>">
                                            <?php echo esc_html(Bookflow_I18n::status($status)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <textarea name="status_note" rows="2" style="width:100%; margin-bottom:10px;" placeholder="<?php echo esc_attr(Bookflow_I18n::t('admin.note_optional')); ?>"></textarea>

                                <button type="submit" name="bookflow_update_status" class="button button-primary" style="width:100%;">
                                    <?php Bookflow_I18n::te('admin.update_status'); ?>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Internal Notes -->
                    <div class="postbox">
                        <h2 class="hndle" style="padding:10px 15px; margin:0;"><?php Bookflow_I18n::te('admin.internal_notes'); ?></h2>
                        <div class="inside" style="padding:15px;">
                            <form method="post">
                                <?php wp_nonce_field('bookflow_save_notes_' . $booking_id, '_wpnonce_notes'); ?>
                                <textarea name="internal_notes" rows="5" style="width:100%; margin-bottom:10px;"><?php echo esc_textarea($booking->internal_notes ?? ''); ?></textarea>
                                <button type="submit" name="bookflow_save_notes" class="button" style="width:100%;">
                                    <?php Bookflow_I18n::te('admin.save_notes'); ?>
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Trash / Restore / Delete -->
                    <div class="postbox">
                        <h2 class="hndle" style="padding:10px 15px; margin:0;">Manage</h2>
                        <div class="inside" style="padding:15px;">
                            <?php if (!empty($booking->deleted_at)) : ?>
                                <p style="color:#d63638;">In Trash since <?php echo esc_html($booking->deleted_at); ?>.</p>
                                <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => 'bookflow-bookings', 'row_action' => 'restore', 'booking' => $booking_id], admin_url('admin.php')), 'bookflow_row_action_' . $booking_id)); ?>" class="button" style="width:100%; text-align:center; margin-bottom:6px;">Restore</a>
                                <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => 'bookflow-bookings', 'row_action' => 'delete', 'booking' => $booking_id], admin_url('admin.php')), 'bookflow_row_action_' . $booking_id)); ?>" class="button" style="width:100%; text-align:center; border-color:#d63638; color:#d63638;" onclick="return confirm('Permanently delete this booking? This cannot be undone.');">Delete Permanently</a>
                            <?php else : ?>
                                <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => 'bookflow-bookings', 'row_action' => 'trash', 'booking' => $booking_id], admin_url('admin.php')), 'bookflow_row_action_' . $booking_id)); ?>" class="button" style="width:100%; text-align:center;">Move to Trash</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Turn one raw bookflow_log row into a human-readable Activity Log
     * entry. old_value/new_value are stored as JSON (Bookflow_Logger::log()
     * — full booking snapshots for create, raw diffs for update) so they
     * can support real auditing later, but dumping that JSON straight into
     * the page read like a debug console rather than an admin feature —
     * this renders the same data as plain sentences instead.
     *
     * @return array{label: string, detail: string, note: string}
     */
    private function format_log_entry($log) {
        // Fields worth showing in an update diff, mapped to an existing
        // i18n label — internal/system-only columns (rating_token,
        // google_calendar_event_id, customer_locale, schedule_id, ids)
        // are deliberately left out as noise no admin needs to audit.
        $field_labels = [
            'booking_date'    => Bookflow_I18n::t('admin.date'),
            'start_time'      => Bookflow_I18n::t('admin.time'),
            'persons_total'   => Bookflow_I18n::t('admin.persons'),
            'cost'            => Bookflow_I18n::t('admin.total'),
            'customer_name'   => Bookflow_I18n::t('admin.name'),
            'customer_email'  => Bookflow_I18n::t('admin.email'),
            'customer_phone'  => Bookflow_I18n::t('form.phone'),
            'notes'           => Bookflow_I18n::t('admin.customer_notes'),
            'internal_notes'  => Bookflow_I18n::t('admin.internal_notes'),
            'cancellation_reason' => Bookflow_I18n::t('admin.cancelled'),
            'resource_id'     => Bookflow_I18n::t('admin.product'),
        ];

        $new = $log->new_value ? json_decode($log->new_value, true) : null;
        $old = $log->old_value ? json_decode($log->old_value, true) : null;
        $note = $log->note ?: '';
        $detail = '';

        switch ($log->action) {
            case 'booking_created':
                $label = Bookflow_I18n::t('admin.booking_created');
                break;

            case 'booking_updated':
                $label = Bookflow_I18n::t('admin.booking_updated');
                if (is_array($new)) {
                    $lines = [];
                    foreach ($field_labels as $key => $field_label) {
                        if (!array_key_exists($key, $new)) {
                            continue;
                        }
                        $old_val = is_array($old) && array_key_exists($key, $old) ? $old[$key] : null;
                        if ((string) $old_val === (string) $new[$key]) {
                            continue; // unchanged — not worth a line
                        }
                        $lines[] = sprintf(
                            '%s: %s &rarr; %s',
                            esc_html($field_label),
                            esc_html($old_val !== null && $old_val !== '' ? $old_val : '—'),
                            esc_html($new[$key] !== null && $new[$key] !== '' ? $new[$key] : '—')
                        );
                    }
                    $detail = implode('<br>', $lines);
                }
                break;

            case 'status_changed':
                $label = Bookflow_I18n::t('admin.status_updated');
                // $note already reads "Status changed from X to Y" in
                // plain English from Bookflow_Logger::log_status_change() —
                // nothing extra to add here.
                break;

            case 'booking_deleted':
                $label = Bookflow_I18n::t('admin.delete_permanently');
                break;
            case 'booking_trashed':
                $label = Bookflow_I18n::t('admin.trash');
                break;
            case 'booking_restored':
                $label = Bookflow_I18n::t('admin.restore');
                break;

            default:
                $label = ucwords(str_replace('_', ' ', $log->action));
        }

        return ['label' => $label, 'detail' => $detail, 'note' => $note];
    }
}

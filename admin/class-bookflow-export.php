<?php
/**
 * CSV Export for Bookings
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Export {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_submenu']);
        add_action('admin_init', [$this, 'handle_export']);
    }

    public function add_submenu() {
        add_submenu_page(
            'bookflow-bookings',
            Bookflow_I18n::t('admin.export_bookings'),
            Bookflow_I18n::t('admin.export_bookings'),
            'manage_woocommerce',
            'bookflow-export',
            [$this, 'render_page']
        );
    }

    public function render_page() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php Bookflow_I18n::te('admin.export_bookings'); ?></h1>
            <hr class="wp-header-end">

            <form method="post">
                <?php wp_nonce_field('bookflow_export_bookings'); ?>

                <table class="form-table">
                    <tr>
                        <th><label><?php Bookflow_I18n::te('admin.date_from'); ?></label></th>
                        <td><input type="date" name="date_from" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label><?php Bookflow_I18n::te('admin.date_to'); ?></label></th>
                        <td><input type="date" name="date_to" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label><?php Bookflow_I18n::te('admin.status'); ?></label></th>
                        <td>
                            <select name="status">
                                <option value=""><?php Bookflow_I18n::te('admin.all'); ?></option>
                                <?php foreach (Bookflow_I18n::statuses() as $key => $label) : ?>
                                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php Bookflow_I18n::te('admin.product'); ?></label></th>
                        <td>
                            <select name="product_id">
                                <option value=""><?php Bookflow_I18n::te('admin.all_products'); ?></option>
                                <?php
                                $products = wc_get_products(['type' => 'booking', 'limit' => 100]);
                                foreach ($products as $p) {
                                    echo '<option value="' . esc_attr($p->get_id()) . '">' . esc_html($p->get_name()) . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="submit" name="bookflow_export" class="button button-primary"><?php Bookflow_I18n::te('admin.export_csv'); ?></button>
                </p>
            </form>
        </div>
        <?php
    }

    public function handle_export() {
        if (!isset($_POST['bookflow_export']) || !check_admin_referer('bookflow_export_bookings')) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        $args = [
            'limit'   => 10000,
            'orderby' => 'booking_date',
            'order'   => 'ASC',
        ];

        if (!empty($_POST['date_from'])) $args['date_from'] = sanitize_text_field(wp_unslash($_POST['date_from']));
        if (!empty($_POST['date_to']))   $args['date_to']   = sanitize_text_field(wp_unslash($_POST['date_to']));
        if (!empty($_POST['status']))     $args['status']     = sanitize_text_field(wp_unslash($_POST['status']));
        if (!empty($_POST['product_id'])) $args['product_id'] = absint($_POST['product_id']);

        $bookings = Bookflow_Booking::query($args);

        $filename = 'bookflow-bookings-' . gmdate('Y-m-d-His') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Pragma: no-cache');
        header('Expires: 0');

        // WP_Filesystem has no equivalent for streaming directly to the
        // active HTTP response — it operates on real files on disk, not
        // the php://output stream a CSV download needs to write to.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $output = fopen('php://output', 'w');

        // BOM for Excel UTF-8
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite($output, "\xEF\xBB\xBF");

        // Header row
        fputcsv($output, [
            'ID', 'Product', 'Resource', 'Order ID', 'Date', 'Start Time', 'End Time',
            'Persons', 'Cost', 'Status', 'Customer Name', 'Customer Email',
            'Customer Phone', 'Notes', 'Created', 'Confirmed', 'Cancelled', 'Completed',
        ]);

        foreach ($bookings as $b) {
            $product = wc_get_product($b->product_id);
            $resource = $b->resource_id ? Bookflow_Resources::get($b->resource_id) : null;

            fputcsv($output, [
                $b->id,
                $product ? $product->get_name() : '#' . $b->product_id,
                $resource ? $resource->title : '',
                $b->order_id ?: '',
                $b->booking_date,
                $b->start_time,
                $b->end_time ?: '',
                $b->persons_total,
                $b->cost,
                $b->status,
                $b->customer_name,
                $b->customer_email,
                $b->customer_phone,
                $b->notes,
                $b->created_at,
                $b->confirmed_at ?: '',
                $b->cancelled_at ?: '',
                $b->completed_at ?: '',
            ]);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($output);
        exit;
    }
}

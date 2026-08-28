<?php
/**
 * CSV Import for Bookings
 *
 * Accepts the same column layout Bookflow's own CSV export produces
 * (see Bookflow_Export), so a round-trip export → edit → import works
 * out of the box. Also tolerant of a minimal column set for bookings
 * migrated from another system.
 *
 * Imported rows are inserted directly (bypassing live slot/capacity
 * checks, which only make sense for new bookings against current
 * availability — not for replaying historical data) and do NOT fire
 * `bookflow_booking_created` (so importing hundreds of past bookings
 * doesn't spam customer emails or create hundreds of Google Calendar
 * events). A dedicated `bookflow_booking_imported` action fires
 * instead for anyone who wants to hook into imports specifically.
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Import {

    const MAX_ROWS = 5000;

    public function __construct() {
        add_action('admin_menu', [$this, 'add_submenu']);
        add_action('admin_init', [$this, 'handle_import']);
    }

    public function add_submenu() {
        add_submenu_page(
            'bookflow-bookings',
            Bookflow_I18n::t('admin.import_bookings'),
            Bookflow_I18n::t('admin.import_bookings'),
            'manage_woocommerce',
            'bookflow-import',
            [$this, 'render_page']
        );
    }

    public function render_page() {
        $result = get_transient('bookflow_import_result_' . get_current_user_id());
        delete_transient('bookflow_import_result_' . get_current_user_id());
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php Bookflow_I18n::te('admin.import_bookings'); ?></h1>
            <hr class="wp-header-end">

            <?php if ($result) : ?>
                <div class="notice <?php echo $result['errors'] ? 'notice-warning' : 'notice-success'; ?>">
                    <p>
                        <strong><?php echo esc_html(sprintf('%d imported, %d skipped.', $result['imported'], count($result['errors']))); ?></strong>
                    </p>
                    <?php if (!empty($result['errors'])) : ?>
                        <ul style="list-style: disc; margin-left: 20px;">
                            <?php foreach (array_slice($result['errors'], 0, 50) as $err) : ?>
                                <li><?php echo esc_html($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if (count($result['errors']) > 50) : ?>
                            <p><?php echo esc_html(sprintf('...and %d more.', count($result['errors']) - 50)); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <p><?php Bookflow_I18n::te('admin.import_bookings_desc'); ?></p>

            <table class="widefat" style="max-width: 700px; margin-bottom: 20px;">
                <thead><tr><th colspan="2"><?php Bookflow_I18n::te('admin.import_columns'); ?></th></tr></thead>
                <tbody>
                    <tr><td><code>Product</code></td><td><?php Bookflow_I18n::te('admin.import_col_product'); ?></td></tr>
                    <tr><td><code>Date</code></td><td>YYYY-MM-DD</td></tr>
                    <tr><td><code>Start Time</code></td><td>HH:MM (24h)</td></tr>
                    <tr><td><code>End Time</code></td><td><?php Bookflow_I18n::te('admin.optional'); ?></td></tr>
                    <tr><td><code>Persons</code></td><td><?php Bookflow_I18n::te('admin.optional'); ?> — default 1</td></tr>
                    <tr><td><code>Cost</code></td><td><?php Bookflow_I18n::te('admin.optional'); ?> — default 0</td></tr>
                    <tr><td><code>Status</code></td><td><?php echo esc_html(implode(', ', array_keys(BOOKFLOW_STATUSES))); ?></td></tr>
                    <tr><td><code>Customer Name</code></td><td></td></tr>
                    <tr><td><code>Customer Email</code></td><td><?php Bookflow_I18n::te('admin.optional'); ?></td></tr>
                    <tr><td><code>Customer Phone</code></td><td><?php Bookflow_I18n::te('admin.optional'); ?></td></tr>
                    <tr><td><code>Resource</code></td><td><?php Bookflow_I18n::te('admin.optional'); ?> — match by name</td></tr>
                    <tr><td><code>Notes</code></td><td><?php Bookflow_I18n::te('admin.optional'); ?></td></tr>
                </tbody>
            </table>

            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('bookflow_import_bookings'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="bookflow_import_file"><?php Bookflow_I18n::te('admin.import_file'); ?></label></th>
                        <td><input type="file" id="bookflow_import_file" name="bookflow_import_file" accept=".csv,text/csv" required></td>
                    </tr>
                </table>
                <p>
                    <button type="submit" name="bookflow_import" class="button button-primary"><?php Bookflow_I18n::te('admin.import_csv'); ?></button>
                </p>
            </form>
        </div>
        <?php
    }

    public function handle_import() {
        if (!isset($_POST['bookflow_import']) || !check_admin_referer('bookflow_import_bookings')) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        if (empty($_FILES['bookflow_import_file']['tmp_name']) || !is_uploaded_file($_FILES['bookflow_import_file']['tmp_name'])) {
            $this->store_result(0, [Bookflow_I18n::t('admin.import_no_file')]);
            $this->redirect_back();
        }

        $filetype = wp_check_filetype($_FILES['bookflow_import_file']['name'], ['csv' => 'text/csv']);
        if (empty($filetype['ext']) || $filetype['ext'] !== 'csv') {
            $this->store_result(0, [Bookflow_I18n::t('admin.import_invalid_type')]);
            $this->redirect_back();
        }

        $handle = fopen($_FILES['bookflow_import_file']['tmp_name'], 'r');
        if (!$handle) {
            $this->store_result(0, [Bookflow_I18n::t('admin.import_read_failed')]);
            $this->redirect_back();
        }

        $result = self::process($handle);
        fclose($handle);

        $this->store_result($result['imported'], $result['errors']);
        $this->redirect_back();
    }

    /**
     * Parse and import an open CSV file handle. Public + static so it's
     * unit-testable without going through an HTTP upload.
     *
     * @return array{imported:int, errors:string[]}
     */
    public static function process($handle) {
        $imported = 0;
        $errors = [];
        $row_num = 1;

        // Strip a UTF-8 BOM if present (Excel loves to add one).
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle);
        if (!$header) {
            return ['imported' => 0, 'errors' => [Bookflow_I18n::t('admin.import_empty_file')]];
        }

        $map = self::header_map($header);
        if (!isset($map['product']) || !isset($map['date']) || !isset($map['start_time'])) {
            return ['imported' => 0, 'errors' => [Bookflow_I18n::t('admin.import_missing_columns')]];
        }

        $products_by_name = self::products_by_name();
        $resources_by_name = self::resources_by_name();
        $valid_statuses = array_keys(BOOKFLOW_STATUSES);

        while (($row = fgetcsv($handle)) !== false) {
            $row_num++;

            if (count($row) === 1 && trim($row[0]) === '') {
                continue; // blank line
            }

            if ($row_num > self::MAX_ROWS + 1) {
                $errors[] = sprintf('Row %d: import capped at %d rows, stopping here.', $row_num, self::MAX_ROWS);
                break;
            }

            $get = function ($key) use ($map, $row) {
                return isset($map[$key], $row[$map[$key]]) ? trim($row[$map[$key]]) : '';
            };

            $product_ref = $get('product');
            $date = $get('date');
            $start_time = $get('start_time');

            if ($product_ref === '' || $date === '' || $start_time === '') {
                $errors[] = "Row $row_num: missing required Product, Date, or Start Time.";
                continue;
            }

            // Product may be given as a numeric ID or an exact product name.
            $product_id = ctype_digit($product_ref) ? (int) $product_ref : ($products_by_name[self::norm($product_ref)] ?? 0);
            if (!$product_id) {
                $errors[] = "Row $row_num: product \"$product_ref\" not found (must be a bookable product).";
                continue;
            }

            $date_obj = DateTime::createFromFormat('Y-m-d', $date);
            if (!$date_obj || $date_obj->format('Y-m-d') !== $date) {
                $errors[] = "Row $row_num: invalid date \"$date\" (expected YYYY-MM-DD).";
                continue;
            }

            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start_time)) {
                $errors[] = "Row $row_num: invalid start time \"$start_time\" (expected HH:MM).";
                continue;
            }

            $end_time = $get('end_time');
            if ($end_time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end_time)) {
                $errors[] = "Row $row_num: invalid end time \"$end_time\" (expected HH:MM).";
                continue;
            }

            $status = strtolower($get('status')) ?: 'pending';
            if (!in_array($status, $valid_statuses, true)) {
                $errors[] = "Row $row_num: unknown status \"$status\" (expected one of: " . implode(', ', $valid_statuses) . ').';
                continue;
            }

            $resource_ref = $get('resource');
            $resource_id = null;
            if ($resource_ref !== '') {
                $resource_id = ctype_digit($resource_ref) ? (int) $resource_ref : ($resources_by_name[self::norm($resource_ref)] ?? null);
                if (!$resource_id) {
                    $errors[] = "Row $row_num: resource \"$resource_ref\" not found — importing without a resource.";
                    $resource_id = null;
                }
            }

            $customer_email = $get('customer_email');
            if ($customer_email !== '' && !is_email($customer_email)) {
                $errors[] = "Row $row_num: invalid customer email \"$customer_email\".";
                continue;
            }

            $persons = $get('persons');
            $persons = ($persons !== '' && ctype_digit($persons)) ? max(1, (int) $persons) : 1;

            $cost = $get('cost');
            $cost = is_numeric($cost) ? max(0, (float) $cost) : 0.0;

            $booking_id = self::insert_row([
                'product_id'     => $product_id,
                'resource_id'    => $resource_id,
                'booking_date'   => $date,
                'start_time'     => $start_time,
                'end_time'       => $end_time !== '' ? $end_time : null,
                'persons_total'  => $persons,
                'cost'           => $cost,
                'status'         => $status,
                'customer_name'  => sanitize_text_field($get('customer_name')),
                'customer_email' => sanitize_email($customer_email),
                'customer_phone' => sanitize_text_field($get('customer_phone')),
                'notes'          => sanitize_textarea_field($get('notes')),
            ]);

            if (is_wp_error($booking_id)) {
                $errors[] = "Row $row_num: " . $booking_id->get_error_message();
                continue;
            }

            $imported++;
        }

        return ['imported' => $imported, 'errors' => $errors];
    }

    /**
     * Insert a booking row directly, bypassing the live slot/capacity
     * lock in Bookflow_Booking::create() (which is meant to protect
     * new bookings against current availability, not historical data),
     * while keeping the same sanitization and status-machine timestamps.
     *
     * @return int|WP_Error
     */
    private static function insert_row($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_bookings';

        $now = current_time('mysql', true);
        $timestamps = ['created_at' => $now];
        if (in_array($data['status'], ['confirmed', 'paid', 'in-progress', 'completed'], true)) {
            $timestamps['confirmed_at'] = $now;
        }
        if ($data['status'] === 'cancelled') {
            $timestamps['cancelled_at'] = $now;
        }
        if ($data['status'] === 'completed') {
            $timestamps['completed_at'] = $now;
        }

        $result = $wpdb->insert($table, array_merge([
            'product_id'      => absint($data['product_id']),
            'resource_id'     => $data['resource_id'] ? absint($data['resource_id']) : null,
            'booking_date'    => $data['booking_date'],
            'start_time'      => $data['start_time'],
            'end_time'        => $data['end_time'],
            'all_day'         => 0,
            'persons_total'   => $data['persons_total'],
            'cost'            => $data['cost'],
            'status'          => $data['status'],
            'customer_name'   => $data['customer_name'],
            'customer_email'  => $data['customer_email'],
            'customer_phone'  => $data['customer_phone'],
            'notes'           => $data['notes'],
            'internal_notes'  => 'Imported via CSV on ' . current_time('mysql'),
        ], $timestamps));

        if ($result === false) {
            return new WP_Error('db_error', 'Could not insert booking (' . $wpdb->last_error . ').');
        }

        $booking_id = $wpdb->insert_id;

        do_action('bookflow_booking_imported', $data, $booking_id);
        Bookflow_Logger::log('booking_imported', $booking_id, ['new' => $data]);

        return $booking_id;
    }

    /** Map lowercased/trimmed header names (accepting both the export's names and a few aliases) to column indexes. */
    private static function header_map(array $header) {
        $aliases = [
            'product'         => 'product',
            'resource'        => 'resource',
            'date'            => 'date',
            'booking date'    => 'date',
            'start time'      => 'start_time',
            'start'           => 'start_time',
            'end time'        => 'end_time',
            'end'             => 'end_time',
            'persons'         => 'persons',
            'guests'          => 'persons',
            'cost'            => 'cost',
            'total'           => 'cost',
            'status'          => 'status',
            'customer name'   => 'customer_name',
            'customer email'  => 'customer_email',
            'customer phone'  => 'customer_phone',
            'notes'           => 'notes',
        ];

        $map = [];
        foreach ($header as $i => $col) {
            $key = self::norm($col);
            if (isset($aliases[$key])) {
                $map[$aliases[$key]] = $i;
            }
        }
        return $map;
    }

    private static function norm($str) {
        return strtolower(trim(preg_replace('/\s+/', ' ', (string) $str)));
    }

    private static function products_by_name() {
        $map = [];
        $products = wc_get_products(['type' => 'booking', 'limit' => -1]);
        foreach ($products as $p) {
            $map[self::norm($p->get_name())] = $p->get_id();
        }
        return $map;
    }

    private static function resources_by_name() {
        $map = [];
        foreach (Bookflow_Resources::get_all() as $r) {
            $map[self::norm($r->title)] = $r->id;
        }
        return $map;
    }

    private function store_result($imported, $errors) {
        set_transient('bookflow_import_result_' . get_current_user_id(), [
            'imported' => $imported,
            'errors'   => $errors,
        ], 60);
    }

    private function redirect_back() {
        wp_safe_redirect(admin_url('admin.php?page=bookflow-import'));
        exit;
    }
}

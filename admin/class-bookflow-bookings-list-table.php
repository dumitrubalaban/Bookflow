<?php
/**
 * Bookings admin table — extends core WP_List_Table, the same base class
 * Posts/Pages/Users/WooCommerce Orders use. Gets native styling, checkbox
 * column, hover row actions, sortable headers, and a Bulk Actions dropdown
 * for free instead of hand-rolled HTML, and adds an "All / Trash" views
 * split matching how Posts handles trash.
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Bookflow_Bookings_List_Table extends WP_List_Table {

    /** 'all' | 'trash' */
    public $view = 'all';

    private $status_colors = [
        'pending'     => '#f0ad4e',
        'confirmed'   => '#5cb85c',
        'paid'        => '#337ab7',
        'in-progress' => '#5bc0de',
        'completed'   => '#6c757d',
        'cancelled'   => '#d9534f',
        'refunded'    => '#999',
        'no-show'     => '#d9534f',
    ];

    public function __construct($view = 'all') {
        parent::__construct([
            'singular' => 'booking',
            'plural'   => 'bookings',
            'ajax'     => false,
        ]);
        $this->view = $view === 'trash' ? 'trash' : 'all';
    }

    public function get_columns() {
        return [
            'cb'       => '<input type="checkbox" />',
            'id'       => 'ID',
            'product'  => Bookflow_I18n::t('admin.product'),
            'customer' => Bookflow_I18n::t('admin.customer'),
            'date'     => Bookflow_I18n::t('admin.date'),
            'time'     => Bookflow_I18n::t('admin.time'),
            'persons'  => Bookflow_I18n::t('admin.persons'),
            'total'    => Bookflow_I18n::t('admin.total'),
            'status'   => Bookflow_I18n::t('admin.status'),
            'order'    => Bookflow_I18n::t('admin.order'),
        ];
    }

    protected function get_sortable_columns() {
        return [
            'id'      => ['id', false],
            'date'    => ['booking_date', true],
            'total'   => ['cost', false],
            'status'  => ['status', false],
            'persons' => ['persons_total', false],
        ];
    }

    public function get_bulk_actions() {
        if ($this->view === 'trash') {
            return [
                'restore' => Bookflow_I18n::t('admin.restore'),
                'delete'  => Bookflow_I18n::t('admin.delete_permanently'),
            ];
        }
        return [
            'trash' => Bookflow_I18n::t('admin.move_to_trash'),
        ];
    }

    protected function column_cb($item) {
        return sprintf('<input type="checkbox" name="booking_ids[]" value="%d" />', (int) $item->id);
    }

    protected function column_id($item) {
        $view_url = add_query_arg(['page' => 'bookflow-bookings', 'view' => $item->id], admin_url('admin.php'));

        if ($this->view === 'trash') {
            $restore_url = wp_nonce_url(add_query_arg(['page' => 'bookflow-bookings', 'row_action' => 'restore', 'booking' => $item->id]), 'bookflow_row_action_' . $item->id);
            $delete_url  = wp_nonce_url(add_query_arg(['page' => 'bookflow-bookings', 'row_action' => 'delete', 'booking' => $item->id]), 'bookflow_row_action_' . $item->id);
            $actions = [
                'restore' => sprintf('<a href="%s">%s</a>', esc_url($restore_url), esc_html(Bookflow_I18n::t('admin.restore'))),
                'delete'  => sprintf('<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>', esc_url($delete_url), esc_js(Bookflow_I18n::t('admin.delete_booking_confirm')), esc_html(Bookflow_I18n::t('admin.delete_permanently'))),
            ];
        } else {
            $trash_url = wp_nonce_url(add_query_arg(['page' => 'bookflow-bookings', 'row_action' => 'trash', 'booking' => $item->id]), 'bookflow_row_action_' . $item->id);
            $actions = [
                'view'  => sprintf('<a href="%s">%s</a>', esc_url($view_url), esc_html(Bookflow_I18n::t('admin.view'))),
                'trash' => sprintf('<a href="%s" class="submitdelete">%s</a>', esc_url($trash_url), esc_html(Bookflow_I18n::t('admin.trash'))),
            ];
        }

        return sprintf('<a href="%s"><strong>#%d</strong></a>%s', esc_url($view_url), (int) $item->id, $this->row_actions($actions));
    }

    protected function column_product($item) {
        $product = wc_get_product($item->product_id);
        return esc_html($product ? $product->get_name() : '#' . $item->product_id);
    }

    protected function column_customer($item) {
        $out = '<strong>' . esc_html($item->customer_name) . '</strong><br><small>' . esc_html($item->customer_email) . '</small>';
        if ($item->customer_phone) {
            $out .= '<br><small>' . esc_html($item->customer_phone) . '</small>';
        }
        return $out;
    }

    protected function column_date($item) {
        return esc_html(date_i18n(get_option('date_format'), strtotime($item->booking_date)));
    }

    protected function column_time($item) {
        return esc_html($item->start_time);
    }

    protected function column_persons($item) {
        return esc_html($item->persons_total);
    }

    protected function column_total($item) {
        return wp_kses_post(wc_price($item->cost));
    }

    protected function column_status($item) {
        $color = $this->status_colors[$item->status] ?? '#999';
        return sprintf(
            '<span style="background:%s; color:#fff; padding:3px 8px; border-radius:3px; font-size:12px;">%s</span>',
            esc_attr($color),
            esc_html(Bookflow_I18n::status($item->status))
        );
    }

    protected function column_order($item) {
        if (!$item->order_id) {
            return '&mdash;';
        }
        return sprintf(
            '<a href="%s">#%d</a>',
            esc_url(admin_url('post.php?post=' . $item->order_id . '&action=edit')),
            (int) $item->order_id
        );
    }

    protected function column_default($item, $column_name) {
        return isset($item->$column_name) ? esc_html($item->$column_name) : '';
    }

    protected function get_views() {
        $all_count   = Bookflow_Booking::count([]);
        $trash_count = Bookflow_Booking::count(['trashed_only' => true]);
        $base_url    = remove_query_arg(['booking_view', 'paged'], admin_url('admin.php?page=bookflow-bookings'));

        $views = [
            'all' => sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url($base_url),
                $this->view === 'all' ? 'current' : '',
                esc_html(Bookflow_I18n::t('admin.all')),
                $all_count
            ),
        ];

        if ($trash_count > 0 || $this->view === 'trash') {
            $views['trash'] = sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url(add_query_arg('booking_view', 'trash', $base_url)),
                $this->view === 'trash' ? 'current' : '',
                esc_html(Bookflow_I18n::t('admin.trash')),
                $trash_count
            );
        }

        return $views;
    }

    /**
     * Build Bookflow_Booking::query()/count() args from the current filters + view.
     * Uses $_REQUEST — filters, search, and bulk actions all live in one
     * <form method="post">, same as WP core's Posts screen, so filter values
     * arrive as POST fields when submitted via the "Filter" button, exactly
     * like $_REQUEST['cat'] on edit.php.
     */
    public function build_query_args() {
        $args = [];

        if ($this->view === 'trash') {
            $args['trashed_only'] = true;
        }

        if (!empty($_REQUEST['status']))     $args['status']     = sanitize_text_field(wp_unslash($_REQUEST['status']));
        if (!empty($_REQUEST['product_id'])) $args['product_id'] = absint($_REQUEST['product_id']);
        if (!empty($_REQUEST['s']))          $args['search']     = sanitize_text_field(wp_unslash($_REQUEST['s']));

        // The "m" month-picker (YYYYMM, same convention as WP core's own
        // months_dropdown()) takes priority over the manual date range when set.
        $m = sanitize_text_field(wp_unslash($_REQUEST['m'] ?? '0'));
        if ($m && $m !== '0' && preg_match('/^\d{6}$/', $m)) {
            $year = (int) substr($m, 0, 4);
            $month = (int) substr($m, 4, 2);
            $last_day = cal_days_in_month(CAL_GREGORIAN, $month, $year);
            $args['date_from'] = sprintf('%04d-%02d-01', $year, $month);
            $args['date_to']   = sprintf('%04d-%02d-%02d', $year, $month, $last_day);
        } else {
            if (!empty($_REQUEST['date_from'])) $args['date_from'] = sanitize_text_field(wp_unslash($_REQUEST['date_from']));
            if (!empty($_REQUEST['date_to']))   $args['date_to']   = sanitize_text_field(wp_unslash($_REQUEST['date_to']));
        }

        return $args;
    }

    /**
     * Every distinct year/month that has a booking in the current view (all
     * vs trash), most recent first — spans however far back and forward the
     * real data goes, past/current/future alike, same as WP core's own
     * months_dropdown() only showing months that actually have posts.
     */
    protected function get_month_options() {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_bookings';
        $trash_clause = $this->view === 'trash' ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL';
        return $wpdb->get_results(
            "SELECT DISTINCT YEAR(booking_date) AS year, MONTH(booking_date) AS month
             FROM $table
             WHERE $trash_clause
             ORDER BY year DESC, month DESC"
        );
    }

    /**
     * Native WP_List_Table integration point for extra filter controls —
     * rendered inside the same .tablenav .actions bar the Bulk Actions
     * dropdown lives in, styled identically to core (Posts' category/date
     * filters use this exact same pattern), instead of a separate custom box.
     */
    protected function extra_tablenav($which) {
        if ($which !== 'top') {
            return;
        }
        $status_filter  = sanitize_text_field(wp_unslash($_REQUEST['status'] ?? ''));
        $product_filter = absint($_REQUEST['product_id'] ?? 0);
        $date_from      = sanitize_text_field(wp_unslash($_REQUEST['date_from'] ?? ''));
        $date_to        = sanitize_text_field(wp_unslash($_REQUEST['date_to'] ?? ''));
        $month_filter   = sanitize_text_field(wp_unslash($_REQUEST['m'] ?? '0'));
        ?>
        <div class="alignleft actions">
            <select name="status">
                <option value=""><?php Bookflow_I18n::te('admin.all'); ?> <?php echo esc_html(Bookflow_I18n::t('admin.status')); ?></option>
                <?php foreach (Bookflow_I18n::statuses() as $key => $label) : ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($status_filter, $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <label for="bookflow-filter-by-date" class="screen-reader-text"><?php Bookflow_I18n::te('admin.filter_by_month'); ?></label>
            <select name="m" id="bookflow-filter-by-date">
                <option value="0" <?php selected($month_filter, '0'); ?>><?php Bookflow_I18n::te('admin.all_dates'); ?></option>
                <?php foreach ($this->get_month_options() as $row) :
                    $ym = sprintf('%04d%02d', $row->year, $row->month);
                ?>
                    <option value="<?php echo esc_attr($ym); ?>" <?php selected($month_filter, $ym); ?>>
                        <?php echo esc_html(Bookflow_I18n::t('calendar.month.' . (int) $row->month) . ' ' . $row->year); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="product_id">
                <option value=""><?php Bookflow_I18n::te('admin.all'); ?> <?php echo esc_html(Bookflow_I18n::t('admin.product')); ?></option>
                <?php
                $products = wc_get_products(['type' => 'booking', 'limit' => 100]);
                foreach ($products as $p) {
                    echo '<option value="' . esc_attr($p->get_id()) . '" ' . selected($product_filter, $p->get_id(), false) . '>' . esc_html($p->get_name()) . '</option>';
                }
                ?>
            </select>
            <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" placeholder="<?php echo esc_attr(Bookflow_I18n::t('admin.from')); ?>">
            <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" placeholder="<?php echo esc_attr(Bookflow_I18n::t('admin.to')); ?>">
            <?php submit_button(Bookflow_I18n::t('admin.filter'), 'button', 'filter_action', false); ?>
        </div>
        <?php
    }

    public function prepare_items() {
        $per_page = $this->get_items_per_page('bookflow_bookings_per_page', 20);
        $current_page = $this->get_pagenum();

        $args = $this->build_query_args();
        $args['limit']  = $per_page;
        $args['offset'] = ($current_page - 1) * $per_page;

        $orderby = sanitize_text_field(wp_unslash($_GET['orderby'] ?? 'booking_date'));
        $order   = sanitize_text_field(wp_unslash($_GET['order'] ?? 'desc'));
        $args['orderby'] = $orderby;
        $args['order']   = $order;

        $total_items = Bookflow_Booking::count($args);

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
        $this->items = Bookflow_Booking::query($args);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ]);
    }
}

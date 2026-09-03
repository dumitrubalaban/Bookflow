<?php
/**
 * Admin Reports View
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Admin_Reports {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_submenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function add_submenu() {
        add_submenu_page(
            'bookflow-bookings',
            Bookflow_I18n::t('admin.reports'),
            Bookflow_I18n::t('admin.reports'),
            'manage_woocommerce',
            'bookflow-reports',
            [$this, 'render_page']
        );
    }

    public function enqueue($hook) {
        if (strpos($hook, 'bookflow-reports') === false) {
            return;
        }

        $bundle_js = BOOKFLOW_PLUGIN_DIR . 'admin/dist/admin-reports.js';
        $bundle_css = BOOKFLOW_PLUGIN_DIR . 'admin/dist/admin-reports.css';
        if (!file_exists($bundle_js)) {
            return;
        }

        if (file_exists($bundle_css)) {
            wp_enqueue_style('bookflow-admin-reports', BOOKFLOW_PLUGIN_URL . 'admin/dist/admin-reports.css', [], BOOKFLOW_VERSION);
        }
        wp_enqueue_script('bookflow-admin-reports', BOOKFLOW_PLUGIN_URL . 'admin/dist/admin-reports.js', [], BOOKFLOW_VERSION, true);

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

        wp_localize_script('bookflow-admin-reports', 'bookflowAdminReports', [
            'restUrl'   => esc_url_raw(rest_url('bookflow/v1/')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'currency'  => get_woocommerce_currency_symbol(),
            'products'  => $products,
            'i18n'      => [
                'reports'         => Bookflow_I18n::t('admin.reports'),
                'reportsDesc'     => Bookflow_I18n::t('admin.reports_desc'),
                'totalRevenue'    => Bookflow_I18n::t('admin.total_revenue'),
                'totalBookings'   => Bookflow_I18n::t('admin.total_bookings'),
                'totalPersons'    => Bookflow_I18n::t('admin.total_persons'),
                'avgBookingValue' => Bookflow_I18n::t('admin.avg_booking_value'),
                'revenueByDay'    => Bookflow_I18n::t('admin.revenue_by_day'),
                'topProducts'     => Bookflow_I18n::t('admin.top_products'),
                'bookingsByStatus'=> Bookflow_I18n::t('admin.bookings_by_status'),
                'noData'          => Bookflow_I18n::t('admin.no_data'),
                'last7Days'       => Bookflow_I18n::t('admin.last_7_days'),
                'last30Days'      => Bookflow_I18n::t('admin.last_30_days'),
                'thisMonth'       => Bookflow_I18n::t('admin.this_month'),
                'thisYear'        => Bookflow_I18n::t('admin.this_year'),
                'allTime'         => Bookflow_I18n::t('admin.all_time'),
                'allProducts'     => Bookflow_I18n::t('admin.all_products'),
                'errorGeneric'    => Bookflow_I18n::t('calendar.error_generic'),
                'loading'         => Bookflow_I18n::t('calendar.loading'),
                'statusPending'        => Bookflow_I18n::t('status.pending'),
                'statusConfirmed'      => Bookflow_I18n::t('status.confirmed'),
                'statusPartiallyPaid'  => Bookflow_I18n::t('status.partially_paid'),
                'statusPaid'           => Bookflow_I18n::t('status.paid'),
                'statusInProgress'     => Bookflow_I18n::t('status.in_progress'),
                'statusCompleted'      => Bookflow_I18n::t('status.completed'),
                'statusCancelled'      => Bookflow_I18n::t('status.cancelled'),
                'statusRefunded'       => Bookflow_I18n::t('status.refunded'),
                'statusNoShow'         => Bookflow_I18n::t('status.no_show'),
            ],
        ]);
    }

    public function render_page() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php Bookflow_I18n::te('admin.reports'); ?></h1>
            <hr class="wp-header-end">
            <div id="bookflow-admin-reports-root" style="margin-top:16px;">
                <?php if (!file_exists(BOOKFLOW_PLUGIN_DIR . 'admin/dist/admin-reports.js')) : ?>
                    <p><em>Run <code>npm run build</code> in <code>svelte-src/</code> to build the reports view.</em></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

<?php
/**
 * Admin Calendar View
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Admin_Calendar {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_submenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function add_submenu() {
        add_submenu_page(
            'bookflow-bookings',
            Bookflow_I18n::t('admin.booking_calendar'),
            Bookflow_I18n::t('admin.booking_calendar'),
            'manage_woocommerce',
            'bookflow-calendar',
            [$this, 'render_page']
        );
    }

    public function enqueue($hook) {
        if (strpos($hook, 'bookflow-calendar') === false) {
            return;
        }

        $bundle_js = BOOKFLOW_PLUGIN_DIR . 'admin/dist/admin-calendar.js';
        $bundle_css = BOOKFLOW_PLUGIN_DIR . 'admin/dist/admin-calendar.css';
        if (!file_exists($bundle_js)) {
            return;
        }

        if (file_exists($bundle_css)) {
            wp_enqueue_style('bookflow-admin-calendar', BOOKFLOW_PLUGIN_URL . 'admin/dist/admin-calendar.css', [], BOOKFLOW_VERSION);
        }
        wp_enqueue_script('bookflow-admin-calendar', BOOKFLOW_PLUGIN_URL . 'admin/dist/admin-calendar.js', [], BOOKFLOW_VERSION, true);

        $month_names = [];
        for ($i = 1; $i <= 12; $i++) {
            $month_names[] = Bookflow_I18n::t('calendar.month.' . $i);
        }
        $weekdays = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $d) {
            $weekdays[] = Bookflow_I18n::t('calendar.weekday.' . $d);
        }

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

        wp_localize_script('bookflow-admin-calendar', 'bookflowAdminCalendar', [
            'restUrl'        => esc_url_raw(rest_url('bookflow/v1/')),
            'restNonce'      => wp_create_nonce('wp_rest'),
            'year'           => (int) gmdate('Y'),
            'month'          => (int) gmdate('m'),
            'months'         => $month_names,
            'weekdays'       => $weekdays,
            'statusLabels'   => Bookflow_I18n::statuses(),
            'products'       => $products,
            'bookingsListUrl' => admin_url('admin.php?page=bookflow-bookings'),
            'i18n'           => [
                'today'      => Bookflow_I18n::t('admin.today'),
                'more'       => Bookflow_I18n::t('admin.more'),
                'persons'    => rtrim(Bookflow_I18n::t('form.persons'), ':'),
                'person'     => Bookflow_I18n::t('admin.person_singular'),
                'status'     => Bookflow_I18n::t('admin.status'),
                'notes'      => Bookflow_I18n::t('form.notes_optional'),
                'viewFull'   => Bookflow_I18n::t('admin.view'),
                'newBooking' => Bookflow_I18n::t('admin.new_booking'),
                'product'    => Bookflow_I18n::t('admin.product'),
                'selectProduct' => Bookflow_I18n::t('admin.select_product'),
                'date'       => Bookflow_I18n::t('email.label.date'),
                'time'       => Bookflow_I18n::t('email.label.time'),
                'fullName'   => Bookflow_I18n::t('form.full_name'),
                'phone'      => Bookflow_I18n::t('form.phone'),
                'email'      => Bookflow_I18n::t('admin.email') ?: 'Email',
                'save'       => Bookflow_I18n::t('admin.save'),
                'cancel'     => Bookflow_I18n::t('admin.cancel_edit'),
                'errorGeneric' => Bookflow_I18n::t('calendar.error_generic'),
                'monthView'    => Bookflow_I18n::t('admin.month_view'),
                'weekView'     => Bookflow_I18n::t('admin.week_view'),
                'dragToCreateHelp' => Bookflow_I18n::t('admin.drag_to_create_help'),
            ],
        ]);
    }

    public function render_page() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php Bookflow_I18n::te('admin.booking_calendar'); ?></h1>
            <hr class="wp-header-end">
            <div id="bookflow-admin-calendar-root" style="margin-top:16px;">
                <?php if (!file_exists(BOOKFLOW_PLUGIN_DIR . 'admin/dist/admin-calendar.js')) : ?>
                    <p><em>Run <code>npm run build</code> in <code>svelte-src/</code> to build the calendar view.</em></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

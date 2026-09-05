<?php
/**
 * Plugin Name: Bookflow
 * Description: Enterprise booking system for daily bookable products. Adds bookable product type to WooCommerce with calendar, time slots, resources, person types, pricing rules, availability management, REST API, and full booking lifecycle.
 * Version: 1.0.0
 * Author: Balaban Dumitru
 * Text Domain: bookflow
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 10.6
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BOOKFLOW_VERSION', '1.0.0');
define('BOOKFLOW_DB_VERSION', '1.14.0');
define('BOOKFLOW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BOOKFLOW_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BOOKFLOW_PLUGIN_FILE', __FILE__);

// Valid booking statuses
define('BOOKFLOW_STATUSES', [
    'pending'       => 'Pending',
    'confirmed'     => 'Confirmed',
    'paid'          => 'Paid',
    'in-progress'   => 'In Progress',
    'completed'     => 'Completed',
    'cancelled'     => 'Cancelled',
    'refunded'      => 'Refunded',
    'no-show'       => 'No Show',
    'rejected'      => 'Rejected',
]);

// Status transitions: status => [allowed next statuses]
define('BOOKFLOW_STATUS_TRANSITIONS', [
    'pending'         => ['confirmed', 'rejected', 'cancelled', 'paid', 'partially-paid'],
    'confirmed'       => ['paid', 'cancelled', 'no-show', 'partially-paid'],
    'partially-paid'  => ['paid', 'confirmed', 'cancelled', 'refunded'],
    'paid'            => ['confirmed', 'in-progress', 'cancelled', 'refunded'],
    'in-progress'     => ['completed', 'cancelled'],
    'completed'       => ['refunded'],
    'cancelled'       => ['pending'],
    'refunded'        => [],
    'no-show'         => ['refunded'],
    'rejected'        => ['pending'],
]);

/**
 * Check if WooCommerce is active
 */
function bookflow_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>Bookflow</strong> requires WooCommerce to be installed and active.</p></div>';
        });
        return false;
    }
    return true;
}

/**
 * Initialize plugin
 */
function bookflow_init() {
    if (!bookflow_check_woocommerce()) {
        return;
    }

    // Check for DB migrations
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-install.php';
    Bookflow_Install::maybe_migrate();

    // i18n — load class early, re-init later when URL language is resolved
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-i18n.php';
    Bookflow_I18n::init();
    add_action('after_setup_theme', [Bookflow_I18n::class, 'init'], 1);
    add_action('wp', [Bookflow_I18n::class, 'init'], 1); // re-init after URL is parsed (for per-page locale)

    // Core includes
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-logger.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-cache.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-rate-limit.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-spam.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-product-type.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-booking.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-resources.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-extras.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-vouchers.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-ratings.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-abandoned.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-waitlist.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-webhooks.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-credits.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-resource-pins.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-booking-resources.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-customer-flags.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-booking-notes.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-customer-documents.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-locations.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-widgets.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-schedules.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-person-types.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-availability.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-pricing.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-cart.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-ajax.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-emails.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-cron.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-my-bookings.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-ical.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-rest-api.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-pingme.php';
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-google-calendar.php';

    // Admin
    // Submenu order under the top-level "Bookflow" menu follows the order
    // these classes are instantiated here — each hooks its own
    // add_submenu_page() call onto 'admin_menu', and same-priority hooks
    // fire in registration order. Grouped by how often a merchant actually
    // reaches for each page: daily-use views first (Calendar, the Widget
    // Builder), then insight (Reports), then the setup/config entities a
    // widget's product depends on, then integrations, then the
    // least-frequently-touched utilities (Export/Import) last.
    if (is_admin()) {
        require_once BOOKFLOW_PLUGIN_DIR . 'admin/class-bookflow-bookings-list-table.php';
        require_once BOOKFLOW_PLUGIN_DIR . 'admin/class-bookflow-widgets-list-table.php';
        require_once BOOKFLOW_PLUGIN_DIR . 'admin/class-bookflow-admin.php';
        require_once BOOKFLOW_PLUGIN_DIR . 'admin/class-bookflow-admin-calendar.php';
        require_once BOOKFLOW_PLUGIN_DIR . 'admin/class-bookflow-admin-reports.php';
        new Bookflow_Admin();
        new Bookflow_Admin_Calendar();
    }
    // Unlike Resources/Locations/Schedules/Extras below (admin_menu +
    // wp_ajax_ only — safe to gate behind is_admin()), Bookflow_Widgets
    // also registers the `[bookflow_widget]` shortcode and the
    // bookflow_booking_created/status_changed hooks that fire real
    // webhooks during checkout — both needed on ordinary frontend/REST
    // requests, so this must construct unconditionally. Positioned here,
    // between Admin_Calendar and Admin_Reports' own construction, purely
    // so its submenu item lands in the right position in the sidebar when
    // a request IS in wp-admin.
    new Bookflow_Widgets();
    if (is_admin()) {
        new Bookflow_Admin_Reports();
        new Bookflow_Resources();
        new Bookflow_Locations();
        new Bookflow_Schedules();
        new Bookflow_Extras();
    }

    // Frontend
    require_once BOOKFLOW_PLUGIN_DIR . 'public/class-bookflow-frontend.php';

    // Initialize
    Bookflow_Logger::init();
    Bookflow_Cache::init();
    new Bookflow_Product_Type();
    new Bookflow_Vouchers();
    new Bookflow_Ratings();
    new Bookflow_Abandoned();
    new Bookflow_Waitlist();
    new Bookflow_Webhooks();
    new Bookflow_Credits();
    new Bookflow_Resource_Pins();
    new Bookflow_Customer_Flags();
    new Bookflow_Person_Types();
    new Bookflow_Cart();
    new Bookflow_Ajax();
    new Bookflow_Emails();
    new Bookflow_Cron();
    new Bookflow_My_Bookings();
    new Bookflow_Frontend();
    Bookflow_iCal::init();
    Bookflow_PingMe::init();
    Bookflow_Google_Calendar::init();

    // Export/Import: least-frequently-used admin utilities, deliberately
    // registered last so their submenu items land at the bottom.
    if (is_admin()) {
        require_once BOOKFLOW_PLUGIN_DIR . 'admin/class-bookflow-export.php';
        require_once BOOKFLOW_PLUGIN_DIR . 'admin/class-bookflow-import.php';
        new Bookflow_Export();
        new Bookflow_Import();
    }

    // REST API loads on rest_api_init
    add_action('rest_api_init', function () {
        $api = new Bookflow_REST_API();
        $api->register_routes();
    });
}
add_action('plugins_loaded', 'bookflow_init');

/**
 * Create/update database tables
 */
function bookflow_activate() {
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-install.php';
    Bookflow_Install::create_tables();
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-cron.php';
    Bookflow_Cron::schedule_events();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'bookflow_activate');

/**
 * Cleanup on deactivation
 */
function bookflow_deactivate() {
    require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-cron.php';
    Bookflow_Cron::clear_events();
}
register_deactivation_hook(__FILE__, 'bookflow_deactivate');

/**
 * HPOS compatibility
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Register WC product type class on classmap
 */
add_filter('woocommerce_product_class', function ($classname, $product_type) {
    if ($product_type === 'booking') {
        return 'WC_Product_Booking';
    }
    return $classname;
}, 10, 2);

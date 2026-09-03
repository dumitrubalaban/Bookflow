<?php
/**
 * Database schema: table creation and migrations.
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Install {

    /**
     * Create (or, via dbDelta, non-destructively update) all Bookflow tables.
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Bookings table
        dbDelta("CREATE TABLE {$wpdb->prefix}bookflow_bookings (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            resource_id bigint(20) unsigned DEFAULT NULL,
            schedule_id bigint(20) unsigned DEFAULT NULL,
            order_id bigint(20) unsigned DEFAULT NULL,
            customer_id bigint(20) unsigned DEFAULT NULL,
            parent_id bigint(20) unsigned DEFAULT NULL,
            booking_date date NOT NULL,
            start_time varchar(10) NOT NULL,
            end_time varchar(10) DEFAULT NULL,
            all_day tinyint(1) NOT NULL DEFAULT 0,
            persons_total int(11) NOT NULL DEFAULT 1,
            cost decimal(12,2) NOT NULL DEFAULT 0.00,
            full_total decimal(12,2) NOT NULL DEFAULT 0.00,
            deposit_amount decimal(12,2) NOT NULL DEFAULT 0.00,
            status varchar(20) NOT NULL DEFAULT 'pending',
            customer_name varchar(255) DEFAULT NULL,
            customer_email varchar(255) DEFAULT NULL,
            customer_phone varchar(50) DEFAULT NULL,
            customer_locale varchar(10) DEFAULT NULL,
            notes text DEFAULT NULL,
            internal_notes text DEFAULT NULL,
            cancellation_reason text DEFAULT NULL,
            google_calendar_event_id varchar(255) DEFAULT NULL,
            rating_token varchar(64) DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent varchar(500) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            confirmed_at datetime DEFAULT NULL,
            cancelled_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            deleted_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY resource_id (resource_id),
            KEY schedule_id (schedule_id),
            KEY order_id (order_id),
            KEY customer_id (customer_id),
            KEY booking_date (booking_date),
            KEY status (status),
            KEY start_time (start_time),
            KEY parent_id (parent_id),
            KEY deleted_at (deleted_at),
            KEY date_status (booking_date, status),
            KEY product_date_status (product_id, booking_date, status)
        ) $charset_collate;");

        // Booking person types (for multi-type bookings: adults, children, etc.)
        dbDelta("CREATE TABLE {$wpdb->prefix}bookflow_booking_persons (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) unsigned NOT NULL,
            person_type_id bigint(20) unsigned NOT NULL,
            quantity int(11) NOT NULL DEFAULT 0,
            cost_per_person decimal(12,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (id),
            KEY booking_id (booking_id),
            KEY person_type_id (person_type_id)
        ) $charset_collate;");

        // Resources (rooms, guides, vehicles, etc.)
        dbDelta("CREATE TABLE {$wpdb->prefix}bookflow_resources (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            description text DEFAULT NULL,
            capacity int(11) NOT NULL DEFAULT 0,
            sort_order int(11) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            meta longtext DEFAULT NULL,
            avg_rating decimal(3,2) NOT NULL DEFAULT 0.00,
            rating_count int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status)
        ) $charset_collate;");

        // Guide/resource ratings (one per booking, left by the customer after
        // their tour completes) — avg_rating/rating_count on bookflow_resources
        // above are denormalized from this table for fast reads on the wizard's
        // Staff step.
        dbDelta("CREATE TABLE {$wpdb->prefix}bookflow_resource_ratings (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            resource_id bigint(20) unsigned NOT NULL,
            booking_id bigint(20) unsigned NOT NULL,
            rating tinyint(1) unsigned NOT NULL,
            comment text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY booking_id (booking_id),
            KEY resource_id (resource_id)
        ) $charset_collate;");

        // Product-resource assignments
        dbDelta("CREATE TABLE {$wpdb->prefix}bookflow_product_resources (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            resource_id bigint(20) unsigned NOT NULL,
            base_cost decimal(12,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (id),
            UNIQUE KEY product_resource (product_id, resource_id),
            KEY product_id (product_id),
            KEY resource_id (resource_id)
        ) $charset_collate;");

        // Person types (adults, children, seniors, etc.)
        dbDelta("CREATE TABLE {$wpdb->prefix}bookflow_person_types (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            name varchar(255) NOT NULL,
            description varchar(500) DEFAULT NULL,
            cost decimal(12,2) NOT NULL DEFAULT 0.00,
            min_qty int(11) NOT NULL DEFAULT 0,
            max_qty int(11) NOT NULL DEFAULT 0,
            sort_order int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY product_id (product_id)
        ) $charset_collate;");

        // Availability rules (complex rules per product)
        dbDelta("CREATE TABLE {$wpdb->prefix}bookflow_availability_rules (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            resource_id bigint(20) unsigned DEFAULT NULL,
            rule_type varchar(30) NOT NULL,
            from_date date DEFAULT NULL,
            to_date date DEFAULT NULL,
            from_time varchar(10) DEFAULT NULL,
            to_time varchar(10) DEFAULT NULL,
            day_of_week varchar(50) DEFAULT NULL,
            bookable tinyint(1) NOT NULL DEFAULT 1,
            priority int(11) NOT NULL DEFAULT 10,
            sort_order int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY resource_id (resource_id),
            KEY rule_type (rule_type)
        ) $charset_collate;");

        // Pricing rules (complex cost adjustments)
        dbDelta("CREATE TABLE {$wpdb->prefix}bookflow_pricing_rules (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            rule_type varchar(30) NOT NULL,
            label varchar(255) DEFAULT NULL,
            from_date date DEFAULT NULL,
            to_date date DEFAULT NULL,
            from_time varchar(10) DEFAULT NULL,
            to_time varchar(10) DEFAULT NULL,
            day_of_week varchar(50) DEFAULT NULL,
            modifier_type varchar(20) NOT NULL DEFAULT 'fixed',
            modifier_amount decimal(12,2) NOT NULL DEFAULT 0.00,
            priority int(11) NOT NULL DEFAULT 10,
            sort_order int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY rule_type (rule_type)
        ) $charset_collate;");

        // Product schedules (language-based or other option-based scheduling)
        dbDelta("CREATE TABLE {$wpdb->prefix}bookflow_product_schedules (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            option_group varchar(50) NOT NULL DEFAULT 'language',
            option_label varchar(255) NOT NULL,
            option_value varchar(100) NOT NULL,
            available_days text DEFAULT NULL,
            time_slots text DEFAULT NULL,
            max_persons int(11) NOT NULL DEFAULT 0,
            max_bookings_per_slot int(11) NOT NULL DEFAULT 0,
            price_modifier decimal(12,2) NOT NULL DEFAULT 0.00,
            sort_order int(11) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY option_group (option_group),
            KEY product_group (product_id, option_group),
            KEY status (status)
        ) $charset_collate;");

        // Locations (first-class entity: own working days/blocked dates/holidays,
        // replacing product_tag term-meta as the source of truth for scheduling;
        // the tag itself is kept in sync for the existing /services?location=
        // catalog filter, not used for availability logic any more)
        dbDelta("CREATE TABLE {$wpdb->prefix}bookflow_locations (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            term_id bigint(20) unsigned DEFAULT NULL,
            address varchar(500) DEFAULT NULL,
            lat varchar(30) DEFAULT NULL,
            lng varchar(30) DEFAULT NULL,
            available_days text DEFAULT NULL,
            blocked_dates text DEFAULT NULL,
            holidays text DEFAULT NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY term_id (term_id),
            KEY status (status)
        ) $charset_collate;");

        // Extras (cart-level upsells, e.g. souvenir bottle, tasting kit)
        dbDelta("CREATE TABLE {$wpdb->prefix}bookflow_extras (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            description varchar(500) DEFAULT NULL,
            price decimal(12,2) NOT NULL DEFAULT 0.00,
            sort_order int(11) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status)
        ) $charset_collate;");

        // Abandoned bookings (partial contact info captured before checkout,
        // for a "still interested?" follow-up email)
        dbDelta("CREATE TABLE {$wpdb->prefix}bookflow_abandoned_bookings (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            email varchar(255) DEFAULT NULL,
            phone varchar(50) DEFAULT NULL,
            name varchar(255) DEFAULT NULL,
            step_reached varchar(30) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            followup_sent_at datetime DEFAULT NULL,
            recovered tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY email (email),
            KEY phone (phone),
            KEY recovered (recovered)
        ) $charset_collate;");

        // Waiting list — customers who asked to be notified when a full slot
        // opens back up (via cancellation).
        dbDelta("CREATE TABLE {$wpdb->prefix}bookflow_waitlist (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            resource_id bigint(20) unsigned DEFAULT NULL,
            schedule_id bigint(20) unsigned DEFAULT NULL,
            booking_date date NOT NULL,
            start_time time NOT NULL,
            persons_requested int(11) NOT NULL DEFAULT 1,
            customer_name varchar(255) NOT NULL,
            customer_email varchar(255) NOT NULL,
            customer_phone varchar(50) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'waiting',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            notified_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY slot_lookup (product_id, booking_date, start_time, status),
            KEY status (status)
        ) $charset_collate;");

        // Audit log
        dbDelta("CREATE TABLE {$wpdb->prefix}bookflow_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) unsigned DEFAULT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            action varchar(100) NOT NULL,
            old_value text DEFAULT NULL,
            new_value text DEFAULT NULL,
            note text DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY booking_id (booking_id),
            KEY action (action),
            KEY created_at (created_at)
        ) $charset_collate;");

        // Widget Builder: reusable widget presets (style + wizard step flow),
        // assignable per-product so different products can use different
        // looks/flows instead of one hardcoded global widget style.
        dbDelta("CREATE TABLE {$wpdb->prefix}bookflow_widgets (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            product_id bigint(20) unsigned DEFAULT NULL,
            style text DEFAULT NULL,
            steps text DEFAULT NULL,
            text longtext DEFAULT NULL,
            webhook_url varchar(500) DEFAULT NULL,
            is_default tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY is_default (is_default)
        ) $charset_collate;");

        update_option('bookflow_db_version', BOOKFLOW_DB_VERSION);
    }

    /**
     * Run schema migrations if the stored DB version is behind the plugin's.
     */
    public static function maybe_migrate() {
        $current_version = get_option('bookflow_db_version', '0');
        if (version_compare($current_version, BOOKFLOW_DB_VERSION, '<')) {
            self::create_tables();
            // Re-run (idempotent: schedule_events() only touches jobs that
            // aren't already scheduled) so new cron jobs added in a later
            // version reach sites that installed before them, without
            // requiring a deactivate/reactivate. This runs before the main
            // includes block, so load the class explicitly.
            require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-abandoned.php';
            require_once BOOKFLOW_PLUGIN_DIR . 'includes/class-bookflow-cron.php';
            Bookflow_Cron::schedule_events();
        }
    }
}

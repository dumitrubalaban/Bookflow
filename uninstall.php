<?php
/**
 * Fired when the plugin is uninstalled (deleted).
 *
 * @package Bookflow
 */

// Exit if not called by WordPress uninstall process.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Drop all custom tables.
$tables = [ // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- local variable, not a global
    'bookflow_bookings',
    'bookflow_booking_persons',
    'bookflow_resources',
    'bookflow_product_resources',
    'bookflow_person_types',
    'bookflow_availability_rules',
    'bookflow_pricing_rules',
    'bookflow_product_schedules',
    'bookflow_log',
];

foreach ($tables as $table) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- local variable, not a global
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- custom table, no core API exists; $table is one of the fixed literal strings above, never user input; uninstall.php's whole purpose is dropping this plugin's own tables
}

// Delete all plugin options.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bookflow\_%'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity

// Delete all plugin post meta.
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_bookflow\_%'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity

// Delete all plugin transients.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bookflow\_%' OR option_name LIKE '_transient_timeout_bookflow\_%'"
);

// Clear scheduled cron events.
$cron_hooks = [ // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- local variable, not a global
    'bookflow_cron_send_reminders',
    'bookflow_cron_auto_complete',
    'bookflow_cron_cleanup_pending',
    'bookflow_cron_mark_no_show',
];

foreach ($cron_hooks as $hook) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- local variable, not a global
    wp_clear_scheduled_hook($hook);
}

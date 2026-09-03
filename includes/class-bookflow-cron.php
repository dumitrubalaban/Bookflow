<?php
/**
 * Scheduled Tasks (Reminders, Cleanup, Auto-completion)
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Cron {

    public function __construct() {
        add_action('bookflow_cron_send_reminders', [__CLASS__, 'send_reminders']);
        add_action('bookflow_cron_auto_complete', [__CLASS__, 'auto_complete_bookings']);
        add_action('bookflow_cron_cleanup_pending', [__CLASS__, 'cleanup_stale_pending']);
        add_action('bookflow_cron_mark_no_show', [__CLASS__, 'mark_no_shows']);
        add_action('bookflow_cron_abandoned_followup', [Bookflow_Abandoned::class, 'send_followups']);
    }

    /**
     * Schedule cron events
     */
    public static function schedule_events() {
        if (!wp_next_scheduled('bookflow_cron_send_reminders')) {
            wp_schedule_event(time(), 'hourly', 'bookflow_cron_send_reminders');
        }
        if (!wp_next_scheduled('bookflow_cron_auto_complete')) {
            wp_schedule_event(time(), 'daily', 'bookflow_cron_auto_complete');
        }
        if (!wp_next_scheduled('bookflow_cron_cleanup_pending')) {
            wp_schedule_event(time(), 'hourly', 'bookflow_cron_cleanup_pending');
        }
        if (!wp_next_scheduled('bookflow_cron_mark_no_show')) {
            wp_schedule_event(time(), 'daily', 'bookflow_cron_mark_no_show');
        }
        if (!wp_next_scheduled('bookflow_cron_abandoned_followup')) {
            wp_schedule_event(time(), 'hourly', 'bookflow_cron_abandoned_followup');
        }
    }

    /**
     * Clear all cron events
     */
    public static function clear_events() {
        wp_clear_scheduled_hook('bookflow_cron_send_reminders');
        wp_clear_scheduled_hook('bookflow_cron_auto_complete');
        wp_clear_scheduled_hook('bookflow_cron_cleanup_pending');
        wp_clear_scheduled_hook('bookflow_cron_mark_no_show');
        wp_clear_scheduled_hook('bookflow_cron_abandoned_followup');
    }

    /**
     * Send reminder emails 24h before booking
     */
    public static function send_reminders() {
        $tomorrow = wp_date('Y-m-d', strtotime('+1 day'));

        $bookings = Bookflow_Booking::query([
            'date'   => $tomorrow,
            'status' => ['confirmed', 'paid'],
            'limit'  => 100,
        ]);

        foreach ($bookings as $booking) {
            if (!empty($booking->internal_notes) && strpos($booking->internal_notes, '[REMINDER_SENT:') !== false) {
                continue;
            }

            do_action('bookflow_send_reminder_email', $booking);

            // Mark as sent using the bookings table internal_notes (no meta table)
            global $wpdb;
            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
                $wpdb->prefix . 'bookflow_bookings',
                ['internal_notes' => trim(($booking->internal_notes ?? '') . "\n[REMINDER_SENT:" . wp_date('Y-m-d H:i:s') . ']')],
                ['id' => $booking->id]
            );

            Bookflow_Logger::log('reminder_sent', $booking->id);
        }
    }

    /**
     * Auto-complete bookings that have passed
     */
    public static function auto_complete_bookings() {
        $yesterday = wp_date('Y-m-d', strtotime('-1 day'));

        $bookings = Bookflow_Booking::query([
            'date_to' => $yesterday,
            'status'  => ['confirmed', 'paid', 'in-progress'],
            'limit'   => 200,
        ]);

        foreach ($bookings as $booking) {
            Bookflow_Booking::transition_status($booking->id, 'completed', 'Auto-completed by system');
        }
    }

    /**
     * Cancel stale pending bookings (no payment after 30 minutes)
     */
    public static function cleanup_stale_pending() {
        global $wpdb;
        $cutoff = wp_date('Y-m-d H:i:s', strtotime('-30 minutes'));

        $stale = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists; live data required for booking/availability integrity
            "SELECT id FROM {$wpdb->prefix}bookflow_bookings WHERE status = 'pending' AND order_id IS NULL AND created_at < %s",
            $cutoff
        ));

        foreach ($stale as $booking) {
            Bookflow_Booking::transition_status($booking->id, 'cancelled', 'Auto-cancelled: no payment received');
        }
    }

    /**
     * Mark no-shows for past bookings that were confirmed but not completed
     */
    public static function mark_no_shows() {
        $two_days_ago = wp_date('Y-m-d', strtotime('-2 days'));

        $bookings = Bookflow_Booking::query([
            'date_to' => $two_days_ago,
            'status'  => 'confirmed',
            'limit'   => 200,
        ]);

        foreach ($bookings as $booking) {
            Bookflow_Booking::transition_status($booking->id, 'no-show', 'Auto-marked as no-show by system');
        }
    }
}

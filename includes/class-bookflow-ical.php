<?php
/**
 * iCalendar (.ics) export & subscribe feed.
 *
 * - Subscribe feed: /wp-json/bookflow/v1/ical?token=XXX  → all bookings, subscribable in
 *   Google Calendar / Apple / Outlook (one-way: bookings → calendar, no OAuth needed).
 * - Single booking: /wp-json/bookflow/v1/bookings/{id}/ical → one .ics ("Add to calendar").
 * - Confirmation emails get the booking attached as an .ics file.
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_iCal {

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        // Attach .ics to outgoing booking emails
        add_filter('bookflow_email_attachments', [__CLASS__, 'email_attachment'], 10, 2);
        // Show the subscribe URL on the bookings admin page
        add_action('admin_notices', [__CLASS__, 'admin_panel']);
        add_action('admin_post_bookflow_regen_ical', [__CLASS__, 'handle_regen']);
    }

    /**
     * "Calendar sync" panel on the Bookflow bookings admin page.
     */
    public static function admin_panel() {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'bookflow-bookings') === false) {
            return;
        }
        $url = self::feed_url();
        $regen = wp_nonce_url(admin_url('admin-post.php?action=bookflow_regen_ical'), 'bookflow_regen_ical');
        ?>
        <div class="notice notice-info" style="padding:14px 16px;">
            <p style="margin:0 0 6px;"><strong>📅 Calendar sync</strong> — subscribe in Google Calendar, Apple Calendar or Outlook to see every booking automatically.</p>
            <input type="text" readonly value="<?php echo esc_attr($url); ?>" onclick="this.select()" style="width:100%;max-width:640px;font-family:monospace;font-size:12px;padding:6px 8px;" />
            <p style="margin:6px 0 0;font-size:12px;color:#666;">
                Google Calendar → <em>Other calendars → From URL</em> → paste this link.
                &nbsp;·&nbsp; <a href="<?php echo esc_url($regen); ?>" onclick="return confirm('Regenerate the feed link? The old link will stop working.');">Regenerate link</a>
            </p>
        </div>
        <?php
    }

    public static function handle_regen() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('bookflow_regen_ical')) {
            wp_die('Not allowed');
        }
        self::regenerate_token();
        wp_safe_redirect(admin_url('admin.php?page=bookflow-bookings'));
        exit;
    }

    /**
     * Get (or create) the secret token used to protect the subscribe feed.
     */
    public static function get_token() {
        $token = get_option('bookflow_ical_token');
        if (!$token) {
            $token = wp_generate_password(32, false, false);
            update_option('bookflow_ical_token', $token);
        }
        return $token;
    }

    public static function regenerate_token() {
        $token = wp_generate_password(32, false, false);
        update_option('bookflow_ical_token', $token);
        return $token;
    }

    /**
     * Public subscribe URL (feed) for the admin to paste into their calendar app.
     */
    public static function feed_url() {
        return add_query_arg('token', self::get_token(), rest_url('bookflow/v1/ical'));
    }

    public static function register_routes() {
        register_rest_route('bookflow/v1', '/ical', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'feed'],
            'permission_callback' => '__return_true', // protected by token param
        ]);
        register_rest_route('bookflow/v1', '/bookings/(?P<id>\d+)/ical', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'single'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Subscribe feed — all upcoming + recent bookings.
     */
    public static function feed($request) {
        $token = $request->get_param('token');
        if (!$token || !hash_equals(self::get_token(), (string) $token)) {
            return new WP_REST_Response('Invalid token', 403);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_bookings';
        // Everything from 30 days ago onwards, excluding cancelled/refunded
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table
             WHERE booking_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             AND status NOT IN ('cancelled', 'refunded')
             ORDER BY booking_date ASC, start_time ASC
             LIMIT 1000"
        ));

        $events = '';
        foreach ($rows as $b) {
            $events .= self::build_event($b);
        }

        return self::ics_response(self::wrap_calendar($events), 'bookings.ics', true);
    }

    /**
     * Single booking .ics (download / add to calendar).
     */
    public static function single($request) {
        $booking = Bookflow_Booking::get((int) $request['id']);
        if (!$booking) {
            return new WP_REST_Response('Not found', 404);
        }
        $ics = self::wrap_calendar(self::build_event($booking));
        return self::ics_response($ics, 'booking-' . $booking->id . '.ics', false);
    }

    /**
     * Build a VEVENT block for a booking.
     */
    public static function build_event($b) {
        $tz = wp_timezone();

        try {
            $start = new DateTime($b->booking_date . ' ' . $b->start_time, $tz);
        } catch (Exception $e) {
            return '';
        }
        $start->setTimezone(new DateTimeZone('UTC'));

        if (!empty($b->end_time)) {
            try {
                $end = new DateTime($b->booking_date . ' ' . $b->end_time, $tz);
            } catch (Exception $e) {
                $end = (clone $start);
                $end->modify('+1 hour');
            }
            $end->setTimezone(new DateTimeZone('UTC'));
        } else {
            $end = (clone $start);
            $end->modify('+1 hour');
        }

        $product_name = get_the_title($b->product_id) ?: 'Booking';
        $summary = sprintf('%s — %s', $product_name, $b->customer_name ?: ('#' . $b->id));

        $desc_parts = [];
        $desc_parts[] = 'Persons: ' . (int) $b->persons_total;
        $desc_parts[] = 'Status: ' . ucfirst($b->status);
        if (!empty($b->customer_email)) $desc_parts[] = 'Email: ' . $b->customer_email;
        if (!empty($b->customer_phone)) $desc_parts[] = 'Phone: ' . $b->customer_phone;
        if (!empty($b->order_id))       $desc_parts[] = 'Order: #' . $b->order_id;
        if (!empty($b->notes))          $desc_parts[] = 'Notes: ' . $b->notes;
        $description = implode("\n", $desc_parts);

        $host = parse_url(home_url(), PHP_URL_HOST) ?: 'bookflow';
        $status_map = [
            'pending' => 'TENTATIVE', 'confirmed' => 'CONFIRMED', 'paid' => 'CONFIRMED',
            'in-progress' => 'CONFIRMED', 'completed' => 'CONFIRMED',
            'cancelled' => 'CANCELLED', 'refunded' => 'CANCELLED', 'no-show' => 'CANCELLED',
        ];
        $vstatus = $status_map[$b->status] ?? 'CONFIRMED';

        $updated = !empty($b->updated_at) ? strtotime($b->updated_at . ' UTC') : time();

        $lines = [
            'BEGIN:VEVENT',
            'UID:bookflow-' . $b->id . '@' . $host,
            'DTSTAMP:' . gmdate('Ymd\THis\Z', $updated),
            'DTSTART:' . $start->format('Ymd\THis\Z'),
            'DTEND:' . $end->format('Ymd\THis\Z'),
            'SUMMARY:' . self::esc($summary),
            'DESCRIPTION:' . self::esc($description),
            'LOCATION:' . self::esc($product_name),
            'STATUS:' . $vstatus,
            'END:VEVENT',
        ];

        $out = '';
        foreach ($lines as $line) {
            $out .= self::fold($line) . "\r\n";
        }
        return $out;
    }

    /**
     * Wrap VEVENTs in a VCALENDAR.
     */
    public static function wrap_calendar($events) {
        $name = self::esc(get_bloginfo('name') . ' — Bookings');
        $cal = "BEGIN:VCALENDAR\r\n";
        $cal .= "VERSION:2.0\r\n";
        $cal .= "PRODID:-//Bookflow//EN\r\n";
        $cal .= "CALSCALE:GREGORIAN\r\n";
        $cal .= "METHOD:PUBLISH\r\n";
        $cal .= self::fold('X-WR-CALNAME:' . $name) . "\r\n";
        $cal .= $events;
        $cal .= "END:VCALENDAR\r\n";
        return $cal;
    }

    /**
     * Build a Response that streams the .ics with the right headers.
     */
    private static function ics_response($ics, $filename, $inline) {
        $disposition = $inline ? 'inline' : 'attachment';
        $response = new WP_REST_Response();
        $response->set_data($ics);
        $response->header('Content-Type', 'text/calendar; charset=utf-8');
        $response->header('Content-Disposition', $disposition . '; filename="' . $filename . '"');
        // Tell WP REST not to JSON-encode our string
        add_filter('rest_pre_serve_request', function ($served, $result) use ($ics) {
            if (!$served) {
                // Not HTML — this is a raw iCalendar (.ics) file body with
                // Content-Type: text/calendar. Escaping for HTML output here
                // would corrupt the ICS syntax. Field values are already
                // escaped per RFC 5545 rules at build time via self::esc().
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo $ics;
                return true;
            }
            return $served;
        }, 10, 2);
        return $response;
    }

    /**
     * Attach a single booking .ics to confirmation emails.
     * Hook: bookflow_email_attachments( array $files, object $booking )
     */
    public static function email_attachment($files, $booking) {
        if (empty($booking) || empty($booking->id)) {
            return $files;
        }
        $ics = self::wrap_calendar(self::build_event($booking));
        $tmp = trailingslashit(get_temp_dir()) . 'bookflow-booking-' . $booking->id . '.ics';
        if (file_put_contents($tmp, $ics) !== false) {
            $files[] = $tmp;
        }
        return $files;
    }

    /**
     * Escape a TEXT value per RFC 5545.
     */
    private static function esc($text) {
        $text = str_replace('\\', '\\\\', (string) $text);
        $text = str_replace(["\r\n", "\n", "\r"], '\\n', $text);
        $text = str_replace(',', '\\,', $text);
        $text = str_replace(';', '\\;', $text);
        return $text;
    }

    /**
     * Fold long lines at 75 octets per RFC 5545.
     */
    private static function fold($line) {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        while (strlen($line) > 75) {
            $out .= substr($line, 0, 75) . "\r\n ";
            $line = substr($line, 75);
        }
        return $out . $line;
    }
}

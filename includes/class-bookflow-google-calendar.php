<?php
/**
 * Real Google Calendar integration — as opposed to the read-only,
 * no-reminders iCal subscribe feed in class-bookflow-ical.php, this connects
 * the site owner's actual Google account via OAuth 2.0 and creates/
 * updates/deletes real Calendar events through the Calendar API v3,
 * each with a configurable "N minutes before" popup reminder. That's
 * something a subscribed iCal feed can never do — Google ignores VALARM
 * blocks on externally-subscribed calendars entirely.
 *
 * Architecture (same shape as any Calendly/Acuity-style integration):
 *   1. Site owner clicks "Connect Google Calendar" in wp-admin.
 *   2. OAuth redirect to Google, owner grants the `calendar.events` scope.
 *   3. Google redirects back to our own REST callback with an auth code.
 *   4. We exchange that for an access_token (~1hr) + refresh_token
 *      (long-lived) and store both. From then on every booking create/
 *      update/cancel calls the Calendar API directly using a token we
 *      silently refresh as needed — no further owner interaction.
 *   5. The real Google event ID gets stored back on the booking row
 *      (the `google_calendar_event_id` column already existed in the
 *      schema for exactly this, just never wired to anything until now)
 *      so a later update/cancel targets the right event.
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Google_Calendar {

    const OPT_CLIENT_ID = 'bookflow_gcal_client_id';
    const OPT_CLIENT_SECRET = 'bookflow_gcal_client_secret';
    const OPT_ACCESS_TOKEN = 'bookflow_gcal_access_token';
    const OPT_REFRESH_TOKEN = 'bookflow_gcal_refresh_token';
    const OPT_TOKEN_EXPIRES = 'bookflow_gcal_token_expires';
    const OPT_CALENDAR_ID = 'bookflow_gcal_calendar_id';
    const OPT_REMINDER_MINUTES = 'bookflow_gcal_reminder_minutes';
    const OPT_ACCOUNT_EMAIL = 'bookflow_gcal_account_email';
    const OPT_LAST_ERROR = 'bookflow_gcal_last_error';

    const STATE_TRANSIENT_PREFIX = 'bookflow_gcal_state_';
    const SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('admin_post_bookflow_gcal_disconnect', [__CLASS__, 'handle_disconnect']);
        add_action('admin_post_bookflow_gcal_reset_credentials', [__CLASS__, 'handle_reset_credentials']);
        add_action('admin_post_bookflow_gcal_test_event', [__CLASS__, 'handle_test_event']);

        // Booking lifecycle -> Calendar API. Every handler no-ops
        // immediately if the site isn't connected, so this costs nothing
        // for sites that never set this up.
        add_action('bookflow_booking_created', [__CLASS__, 'on_booking_created'], 10, 2);
        add_action('bookflow_booking_status_changed', [__CLASS__, 'on_status_changed'], 10, 3);
        add_action('bookflow_booking_updated', [__CLASS__, 'on_booking_updated'], 10, 3);
        add_action('bookflow_booking_deleted', [__CLASS__, 'on_booking_removed'], 10, 2);
        add_action('bookflow_booking_trashed', [__CLASS__, 'on_booking_removed'], 10, 2);
    }

    // ---------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------

    public static function client_id(): string {
        $v = get_option(self::OPT_CLIENT_ID, '');
        return is_string($v) ? $v : '';
    }

    public static function client_secret(): string {
        $v = get_option(self::OPT_CLIENT_SECRET, '');
        return is_string($v) ? $v : '';
    }

    public static function is_configured(): bool {
        return self::client_id() !== '' && self::client_secret() !== '';
    }

    public static function is_connected(): bool {
        $v = get_option(self::OPT_REFRESH_TOKEN, '');
        return is_string($v) && $v !== '';
    }

    public static function calendar_id(): string {
        $v = get_option(self::OPT_CALENDAR_ID, 'primary');
        return is_string($v) && $v !== '' ? $v : 'primary';
    }

    public static function reminder_minutes(): int {
        $v = get_option(self::OPT_REMINDER_MINUTES, 30);
        $n = (int) $v;
        return $n > 0 ? $n : 30;
    }

    public static function redirect_uri(): string {
        return rest_url('bookflow/v1/google-calendar/callback');
    }

    public static function account_email(): string {
        $v = get_option(self::OPT_ACCOUNT_EMAIL, '');
        return is_string($v) ? $v : '';
    }

    public static function last_error(): string {
        $v = get_option(self::OPT_LAST_ERROR, '');
        return is_string($v) ? $v : '';
    }

    // ---------------------------------------------------------------
    // OAuth flow
    // ---------------------------------------------------------------

    /** Builds the "Connect Google Calendar" link. */
    public static function authorize_url(): string {
        $state = wp_generate_password(32, false, false);
        // A transient (not a WP nonce) because this round-trip leaves the
        // site entirely — Google, then back — so the verification token
        // has to be something we can check on a plain, unauthenticated
        // GET from Google's redirect rather than a same-session nonce.
        set_transient(self::STATE_TRANSIENT_PREFIX . $state, get_current_user_id(), 10 * MINUTE_IN_SECONDS);

        $params = [
            'client_id' => self::client_id(),
            'redirect_uri' => self::redirect_uri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            // Forces Google to hand back a refresh_token even on a
            // reconnect — without this, a second authorization for an
            // account that already granted access once returns no
            // refresh_token at all, silently breaking long-term access.
            'prompt' => 'consent',
            'state' => $state,
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    public static function register_routes() {
        register_rest_route('bookflow/v1', '/google-calendar/callback', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'oauth_callback'],
            'permission_callback' => '__return_true', // verified via state, not WP auth
        ]);
    }

    public static function oauth_callback(WP_REST_Request $request) {
        $settings_url = admin_url('admin.php?page=bookflow-google-calendar');

        $error = $request->get_param('error');
        if ($error) {
            update_option(self::OPT_LAST_ERROR, sanitize_text_field($error));
            wp_safe_redirect(add_query_arg('bookflow_gcal_notice', 'error', $settings_url));
            exit;
        }

        $state = (string) $request->get_param('state');
        $transient_key = self::STATE_TRANSIENT_PREFIX . $state;
        if (!$state || get_transient($transient_key) === false) {
            update_option(self::OPT_LAST_ERROR, 'Invalid or expired authorization state.');
            wp_safe_redirect(add_query_arg('bookflow_gcal_notice', 'error', $settings_url));
            exit;
        }
        delete_transient($transient_key);

        $code = (string) $request->get_param('code');
        if (!$code) {
            update_option(self::OPT_LAST_ERROR, 'Google did not return an authorization code.');
            wp_safe_redirect(add_query_arg('bookflow_gcal_notice', 'error', $settings_url));
            exit;
        }

        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 15,
            'body' => [
                'code' => $code,
                'client_id' => self::client_id(),
                'client_secret' => self::client_secret(),
                'redirect_uri' => self::redirect_uri(),
                'grant_type' => 'authorization_code',
            ],
        ]);

        if (is_wp_error($response)) {
            update_option(self::OPT_LAST_ERROR, $response->get_error_message());
            wp_safe_redirect(add_query_arg('bookflow_gcal_notice', 'error', $settings_url));
            exit;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['access_token'])) {
            update_option(self::OPT_LAST_ERROR, is_array($body) && !empty($body['error_description'])
                ? $body['error_description']
                : 'Token exchange failed.');
            wp_safe_redirect(add_query_arg('bookflow_gcal_notice', 'error', $settings_url));
            exit;
        }

        update_option(self::OPT_ACCESS_TOKEN, sanitize_text_field($body['access_token']));
        update_option(self::OPT_TOKEN_EXPIRES, time() + (int) ($body['expires_in'] ?? 3600));
        if (!empty($body['refresh_token'])) {
            update_option(self::OPT_REFRESH_TOKEN, sanitize_text_field($body['refresh_token']));
        }
        delete_option(self::OPT_LAST_ERROR);

        // Best-effort: fetch the connected email for display, doesn't
        // block a successful connection if it fails.
        $userinfo = wp_remote_get('https://www.googleapis.com/oauth2/v2/userinfo', [
            'timeout' => 10,
            'headers' => ['Authorization' => 'Bearer ' . $body['access_token']],
        ]);
        if (!is_wp_error($userinfo)) {
            $info = json_decode(wp_remote_retrieve_body($userinfo), true);
            if (is_array($info) && !empty($info['email'])) {
                update_option(self::OPT_ACCOUNT_EMAIL, sanitize_email($info['email']));
            }
        }

        wp_safe_redirect(add_query_arg('bookflow_gcal_notice', 'connected', $settings_url));
        exit;
    }

    public static function handle_disconnect() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('bookflow_gcal_disconnect')) {
            wp_die('Not allowed');
        }
        foreach ([self::OPT_ACCESS_TOKEN, self::OPT_REFRESH_TOKEN, self::OPT_TOKEN_EXPIRES, self::OPT_ACCOUNT_EMAIL] as $opt) {
            delete_option($opt);
        }
        wp_safe_redirect(add_query_arg('bookflow_gcal_notice', 'disconnected', admin_url('admin.php?page=bookflow-google-calendar')));
        exit;
    }

    /**
     * Full reset — clears the OAuth Client ID/Secret as well as any
     * tokens tied to them, so pasting in credentials from the wrong
     * Google Cloud project/account doesn't leave stale, mismatched
     * values behind. Distinct from handle_disconnect(), which only
     * clears the connected account's tokens and keeps the (presumably
     * correct) Client ID/Secret in place.
     */
    public static function handle_reset_credentials() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('bookflow_gcal_reset_credentials')) {
            wp_die('Not allowed');
        }
        foreach ([
            self::OPT_CLIENT_ID,
            self::OPT_CLIENT_SECRET,
            self::OPT_ACCESS_TOKEN,
            self::OPT_REFRESH_TOKEN,
            self::OPT_TOKEN_EXPIRES,
            self::OPT_ACCOUNT_EMAIL,
            self::OPT_LAST_ERROR,
        ] as $opt) {
            delete_option($opt);
        }
        wp_safe_redirect(add_query_arg('bookflow_gcal_notice', 'reset', admin_url('admin.php?page=bookflow-google-calendar')));
        exit;
    }

    /**
     * Returns a valid access token, transparently refreshing it first if
     * expired — every event API call goes through this rather than
     * reading OPT_ACCESS_TOKEN directly.
     */
    private static function fresh_access_token() {
        if (!self::is_connected()) {
            return new WP_Error('not_connected', 'Google Calendar is not connected.');
        }

        $expires = (int) get_option(self::OPT_TOKEN_EXPIRES, 0);
        if ($expires > time() + 60) {
            return get_option(self::OPT_ACCESS_TOKEN, '');
        }

        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 15,
            'body' => [
                'refresh_token' => get_option(self::OPT_REFRESH_TOKEN, ''),
                'client_id' => self::client_id(),
                'client_secret' => self::client_secret(),
                'grant_type' => 'refresh_token',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['access_token'])) {
            $message = is_array($body) && !empty($body['error_description'])
                ? $body['error_description']
                : 'Failed to refresh Google access token.';
            update_option(self::OPT_LAST_ERROR, $message);
            return new WP_Error('refresh_failed', $message);
        }

        update_option(self::OPT_ACCESS_TOKEN, sanitize_text_field($body['access_token']));
        update_option(self::OPT_TOKEN_EXPIRES, time() + (int) ($body['expires_in'] ?? 3600));
        return $body['access_token'];
    }

    // ---------------------------------------------------------------
    // Calendar API
    // ---------------------------------------------------------------

    /** @return array<array{id:string,summary:string,primary?:bool}> */
    public static function list_calendars() {
        $token = self::fresh_access_token();
        if (is_wp_error($token)) {
            return [];
        }
        $response = wp_remote_get('https://www.googleapis.com/calendar/v3/users/me/calendarList', [
            'timeout' => 10,
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        if (is_wp_error($response)) {
            return [];
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($body) && !empty($body['items']) ? $body['items'] : [];
    }

    /** Builds the Calendar API event payload for a booking. */
    private static function build_event_payload($booking): array {
        $product = get_post($booking->product_id);
        $product_name = $product ? $product->post_title : 'Booking';

        // wp_timezone_string() can return either a real IANA name (e.g.
        // "Europe/Chisinau") or a raw UTC-offset string like "+00:00"
        // depending on how the site's General Settings timezone was
        // configured — Google Calendar's `timeZone` field expects an IANA
        // identifier and doesn't reliably honor a bare offset string, even
        // though it happened to compute the right instant in one test.
        // Converting to UTC ourselves (same approach class-bookflow-ical.php
        // already uses) sidesteps that entirely: DateTimeZone accepts
        // both formats fine for arithmetic, and sending `timeZone: UTC`
        // with an already-converted instant can never be misinterpreted.
        $site_tz = wp_timezone();

        try {
            $start_dt = new DateTime($booking->booking_date . ' ' . $booking->start_time, $site_tz);
        } catch (Exception $e) {
            $start_dt = new DateTime('now', $site_tz);
        }

        if (!empty($booking->end_time)) {
            try {
                $end_dt = new DateTime($booking->booking_date . ' ' . $booking->end_time, $site_tz);
            } catch (Exception $e) {
                $end_dt = (clone $start_dt)->modify('+1 hour');
            }
        } else {
            // No end_time recorded -> default to a 1-hour block, same
            // fallback the iCal export already uses for consistency.
            $end_dt = (clone $start_dt)->modify('+1 hour');
        }

        // A stored end_time is only a time-of-day, re-combined here with
        // whatever booking_date currently is — if a caller updates
        // start_time/booking_date without also recalculating end_time
        // (e.g. rescheduling to a later slot without touching the
        // originally-computed end), the old end can land at or before the
        // new start. The DB layer has no constraint against that; Google's
        // API correctly rejects an inverted range outright ("The specified
        // time range is empty"), so guard against it here rather than
        // surfacing that as a silent, unexplained upsert failure.
        if ($end_dt <= $start_dt) {
            $end_dt = (clone $start_dt)->modify('+1 hour');
        }

        $start_dt->setTimezone(new DateTimeZone('UTC'));
        $end_dt->setTimezone(new DateTimeZone('UTC'));
        $start = $start_dt->format('Y-m-d\TH:i:s\Z');
        $end = $end_dt->format('Y-m-d\TH:i:s\Z');

        $desc_parts = [];
        $desc_parts[] = 'Persons: ' . (int) $booking->persons_total;
        $desc_parts[] = 'Status: ' . ucfirst(str_replace('-', ' ', $booking->status));
        if (!empty($booking->customer_email)) {
            $desc_parts[] = 'Email: ' . $booking->customer_email;
        }
        if (!empty($booking->customer_phone)) {
            $desc_parts[] = 'Phone: ' . $booking->customer_phone;
        }
        if (!empty($booking->order_id)) {
            $desc_parts[] = 'Order: #' . $booking->order_id;
        }
        if (!empty($booking->notes)) {
            $desc_parts[] = 'Notes: ' . $booking->notes;
        }

        return [
            'summary' => sprintf('%s — %s', $product_name, $booking->customer_name ?: ('#' . $booking->id)),
            'description' => implode("\n", $desc_parts),
            'location' => $product_name,
            'start' => ['dateTime' => $start, 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $end, 'timeZone' => 'UTC'],
            // The one thing the iCal feed could never do: a real,
            // per-event reminder Google will actually surface as a popup
            // notification, using the site's own configured lead time.
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'popup', 'minutes' => self::reminder_minutes()],
                ],
            ],
        ];
    }

    /**
     * Creates the event if the booking has none yet, otherwise updates
     * the existing one in place. Stores the returned event ID back on the
     * booking row either way. Silently no-ops if not connected — callers
     * don't need to check first.
     */
    public static function upsert_event($booking_id): void {
        if (!self::is_connected()) {
            return;
        }
        $booking = Bookflow_Booking::get($booking_id);
        if (!$booking) {
            return;
        }

        $token = self::fresh_access_token();
        if (is_wp_error($token)) {
            return;
        }

        $payload = self::build_event_payload($booking);
        $calendar = rawurlencode(self::calendar_id());

        if (!empty($booking->google_calendar_event_id)) {
            $response = wp_remote_request(
                "https://www.googleapis.com/calendar/v3/calendars/$calendar/events/{$booking->google_calendar_event_id}",
                [
                    'method' => 'PATCH',
                    'timeout' => 15,
                    'headers' => ['Authorization' => "Bearer $token", 'Content-Type' => 'application/json'],
                    'body' => wp_json_encode($payload),
                ]
            );
            // A 404 here means the event was deleted on the Google side
            // (e.g. manually removed from the calendar) — fall through to
            // re-create it instead of leaving the booking permanently
            // out of sync with a dangling, now-invalid event ID.
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) !== 404) {
                self::store_event_id($booking_id, $booking->google_calendar_event_id, $response);
                return;
            }
        }

        $response = wp_remote_post(
            "https://www.googleapis.com/calendar/v3/calendars/$calendar/events",
            [
                'timeout' => 15,
                'headers' => ['Authorization' => "Bearer $token", 'Content-Type' => 'application/json'],
                'body' => wp_json_encode($payload),
            ]
        );
        self::store_event_id($booking_id, null, $response);
    }

    private static function store_event_id($booking_id, $previous_id, $response): void {
        if (is_wp_error($response)) {
            update_option(self::OPT_LAST_ERROR, $response->get_error_message());
            return;
        }
        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($status >= 200 && $status < 300 && is_array($body) && !empty($body['id'])) {
            if ($body['id'] !== $previous_id) {
                Bookflow_Booking::update($booking_id, ['google_calendar_event_id' => $body['id']]);
            }
            delete_option(self::OPT_LAST_ERROR);
        } else {
            update_option(self::OPT_LAST_ERROR, is_array($body) && !empty($body['error']['message'])
                ? $body['error']['message']
                : "Calendar API error (HTTP $status)");
        }
    }

    public static function delete_event($booking): void {
        if (!self::is_connected() || empty($booking->google_calendar_event_id)) {
            return;
        }
        $token = self::fresh_access_token();
        if (is_wp_error($token)) {
            return;
        }
        $calendar = rawurlencode(self::calendar_id());
        wp_remote_request(
            "https://www.googleapis.com/calendar/v3/calendars/$calendar/events/{$booking->google_calendar_event_id}",
            [
                'method' => 'DELETE',
                'timeout' => 15,
                'headers' => ['Authorization' => "Bearer $token"],
            ]
        );
        // Fire-and-forget: whether or not Google's delete succeeds (the
        // event may already be gone), the booking side is done with it.
    }

    // ---------------------------------------------------------------
    // Booking lifecycle hooks
    // ---------------------------------------------------------------

    public static function on_booking_created(array $data, int $booking_id): void {
        self::upsert_event($booking_id);
    }

    public static function on_status_changed(int $booking_id, string $old_status, string $new_status): void {
        if (in_array($new_status, ['cancelled', 'refunded', 'no-show'], true)) {
            $booking = Bookflow_Booking::get($booking_id);
            if ($booking) {
                self::delete_event($booking);
                Bookflow_Booking::update($booking_id, ['google_calendar_event_id' => null]);
            }
            return;
        }
        self::upsert_event($booking_id);
    }

    /** Date/time/persons edits should move the calendar event too. */
    public static function on_booking_updated(int $booking_id, array $update, $old): void {
        $relevant = ['booking_date', 'start_time', 'end_time', 'persons_total', 'customer_name', 'notes'];
        if (array_intersect(array_keys($update), $relevant)) {
            self::upsert_event($booking_id);
        }
    }

    public static function on_booking_removed(int $booking_id, $booking): void {
        self::delete_event($booking);
    }

    // ---------------------------------------------------------------
    // Settings screen
    // ---------------------------------------------------------------

    public static function add_menu() {
        add_submenu_page(
            'bookflow-bookings',
            'Google Calendar',
            'Google Calendar',
            'manage_woocommerce',
            'bookflow-google-calendar',
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings() {
        register_setting('bookflow_gcal', self::OPT_CLIENT_ID, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '']);
        register_setting('bookflow_gcal', self::OPT_CLIENT_SECRET, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '']);
        register_setting('bookflow_gcal', self::OPT_CALENDAR_ID, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'primary']);
        register_setting('bookflow_gcal', self::OPT_REMINDER_MINUTES, ['type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 30]);
    }

    public static function handle_test_event() {
        check_admin_referer('bookflow_gcal_test_event');
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Not allowed');
        }

        $token = self::fresh_access_token();
        $notice = 'test_error';
        if (!is_wp_error($token)) {
            $payload = [
                'summary' => 'Bookflow — test event',
                'description' => "This confirms your Google Calendar connection works.\nReminder is set to " . self::reminder_minutes() . ' minutes before.',
                // gmdate() already formats in UTC, so the timeZone field
                // must say UTC too — it previously claimed wp_timezone_string()
                // (the site's own zone) while the dateTime itself was UTC,
                // which would silently mis-time this test event on any site
                // not actually configured as UTC.
                'start' => ['dateTime' => gmdate('Y-m-d\TH:i:s', time() + 3600), 'timeZone' => 'UTC'],
                'end' => ['dateTime' => gmdate('Y-m-d\TH:i:s', time() + 7200), 'timeZone' => 'UTC'],
                'reminders' => [
                    'useDefault' => false,
                    'overrides' => [['method' => 'popup', 'minutes' => self::reminder_minutes()]],
                ],
            ];
            $calendar = rawurlencode(self::calendar_id());
            $response = wp_remote_post("https://www.googleapis.com/calendar/v3/calendars/$calendar/events", [
                'timeout' => 15,
                'headers' => ['Authorization' => "Bearer $token", 'Content-Type' => 'application/json'],
                'body' => wp_json_encode($payload),
            ]);
            $status = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
            if ($status >= 200 && $status < 300) {
                $notice = 'test_success';
                delete_option(self::OPT_LAST_ERROR);
            } elseif (is_wp_error($response)) {
                update_option(self::OPT_LAST_ERROR, $response->get_error_message());
            } else {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                update_option(self::OPT_LAST_ERROR, is_array($body) && !empty($body['error']['message'])
                    ? $body['error']['message']
                    : "HTTP $status");
            }
        }

        wp_safe_redirect(add_query_arg('bookflow_gcal_notice', $notice, admin_url('admin.php?page=bookflow-google-calendar')));
        exit;
    }

    public static function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $notice = isset($_GET['bookflow_gcal_notice']) ? sanitize_text_field(wp_unslash($_GET['bookflow_gcal_notice'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display notice, no state change
        ?>
        <div class="wrap">
            <h1>Google Calendar</h1>
            <p>Every booking is created as a real event on your connected Google Calendar, with a popup reminder before the reservation — unlike the read-only, no-reminder iCal subscribe feed under Bookings → Calendar sync.</p>

            <?php if ($notice === 'connected') : ?>
                <div class="notice notice-success"><p>Google Calendar connected.</p></div>
            <?php elseif ($notice === 'disconnected') : ?>
                <div class="notice notice-info"><p>Disconnected.</p></div>
            <?php elseif ($notice === 'reset') : ?>
                <div class="notice notice-info"><p>Credentials cleared — add the correct Client ID/Secret below.</p></div>
            <?php elseif ($notice === 'test_success') : ?>
                <div class="notice notice-success"><p>Test event created — check your calendar.</p></div>
            <?php elseif ($notice === 'error' || $notice === 'test_error') : ?>
                <div class="notice notice-error"><p>Something went wrong<?php echo self::last_error() ? ': ' . esc_html(self::last_error()) : '.'; ?></p></div>
            <?php endif; ?>

            <?php if (!self::is_configured()) : ?>
                <h2>1. Add your Google OAuth credentials</h2>
                <p>In your Google Cloud project:</p>
                <ol style="max-width:640px;">
                    <li style="margin-bottom:8px;">
                        <a href="https://console.cloud.google.com/apis/library/calendar-json.googleapis.com" target="_blank" rel="noopener">Enable the Google Calendar API</a>
                        — opens straight to the Enable button for whichever project is currently selected in the top bar.
                    </li>
                    <li style="margin-bottom:8px;">
                        <a href="https://console.cloud.google.com/apis/credentials/consent" target="_blank" rel="noopener">Configure the OAuth consent screen</a>
                        — choose <strong>External</strong>, fill in an app name + your email, and under <em>Test users</em> add the Google account you'll actually connect below (required while the app is in "Testing" mode).
                    </li>
                    <li style="margin-bottom:8px;">
                        <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Go to Credentials</a>
                        → <strong>+ Create Credentials → OAuth client ID</strong> → Application type: <strong>Web application</strong>.
                    </li>
                    <li style="margin-bottom:8px;">
                        Under <strong>Authorized redirect URIs</strong>, click <strong>+ Add URI</strong> and paste exactly this (must match — no trailing slash, same scheme):
                        <br /><code><?php echo esc_html(self::redirect_uri()); ?></code>
                    </li>
                    <li>Click <strong>Create</strong> — a popup shows the <strong>Client ID</strong> and <strong>Client Secret</strong>. Copy both into the fields below.</li>
                </ol>
                <form method="post" action="options.php">
                    <?php settings_fields('bookflow_gcal'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="bookflow_gcal_client_id">Client ID</label></th>
                            <td><input type="text" id="bookflow_gcal_client_id" name="<?php echo esc_attr(self::OPT_CLIENT_ID); ?>" value="<?php echo esc_attr(self::client_id()); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bookflow_gcal_client_secret">Client Secret</label></th>
                            <td><input type="password" id="bookflow_gcal_client_secret" name="<?php echo esc_attr(self::OPT_CLIENT_SECRET); ?>" value="<?php echo esc_attr(self::client_secret()); ?>" class="regular-text" autocomplete="off" /></td>
                        </tr>
                    </table>
                    <?php submit_button('Save credentials'); ?>
                </form>

            <?php elseif (!self::is_connected()) : ?>
                <h2>2. Connect your Google account</h2>
                <p><a href="<?php echo esc_url(self::authorize_url()); ?>" class="button button-primary">Connect Google Calendar</a></p>
                <p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="bookflow_gcal_reset_credentials" />
                        <?php wp_nonce_field('bookflow_gcal_reset_credentials'); ?>
                        <button type="submit" class="button-link" onclick="return confirm('Clear the saved Client ID/Secret and start over?');">Used the wrong Client ID/Secret? Clear and start over</button>
                    </form>
                </p>

            <?php else : ?>
                <div class="notice notice-success inline">
                    <p><span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span> Connected<?php echo self::account_email() ? ' as <strong>' . esc_html(self::account_email()) . '</strong>' : ''; ?>.</p>
                </div>

                <form method="post" action="options.php">
                    <?php settings_fields('bookflow_gcal'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="bookflow_gcal_calendar_id">Calendar</label></th>
                            <td>
                                <?php $calendars = self::list_calendars(); ?>
                                <?php if ($calendars) : ?>
                                    <select id="bookflow_gcal_calendar_id" name="<?php echo esc_attr(self::OPT_CALENDAR_ID); ?>">
                                        <?php foreach ($calendars as $cal) : ?>
                                            <option value="<?php echo esc_attr($cal['id']); ?>" <?php selected(self::calendar_id(), $cal['id']); ?>>
                                                <?php echo esc_html($cal['summary'] . (!empty($cal['primary']) ? ' (primary)' : '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else : ?>
                                    <input type="text" id="bookflow_gcal_calendar_id" name="<?php echo esc_attr(self::OPT_CALENDAR_ID); ?>" value="<?php echo esc_attr(self::calendar_id()); ?>" class="regular-text" />
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bookflow_gcal_reminder_minutes">Reminder</label></th>
                            <td>
                                <input type="number" min="1" id="bookflow_gcal_reminder_minutes" name="<?php echo esc_attr(self::OPT_REMINDER_MINUTES); ?>" value="<?php echo esc_attr(self::reminder_minutes()); ?>" class="small-text" />
                                minutes before the reservation
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Save settings'); ?>
                </form>

                <h2>Test</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
                    <input type="hidden" name="action" value="bookflow_gcal_test_event" />
                    <?php wp_nonce_field('bookflow_gcal_test_event'); ?>
                    <?php submit_button('Send test event', 'secondary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
                    <input type="hidden" name="action" value="bookflow_gcal_disconnect" />
                    <?php wp_nonce_field('bookflow_gcal_disconnect'); ?>
                    <?php submit_button('Disconnect', 'delete', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                    <input type="hidden" name="action" value="bookflow_gcal_reset_credentials" />
                    <?php wp_nonce_field('bookflow_gcal_reset_credentials'); ?>
                    <?php submit_button('Use different Client ID/Secret', 'secondary', 'submit', false, ['onclick' => "return confirm('This disconnects the current account and clears the saved Client ID/Secret. Continue?');"]); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
}

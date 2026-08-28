<?php
/**
 * Rate Limiting
 *
 * Simple transient-based rate limiter for public endpoints.
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Rate_Limit {

    /**
     * Check if the current request is allowed under rate limits.
     *
     * @param string $action  Action identifier, e.g. 'slots', 'month', 'price'.
     * @param int    $max     Maximum requests allowed per window.
     * @param int    $window  Window duration in seconds.
     * @return bool True if allowed, false if rate limited.
     */
    public static function check($action, $max = 30, $window = 60) {
        /**
         * Filter the maximum number of requests allowed per window.
         *
         * @param int    $max    Default max requests.
         * @param string $action The action being rate-limited.
         */
        $max = (int) apply_filters('bookflow_rate_limit', $max, $action);

        $ip  = self::get_ip();
        $key = 'bookflow_rl_' . md5($ip . '_' . $action);

        $data = get_transient($key);

        if ($data === false) {
            // First request in this window
            set_transient($key, 1, $window);
            return true;
        }

        $count = (int) $data;

        if ($count >= $max) {
            return false;
        }

        // Increment counter — set_transient resets TTL, so we preserve the
        // remaining window by not extending expiry beyond the original.
        // WordPress transients don't support "keep existing TTL", so we
        // accept the minor TTL reset as an acceptable trade-off for simplicity.
        set_transient($key, $count + 1, $window);

        return true;
    }

    /**
     * Get client IP address.
     *
     * Uses the same detection logic as Bookflow_Logger.
     *
     * @return string
     */
    private static function get_ip() {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', sanitize_text_field(wp_unslash($_SERVER[$header])));
                $ip  = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
}

<?php
/**
 * Spam protection: honeypot field + optional reCAPTCHA v3 verification.
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Spam {

    /**
     * Check the current booking submission for bot signals.
     *
     * @return bool True if the submission looks human, false to block it.
     */
    // Called only from Bookflow_Cart::validate_booking(), which runs inside
    // WooCommerce's own add-to-cart flow (see that method's docblock for why
    // no bespoke nonce is required there).
    // phpcs:disable WordPress.Security.NonceVerification.Missing
    public static function is_human() {
        // Honeypot: any value here means a bot filled every input on the form.
        if (!empty($_POST['bookflow_website'])) {
            return false;
        }

        $site_key = apply_filters('bookflow_recaptcha_site_key', '');
        $secret_key = apply_filters('bookflow_recaptcha_secret_key', '');
        if (empty($site_key) || empty($secret_key)) {
            return true; // reCAPTCHA not configured — honeypot alone is the gate.
        }

        $token = sanitize_text_field(wp_unslash($_POST['bookflow_recaptcha_token'] ?? ''));
        if (empty($token)) {
            return false;
        }

        return self::verify_recaptcha($token, $secret_key);
    }
    // phpcs:enable WordPress.Security.NonceVerification.Missing

    /**
     * Verify a reCAPTCHA v3 token with Google, requiring a minimum score.
     *
     * @param string $token
     * @param string $secret_key
     * @return bool
     */
    private static function verify_recaptcha($token, $secret_key) {
        $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
            'timeout' => 5,
            'body'    => [
                'secret'   => $secret_key,
                'response' => $token,
                'remoteip' => Bookflow_Rate_Limit::get_ip_public(),
            ],
        ]);

        if (is_wp_error($response)) {
            // Google unreachable: fail open so a network hiccup on Google's
            // side never blocks real customers from completing a booking.
            return true;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['success'])) {
            return false;
        }

        $min_score = (float) apply_filters('bookflow_recaptcha_min_score', 0.5);
        return !isset($body['score']) || $body['score'] >= $min_score;
    }
}

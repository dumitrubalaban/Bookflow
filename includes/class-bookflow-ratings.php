<?php
/**
 * Guide/Resource Ratings
 *
 * After a booking is marked completed (the existing auto-complete cron
 * already does this for past bookings), the customer gets a "rate your
 * guide" email with a one-time token link to a public rating page. One
 * rating per booking; the assigned resource's avg_rating/rating_count are
 * kept denormalized for fast reads on the wizard's Staff step.
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Ratings {

    public function __construct() {
        add_action('bookflow_booking_status_changed', [$this, 'on_status_change'], 10, 3);
        add_action('template_redirect', [$this, 'maybe_render_rating_page']);
        add_action('wp_ajax_bookflow_submit_rating', [$this, 'ajax_submit']);
        add_action('wp_ajax_nopriv_bookflow_submit_rating', [$this, 'ajax_submit']);
    }

    public function on_status_change($booking_id, $old_status, $new_status) {
        if ($new_status !== 'completed') {
            return;
        }
        $booking = Bookflow_Booking::get($booking_id);
        if (!$booking || !$booking->resource_id || empty($booking->customer_email)) {
            return;
        }
        $this->send_rating_email($booking);
    }

    private function get_or_create_token($booking) {
        if (!empty($booking->rating_token)) {
            return $booking->rating_token;
        }
        $token = wp_generate_password(32, false);
        Bookflow_Booking::update($booking->id, ['rating_token' => $token]);
        return $token;
    }

    private function send_rating_email($booking) {
        $token = $this->get_or_create_token($booking);
        $resource = Bookflow_Resources::get($booking->resource_id);
        $link = add_query_arg([
            'bookflow_rate' => $booking->id,
            'token'         => $token,
        ], home_url('/'));

        $site_name = get_bloginfo('name');
        $locale = $booking->customer_locale ?: get_locale();
        $subject = Bookflow_I18n::t_locale('email.subject.rate_guide', $locale, $site_name);
        $guide_name = $resource ? $resource->title : '';

        $html = '<div style="font-family:sans-serif;max-width:520px;margin:0 auto;padding:24px;">'
            . '<h2 style="margin-top:0;">' . esc_html($site_name) . '</h2>'
            . '<p>' . esc_html(sprintf(Bookflow_I18n::t_locale('email.body.rate_guide', $locale), $guide_name)) . '</p>'
            . '<p><a href="' . esc_url($link) . '" style="display:inline-block;background:#917236;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;">'
            . esc_html(Bookflow_I18n::t_locale('email.label.rate_guide_button', $locale)) . '</a></p>'
            . '</div>';

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . get_option('admin_email') . '>',
        ];

        wp_mail($booking->customer_email, $subject, $html, $headers);
    }

    /**
     * Public rating page at /?bookflow_rate={booking_id}&token=...
     */
    public function maybe_render_rating_page() {
        if (!isset($_GET['bookflow_rate'])) {
            return;
        }

        $booking_id = absint($_GET['bookflow_rate']);
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        $booking = Bookflow_Booking::get($booking_id);

        $valid = $booking && $token && hash_equals((string) $booking->rating_token, $token);
        $already_rated = $valid ? $this->get_existing_rating($booking_id) : null;

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html(Bookflow_I18n::t('form.rate_your_guide')); ?></title>
            <style>
                body { font-family: sans-serif; max-width: 480px; margin: 60px auto; padding: 0 20px; color: #222; }
                h1 { font-size: 22px; }
                .stars { font-size: 40px; letter-spacing: 6px; margin: 20px 0; }
                .stars span { cursor: pointer; color: #ccc; }
                .stars span.active { color: #f0ad4e; }
                textarea { width: 100%; min-height: 100px; padding: 10px; font-size: 14px; box-sizing: border-box; }
                button { background: #917236; color: #fff; border: none; padding: 12px 24px; font-size: 15px; cursor: pointer; border-radius: 4px; margin-top: 12px; }
                button:disabled { opacity: .5; cursor: not-allowed; }
                .msg { padding: 14px; border-radius: 4px; margin-top: 16px; }
                .msg.ok { background: #eaf6ea; color: #2c662d; }
            </style>
        </head>
        <body>
            <?php if (!$valid) : ?>
                <h1><?php Bookflow_I18n::te('form.rating_link_invalid'); ?></h1>
            <?php elseif ($already_rated) : ?>
                <h1><?php Bookflow_I18n::te('form.rating_already_submitted'); ?></h1>
            <?php else : ?>
                <h1><?php Bookflow_I18n::te('form.rate_your_guide'); ?></h1>
                <div class="stars" id="stars">
                    <span data-v="1">&#9733;</span><span data-v="2">&#9733;</span><span data-v="3">&#9733;</span><span data-v="4">&#9733;</span><span data-v="5">&#9733;</span>
                </div>
                <textarea id="comment" placeholder="<?php echo esc_attr(Bookflow_I18n::t('form.rating_comment_placeholder')); ?>"></textarea>
                <br>
                <button id="submit" disabled><?php Bookflow_I18n::te('form.submit_rating'); ?></button>
                <div id="result"></div>
                <script>
                (function () {
                    var rating = 0;
                    var stars = document.querySelectorAll('#stars span');
                    stars.forEach(function (s) {
                        s.addEventListener('click', function () {
                            rating = parseInt(s.dataset.v, 10);
                            stars.forEach(function (o) { o.classList.toggle('active', parseInt(o.dataset.v, 10) <= rating); });
                            document.getElementById('submit').disabled = false;
                        });
                    });
                    document.getElementById('submit').addEventListener('click', function () {
                        var btn = this;
                        btn.disabled = true;
                        var fd = new FormData();
                        fd.append('action', 'bookflow_submit_rating');
                        fd.append('booking_id', <?php echo (int) $booking_id; ?>);
                        fd.append('token', <?php echo wp_json_encode($token); ?>);
                        fd.append('rating', rating);
                        fd.append('comment', document.getElementById('comment').value);
                        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', { method: 'POST', body: fd })
                            .then(function (r) { return r.json(); })
                            .then(function (res) {
                                var result = document.getElementById('result');
                                if (res.success) {
                                    result.innerHTML = '<div class="msg ok"><?php echo esc_js(Bookflow_I18n::t('form.rating_thanks')); ?></div>';
                                    document.getElementById('stars').style.display = 'none';
                                    document.getElementById('comment').style.display = 'none';
                                    btn.style.display = 'none';
                                } else {
                                    btn.disabled = false;
                                }
                            });
                    });
                })();
                </script>
            <?php endif; ?>
        </body>
        </html>
        <?php
        exit;
    }

    private function get_existing_rating($booking_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bookflow_resource_ratings WHERE booking_id = %d",
            absint($booking_id)
        ));
    }

    public function ajax_submit() {
        $booking_id = absint($_POST['booking_id'] ?? 0);
        $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
        $rating = absint($_POST['rating'] ?? 0);
        $comment = sanitize_textarea_field(wp_unslash($_POST['comment'] ?? ''));

        $booking = Bookflow_Booking::get($booking_id);
        if (!$booking || !$token || !hash_equals((string) $booking->rating_token, $token)) {
            wp_send_json_error(['message' => Bookflow_I18n::t('error.invalid_request')]);
        }
        if (!$booking->resource_id) {
            wp_send_json_error(['message' => Bookflow_I18n::t('error.invalid_request')]);
        }
        if ($rating < 1 || $rating > 5) {
            wp_send_json_error(['message' => Bookflow_I18n::t('error.invalid_request')]);
        }
        if ($this->get_existing_rating($booking_id)) {
            wp_send_json_error(['message' => Bookflow_I18n::t('form.rating_already_submitted')]);
        }

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'bookflow_resource_ratings', [
            'resource_id' => absint($booking->resource_id),
            'booking_id'  => $booking_id,
            'rating'      => $rating,
            'comment'     => $comment,
        ]);

        $this->recompute_resource_stats($booking->resource_id);

        wp_send_json_success();
    }

    private function recompute_resource_stats($resource_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT AVG(rating) as avg_rating, COUNT(*) as rating_count FROM {$wpdb->prefix}bookflow_resource_ratings WHERE resource_id = %d",
            absint($resource_id)
        ));
        $wpdb->update($wpdb->prefix . 'bookflow_resources', [
            'avg_rating'   => round((float) $row->avg_rating, 2),
            'rating_count' => (int) $row->rating_count,
        ], ['id' => absint($resource_id)]);
    }
}

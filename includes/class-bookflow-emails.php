<?php
/**
 * Email Notifications
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Emails {

    public function __construct() {
        add_action('bookflow_booking_status_changed', [$this, 'on_status_change'], 10, 3);
        add_action('bookflow_send_reminder_email', [$this, 'send_reminder_for_booking']);
        add_action('bookflow_send_queued_email', [$this, 'process_queued_email'], 10, 2);
    }

    public function on_status_change($booking_id, $old_status, $new_status) {
        $booking = Bookflow_Booking::get($booking_id);
        if (!$booking) {
            return;
        }

        switch ($new_status) {
            case 'confirmed':
                $this->queue_email($booking_id, 'confirmation');
                break;
            case 'paid':
                $this->queue_email($booking_id, 'paid');
                break;
            case 'cancelled':
                $this->queue_email($booking_id, 'cancellation');
                break;
            case 'refunded':
                $this->queue_email($booking_id, 'refund');
                break;
        }

        // Always notify admin on new bookings
        if ($old_status === 'pending' && in_array($new_status, ['confirmed', 'paid'])) {
            $this->queue_email($booking_id, 'admin_new');
        }
    }

    /**
     * Queue an email to be sent immediately.
     * Prefers Action Scheduler (ships with WooCommerce) for reliable immediate processing.
     * Falls back to wp_schedule_single_event if Action Scheduler is not available.
     */
    private function queue_email($booking_id, $email_type) {
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time(), 'bookflow_send_queued_email', [$booking_id, $email_type], 'bookflow');
        } else {
            wp_schedule_single_event(time(), 'bookflow_send_queued_email', [$booking_id, $email_type]);
        }
    }

    /**
     * Process a queued email: look up booking and dispatch to the appropriate send method.
     */
    public function process_queued_email($booking_id, $email_type) {
        $booking = Bookflow_Booking::get($booking_id);
        if (!$booking) {
            return;
        }

        switch ($email_type) {
            case 'confirmation':
                $this->send_confirmation($booking);
                break;
            case 'paid':
                $this->send_paid($booking);
                break;
            case 'cancellation':
                $this->send_cancellation($booking);
                break;
            case 'refund':
                $this->send_refund($booking);
                break;
            case 'admin_new':
                $this->send_admin_new_booking($booking);
                break;
        }
    }

    /**
     * Get customer locale for a booking, falling back to site locale.
     */
    private function get_customer_locale($booking) {
        return $booking->customer_locale ?: Bookflow_I18n::get_locale();
    }

    private function send_confirmation($booking) {
        $to = $booking->customer_email;
        if (empty($to)) return;

        $locale = $this->get_customer_locale($booking);
        $subject = Bookflow_I18n::t_locale('email.subject.confirmed', $locale, get_bloginfo('name'));

        $this->send($to, $subject, $this->build_email($booking, 'confirmation'), $booking);
    }

    private function send_paid($booking) {
        $to = $booking->customer_email;
        if (empty($to)) return;

        $locale = $this->get_customer_locale($booking);
        $subject = Bookflow_I18n::t_locale('email.subject.paid', $locale, $booking->id);

        $this->send($to, $subject, $this->build_email($booking, 'paid'), $booking);
    }

    private function send_cancellation($booking) {
        $to = $booking->customer_email;
        if (empty($to)) return;

        $locale = $this->get_customer_locale($booking);
        $subject = Bookflow_I18n::t_locale('email.subject.cancelled', $locale, get_bloginfo('name'));

        $this->send($to, $subject, $this->build_email($booking, 'cancellation'));

        // Also notify admin
        $this->send(
            get_option('admin_email'),
            Bookflow_I18n::t('email.subject.cancelled_admin', $booking->id),
            $this->build_email($booking, 'cancellation')
        );
    }

    private function send_refund($booking) {
        $to = $booking->customer_email;
        if (empty($to)) return;

        $locale = $this->get_customer_locale($booking);
        $subject = Bookflow_I18n::t_locale('email.subject.refunded', $locale, get_bloginfo('name'));

        $this->send($to, $subject, $this->build_email($booking, 'refund'));
    }

    private function send_admin_new_booking($booking) {
        $subject = Bookflow_I18n::t('email.subject.new_booking', $booking->id, $booking->customer_name);

        $this->send(
            get_option('admin_email'),
            $subject,
            $this->build_email($booking, 'admin_new')
        );
    }

    public function send_reminder_for_booking($booking) {
        if (is_numeric($booking)) {
            $booking = Bookflow_Booking::get($booking);
        }
        if (!$booking || empty($booking->customer_email)) return;

        $locale = $this->get_customer_locale($booking);
        $subject = Bookflow_I18n::t_locale('email.subject.reminder', $locale, get_bloginfo('name'));

        $this->send(
            $booking->customer_email,
            $subject,
            $this->build_email($booking, 'reminder'),
            $booking
        );
    }

    private function send($to, $subject, $html, $booking = null) {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
        ];

        // Let integrations (e.g. iCal) attach files for upcoming-booking emails
        $attachments = $booking ? apply_filters('bookflow_email_attachments', [], $booking) : [];

        wp_mail($to, $subject, $html, $headers, $attachments);
    }

    private function build_email($booking, $type) {
        $site_name = get_bloginfo('name');
        $product = wc_get_product($booking->product_id);
        $resource = $booking->resource_id ? Bookflow_Resources::get($booking->resource_id) : null;

        $is_admin = ($type === 'admin_new');
        $locale = $is_admin ? Bookflow_I18n::get_locale() : $this->get_customer_locale($booking);

        $product_name = $product ? $product->get_name() : Bookflow_I18n::t_locale('common.booking', $locale);

        $title_keys = [
            'confirmation' => 'email.title.confirmed',
            'paid'         => 'email.title.paid',
            'cancellation' => 'email.title.cancelled',
            'refund'       => 'email.title.refunded',
            'reminder'     => 'email.title.reminder',
            'admin_new'    => 'email.title.new_booking',
        ];

        $body_keys = [
            'confirmation' => 'email.body.confirmed',
            'paid'         => 'email.body.paid',
            'cancellation' => 'email.body.cancelled',
            'refund'       => 'email.body.refunded',
            'reminder'     => 'email.body.reminder',
            'admin_new'    => 'email.body.new_booking',
        ];

        $title_key = $title_keys[$type] ?? 'email.title.update';
        $title = $is_admin
            ? Bookflow_I18n::t($title_key)
            : Bookflow_I18n::t_locale($title_key, $locale);

        $body_key = $body_keys[$type] ?? null;
        $message = $body_key
            ? ($is_admin
                ? Bookflow_I18n::t($body_key, esc_html($booking->customer_name))
                : Bookflow_I18n::t_locale($body_key, $locale, esc_html($booking->customer_name)))
            : '';

        // Helper to translate labels in correct locale
        $l = function ($key) use ($is_admin, $locale) {
            return $is_admin ? Bookflow_I18n::t($key) : Bookflow_I18n::t_locale($key, $locale);
        };

        $accent_color = apply_filters('bookflow_email_accent_color', '#d4a853');

        // Build the inner content
        ob_start();
        ?>
            <?php if ($type === 'admin_new') : ?>
            <div style="margin-bottom:28px;color:black;">
                <h2 style="color:<?php echo esc_attr($accent_color); ?>;font-size:24px;text-align:center;margin-bottom:30px;">&#128276; <?php echo esc_html($title); ?></h2>
            </div>
            <?php else : ?>
            <div style="margin-bottom:28px;color:black;">
                <p><?php echo wp_kses_post($message); ?></p>
            </div>
            <?php endif; ?>

            <h3 style="margin-bottom:12px;font-size:14px;color:black;font-weight:600;<?php echo ($type === 'admin_new') ? 'border-bottom:2px solid ' . esc_attr($accent_color) . ';padding-bottom:8px;font-size:16px;' : ''; ?>">
                <?php echo esc_html($product_name); ?>
            </h3>
            <div style="margin-left:20px;">
                <p style="color:black;">
                    <span style="font-size:14px;font-weight:600;"><?php echo esc_html($l('email.label.booking_number')); ?></span>
                    <?php echo esc_html($booking->id); ?>
                </p>
                <p style="color:black;">
                    <span style="font-size:14px;font-weight:600;"><?php echo esc_html($l('email.label.date')); ?></span>
                    <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($booking->booking_date))); ?>
                </p>
                <p style="color:black;">
                    <span style="font-size:14px;font-weight:600;"><?php echo esc_html($l('email.label.time')); ?></span>
                    <?php echo esc_html($booking->start_time); ?>
                </p>
                <p style="color:black;">
                    <span style="font-size:14px;font-weight:600;"><?php echo esc_html($l('email.label.persons')); ?></span>
                    <?php echo esc_html($booking->persons_total); ?>
                </p>
                <?php if ($resource) : ?>
                <p style="color:black;">
                    <span style="font-size:14px;font-weight:600;"><?php echo esc_html($l('email.label.resource')); ?></span>
                    <?php echo esc_html($resource->title); ?>
                </p>
                <?php endif; ?>
                <p style="color:black;<?php echo ($type === 'admin_new') ? 'font-size:16px;font-weight:700;color:' . esc_attr($accent_color) . ';' : ''; ?>">
                    <span style="font-size:14px;font-weight:600;"><?php echo esc_html($l('email.label.total')); ?></span>
                    <?php echo esc_html(wp_strip_all_tags(wc_price($booking->cost))); ?>
                </p>
                <p style="color:black;">
                    <span style="font-size:14px;font-weight:600;"><?php echo esc_html($l('email.label.status')); ?></span>
                    <?php echo esc_html(Bookflow_I18n::status($booking->status)); ?>
                </p>
                <?php if (!empty($booking->notes)) : ?>
                <p style="color:black;">
                    <span style="font-size:14px;font-weight:600;"><?php echo esc_html($l('email.label.notes')); ?></span>
                    <?php echo esc_html($booking->notes); ?>
                </p>
                <?php endif; ?>
            </div>

            <?php if ($type === 'confirmation' || $type === 'paid' || $type === 'reminder') : ?>
            <p style="margin-top:20px;color:#666;font-size:13px;">
                <?php echo esc_html($l('email.label.contact_us')); ?>
            </p>
            <?php endif; ?>

            <?php if ($type === 'admin_new') : ?>
            <p style="margin-top:20px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=bookflow-bookings&view=' . $booking->id)); ?>" style="background:<?php echo esc_attr($accent_color); ?>;color:#fff;padding:10px 20px;text-decoration:none;display:inline-block;border-radius:4px;">
                    <?php echo esc_html(Bookflow_I18n::t('email.label.view_booking')); ?>
                </a>
            </p>
            <?php endif; ?>
        <?php
        $content = ob_get_clean();

        // Allow themes/plugins to provide a custom email wrapper
        if (has_filter('bookflow_email_wrap')) {
            return apply_filters('bookflow_email_wrap', $content, $title);
        }

        // Fallback: generic wrapper using site identity
        $site_name = esc_html(get_bloginfo('name'));
        $logo_id = get_theme_mod('custom_logo');
        $header_html = '<h1 style="color:#fff;margin:0;font-size:24px;">' . $site_name . '</h1>';
        if ($logo_id) {
            $logo_url = esc_url(wp_get_attachment_image_url($logo_id, 'medium'));
            if ($logo_url) {
                $header_html = '<img src="' . $logo_url . '" style="max-width:211px;" alt="' . $site_name . '" />';
            }
        }

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;background:#f8fafc;padding:20px;font-family:Arial,sans-serif;"><div style="max-width:600px;margin:0 auto;background:#fff;border-radius:4px;overflow:hidden;"><div style="background:#000;padding:30px;text-align:center;">' . $header_html . '</div><div style="padding:30px;">' . $content . '</div><div style="background:#000;color:#999;padding:20px;text-align:center;font-size:12px;">&copy; ' . gmdate('Y') . ' ' . $site_name . '</div></div></body></html>';
    }
}

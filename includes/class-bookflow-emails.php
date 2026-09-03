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

    /**
     * Same palette as the admin calendar's status pills — one status color
     * system across the plugin instead of the emails inventing their own.
     */
    private static function status_color($status) {
        $colors = [
            'pending' => '#f0ad4e', 'confirmed' => '#5cb85c', 'paid' => '#337ab7',
            'partially-paid' => '#8e6fd6', 'in-progress' => '#5bc0de', 'completed' => '#6c757d',
            'no-show' => '#d9534f', 'cancelled' => '#9b9b9b', 'refunded' => '#c0392b',
        ];
        return $colors[$status] ?? '#646970';
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

        $accent_color = apply_filters('bookflow_email_accent_color', '#5b8fc7');

        $status_color = self::status_color($booking->status);
        $status_label = $is_admin ? Bookflow_I18n::status($booking->status) : Bookflow_I18n::status_locale($booking->status, $locale);

        $rows = [];
        $rows[] = [$l('email.label.booking_number'), '#' . $booking->id];
        $rows[] = [$l('email.label.date'), date_i18n(get_option('date_format'), strtotime($booking->booking_date))];
        $rows[] = [$l('email.label.time'), $booking->start_time];
        $rows[] = [$l('email.label.persons'), $booking->persons_total];
        if ($resource) {
            $rows[] = [$l('email.label.resource'), $resource->title];
        }
        if (!empty($booking->notes)) {
            $rows[] = [$l('email.label.notes'), $booking->notes];
        }

        // Build the inner content — a table-based layout throughout (not
        // flex/grid) since this has to render in Outlook's Word engine and
        // various webmail sanitizers, not just modern browsers.
        ob_start();
        ?>
            <?php if ($type === 'admin_new') : ?>
            <h2 style="margin:0 0 24px;font-size:22px;color:#1a1a1a;text-align:center;">&#128276; <?php echo esc_html($title); ?></h2>
            <?php else : ?>
            <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#333;"><?php echo wp_kses_post($message); ?></p>
            <?php endif; ?>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #dde3ec;border-radius:10px;overflow:hidden;">
                <tr>
                    <td style="background:#f5f7fa;padding:16px 20px;border-bottom:1px solid #dde3ec;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="font-size:15px;font-weight:700;color:#1a1a1a;"><?php echo esc_html($product_name); ?></td>
                                <td align="right">
                                    <span style="display:inline-block;padding:4px 12px;border-radius:99px;background:<?php echo esc_attr($status_color); ?>;color:#fff;font-size:11px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;">
                                        <?php echo esc_html($status_label); ?>
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <?php foreach ($rows as $i => $row) : ?>
                <tr>
                    <td style="padding:12px 20px;<?php echo $i % 2 === 1 ? 'background:#f7f9fc;' : ''; ?><?php echo $i < count($rows) - 1 ? 'border-bottom:1px solid #e8ecf2;' : ''; ?>">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="font-size:13px;color:#7c8798;"><?php echo esc_html($row[0]); ?></td>
                                <td align="right" style="font-size:14px;font-weight:600;color:#1a1a1a;"><?php echo esc_html($row[1]); ?></td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <td style="padding:14px 20px;background:#10151f;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="font-size:13px;font-weight:600;color:<?php echo esc_attr($accent_color); ?>;text-transform:uppercase;letter-spacing:.03em;"><?php echo esc_html($l('email.label.total')); ?></td>
                                <td align="right" style="font-size:17px;font-weight:700;color:#ffffff;"><?php echo esc_html(wp_strip_all_tags(wc_price($booking->cost))); ?></td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            <?php if ($type === 'admin_new') : ?>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:24px;">
                <tr>
                    <td style="background:<?php echo esc_attr($accent_color); ?>;border-radius:6px;">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=bookflow-bookings&view=' . $booking->id)); ?>" style="display:inline-block;padding:12px 24px;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;">
                            <?php echo esc_html(Bookflow_I18n::t('email.label.view_booking')); ?>
                        </a>
                    </td>
                </tr>
            </table>
            <?php endif; ?>

            <?php if ($type === 'confirmation' || $type === 'paid' || $type === 'reminder') : ?>
            <p style="margin:24px 0 0;color:#7c8798;font-size:13px;line-height:1.5;">
                <?php echo esc_html($l('email.label.contact_us')); ?>
            </p>
            <?php endif; ?>
        <?php
        $content = ob_get_clean();

        // Allow themes/plugins to provide a custom email wrapper
        if (has_filter('bookflow_email_wrap')) {
            return apply_filters('bookflow_email_wrap', $content, $title);
        }

        // Fallback: generic wrapper using site identity, styled to match
        // the plugin's own dark-navy/steel-blue visual language (booking
        // widget, admin calendar) instead of a plain black bar.
        $site_name = esc_html(get_bloginfo('name'));
        $logo_id = get_theme_mod('custom_logo');
        $header_html = '<span style="color:#ffffff;font-size:20px;font-weight:700;letter-spacing:.02em;">' . $site_name . '</span>';
        if ($logo_id) {
            $logo_url = esc_url(wp_get_attachment_image_url($logo_id, 'medium'));
            if ($logo_url) {
                $header_html = '<img src="' . $logo_url . '" style="max-width:180px;display:block;margin:0 auto;" alt="' . $site_name . '" />';
            }
        }

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>'
            . '<body style="margin:0;padding:0;background:#eef1f6;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef1f6;padding:24px 12px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.06);">'
            . '<tr><td style="background:#10151f;padding:28px 32px;text-align:center;">' . $header_html . '</td></tr>'
            . '<tr><td style="height:3px;line-height:3px;font-size:0;background:' . esc_attr($accent_color) . ';">&nbsp;</td></tr>'
            . '<tr><td style="padding:32px;">' . $content . '</td></tr>'
            . '<tr><td style="background:#10151f;padding:20px 32px;text-align:center;">'
            . '<span style="color:#9099ab;font-size:12px;">&copy; ' . gmdate('Y') . ' ' . $site_name . '</span>'
            . '</td></tr>'
            . '</table>'
            . '</td></tr>'
            . '</table>'
            . '</body></html>';
    }
}

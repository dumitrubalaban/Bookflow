<?php
/**
 * Gift Vouchers
 *
 * A "gift voucher" is just a regular WooCommerce simple product flagged
 * with _bookflow_is_gift_voucher. When an order containing one is marked
 * completed, this issues a real WooCommerce coupon per unit purchased
 * (fixed_cart discount, single use, 12-month expiry) and emails the
 * code(s) to the buyer. Redemption then rides entirely on WooCommerce's
 * own "Have a coupon?" checkout field — no custom cart/pricing code needed.
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Vouchers {

    public function __construct() {
        add_action('woocommerce_product_options_general_product_data', [$this, 'render_product_field']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_field']);
        add_action('woocommerce_order_status_completed', [$this, 'issue_for_order']);
    }

    public static function is_voucher_product($product_id) {
        return 'yes' === get_post_meta($product_id, '_bookflow_is_gift_voucher', true);
    }

    public function render_product_field() {
        global $post;
        woocommerce_wp_checkbox([
            'id'          => '_bookflow_is_gift_voucher',
            'label'       => Bookflow_I18n::t('product.is_gift_voucher'),
            'description' => Bookflow_I18n::t('product.is_gift_voucher_desc'),
        ]);
    }

    // Hooked to woocommerce_process_product_meta, which WooCommerce core
    // only fires after its own product-edit-screen nonce check.
    public function save_product_field($product_id) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        update_post_meta($product_id, '_bookflow_is_gift_voucher', isset($_POST['_bookflow_is_gift_voucher']) ? 'yes' : 'no');
    }

    /**
     * Issue one coupon per unit for every gift-voucher line item on this
     * order, then email all codes to the buyer in a single message.
     * Idempotent: skips items that already have a _bookflow_voucher_codes
     * meta (protects against status-change hook replays).
     */
    public function issue_for_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $issued_codes = [];

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            if (!self::is_voucher_product($product_id)) {
                continue;
            }
            if ($item->get_meta('_bookflow_voucher_codes')) {
                continue; // already issued
            }

            $qty = max(1, (int) $item->get_quantity());
            $unit_value = round((float) $item->get_total() / $qty, 2);
            $codes = [];

            for ($i = 0; $i < $qty; $i++) {
                $code = $this->create_coupon($unit_value, $order_id);
                if ($code) {
                    $codes[] = $code;
                }
            }

            if ($codes) {
                $item->add_meta_data('_bookflow_voucher_codes', implode(',', $codes));
                $item->save();
                $issued_codes = array_merge($issued_codes, array_map(function ($c) use ($unit_value) {
                    return ['code' => $c, 'value' => $unit_value];
                }, $codes));
            }
        }

        if ($issued_codes) {
            $this->email_codes($order, $issued_codes);
        }
    }

    private function create_coupon($amount, $order_id) {
        $code = $this->generate_unique_code();
        if (!$code) {
            return null;
        }

        $coupon = new WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_discount_type('fixed_cart');
        $coupon->set_amount($amount);
        $coupon->set_usage_limit(1);
        $coupon->set_individual_use(true);
        $coupon->set_date_expires(strtotime('+12 months'));
        $coupon->update_meta_data('_bookflow_gift_voucher', 'yes');
        $coupon->update_meta_data('_bookflow_voucher_order_id', $order_id);
        $coupon->save();

        return $coupon->get_id() ? $code : null;
    }

    private function generate_unique_code() {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = 'GIFT-' . strtoupper(wp_generate_password(8, false, false));
            if (!wc_get_coupon_id_by_code($code)) {
                return $code;
            }
        }
        return null;
    }

    private function email_codes($order, $codes) {
        $to = $order->get_billing_email();
        if (!$to) {
            return;
        }

        $site_name = get_bloginfo('name');
        $subject = Bookflow_I18n::t('email.subject.gift_voucher', $site_name);

        $rows = '';
        foreach ($codes as $c) {
            $rows .= '<tr><td style="padding:10px 0;border-bottom:1px solid #eee;font-family:monospace;font-size:16px;font-weight:bold;">' . esc_html($c['code']) . '</td>'
                . '<td style="padding:10px 0;border-bottom:1px solid #eee;text-align:right;">' . wp_kses_post(wc_price($c['value'])) . '</td></tr>';
        }

        $html = '<div style="font-family:sans-serif;max-width:520px;margin:0 auto;padding:24px;">'
            . '<h2 style="margin-top:0;">' . esc_html($site_name) . '</h2>'
            . '<p>' . esc_html(Bookflow_I18n::t('email.body.gift_voucher')) . '</p>'
            . '<table style="width:100%;border-collapse:collapse;">' . $rows . '</table>'
            . '<p style="color:#777;font-size:13px;margin-top:20px;">' . esc_html(Bookflow_I18n::t('email.label.gift_voucher_note')) . '</p>'
            . '</div>';

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . get_option('admin_email') . '>',
        ];

        wp_mail($to, $subject, $html, $headers);
    }
}

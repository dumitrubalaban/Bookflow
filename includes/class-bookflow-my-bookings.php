<?php
/**
 * Customer "My Bookings" Account Page
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_My_Bookings {

    public function __construct() {
        // Add endpoint
        add_action('init', [$this, 'add_endpoint']);
        add_filter('query_vars', [$this, 'query_vars']);

        // WooCommerce My Account menu
        add_filter('woocommerce_account_menu_items', [$this, 'add_menu_item']);
        add_action('woocommerce_account_my-bookings_endpoint', [$this, 'render_page']);

        // Handle cancellation
        add_action('wp_ajax_bookflow_customer_cancel_booking', [$this, 'handle_cancellation']);
    }

    public function add_endpoint() {
        add_rewrite_endpoint('my-bookings', EP_ROOT | EP_PAGES);
    }

    public function query_vars($vars) {
        $vars[] = 'my-bookings';
        return $vars;
    }

    public function add_menu_item($items) {
        $new_items = [];
        foreach ($items as $key => $label) {
            $new_items[$key] = $label;
            if ($key === 'orders') {
                $new_items['my-bookings'] = Bookflow_I18n::t('account.my_bookings');
            }
        }
        return $new_items;
    }

    /**
     * Render My Bookings page
     */
    public function render_page() {
        $customer_id = get_current_user_id();
        if (!$customer_id) {
            echo '<p>' . esc_html(Bookflow_I18n::t('account.please_login')) . '</p>';
            return;
        }

        $tab = sanitize_text_field(wp_unslash($_GET['booking_tab'] ?? 'upcoming'));

        $args = [
            'customer_id' => $customer_id,
            'orderby'     => 'booking_date',
            'order'       => 'ASC',
            'limit'       => 50,
        ];

        if ($tab === 'upcoming') {
            $args['date_from'] = wp_date('Y-m-d');
            $args['status_not'] = ['cancelled', 'refunded', 'no-show'];
            $args['order'] = 'ASC';
        } else {
            $args['date_to'] = wp_date('Y-m-d', strtotime('-1 day'));
            $args['order'] = 'DESC';
        }

        $bookings = Bookflow_Booking::query($args);

        $status_colors = [
            'pending'     => '#f0ad4e',
            'confirmed'   => '#5cb85c',
            'paid'        => '#337ab7',
            'in-progress' => '#5bc0de',
            'completed'   => '#337ab7',
            'cancelled'   => '#d9534f',
            'refunded'    => '#999',
            'no-show'     => '#d9534f',
        ];

        ?>
        <div class="bookflow-my-bookings">
            <div class="bookflow-tabs" style="margin-bottom:20px;">
                <a href="?booking_tab=upcoming" class="button <?php echo $tab === 'upcoming' ? 'button-primary' : ''; ?>">
                    <?php Bookflow_I18n::te('account.upcoming'); ?>
                </a>
                <a href="?booking_tab=past" class="button <?php echo $tab === 'past' ? 'button-primary' : ''; ?>">
                    <?php Bookflow_I18n::te('account.past'); ?>
                </a>
            </div>

            <?php if (empty($bookings)) : ?>
                <p><?php Bookflow_I18n::te('account.no_bookings'); ?></p>
            <?php else : ?>
                <table class="woocommerce-orders-table shop_table shop_table_responsive" style="width:100%;">
                    <thead>
                        <tr>
                            <th><?php Bookflow_I18n::te('account.booking'); ?></th>
                            <th><?php Bookflow_I18n::te('account.date_time'); ?></th>
                            <th><?php Bookflow_I18n::te('account.persons'); ?></th>
                            <th><?php Bookflow_I18n::te('account.total'); ?></th>
                            <th><?php Bookflow_I18n::te('account.status'); ?></th>
                            <?php if ($tab === 'upcoming') : ?>
                                <th><?php Bookflow_I18n::te('account.actions'); ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking) :
                            $product = wc_get_product($booking->product_id);
                            $color = $status_colors[$booking->status] ?? '#999';
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($product ? $product->get_name() : '#' . $booking->product_id); ?></strong>
                                <br><small>#<?php echo esc_html($booking->id); ?></small>
                            </td>
                            <td>
                                <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($booking->booking_date))); ?>
                                <br><?php echo esc_html($booking->start_time); ?>
                            </td>
                            <td><?php echo esc_html($booking->persons_total); ?></td>
                            <td><?php echo wp_kses_post(wc_price($booking->cost)); ?></td>
                            <td>
                                <span style="background:<?php echo esc_attr($color); ?>; color:#fff; padding:3px 8px; border-radius:3px; font-size:12px;">
                                    <?php echo esc_html(Bookflow_I18n::status($booking->status)); ?>
                                </span>
                            </td>
                            <?php if ($tab === 'upcoming') : ?>
                                <td>
                                    <?php if (Bookflow_Booking::can_customer_cancel($booking->id)) : ?>
                                        <a href="#" class="bookflow-cancel-booking button" data-booking-id="<?php echo esc_attr($booking->id); ?>"
                                           onclick="if(confirm('<?php echo esc_attr(Bookflow_I18n::t('account.cancel_confirm')); ?>')){var f=document.createElement('form');f.method='post';f.action='<?php echo esc_url(admin_url('admin-ajax.php')); ?>';var fields={action:'bookflow_customer_cancel_booking',booking_id:this.dataset.bookingId,nonce:'<?php echo esc_js(wp_create_nonce('bookflow_cancel_booking')); ?>'};for(var k in fields){var i=document.createElement('input');i.type='hidden';i.name=k;i.value=fields[k];f.appendChild(i);}document.body.appendChild(f);f.submit();}return false;">
                                            <?php Bookflow_I18n::te('account.cancel'); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Handle customer cancellation
     */
    public function handle_cancellation() {
        check_ajax_referer('bookflow_cancel_booking', 'nonce');

        $booking_id = absint($_POST['booking_id'] ?? 0);
        $booking = Bookflow_Booking::get($booking_id);

        if (!$booking || (int) $booking->customer_id !== get_current_user_id()) {
            wp_die(esc_html(Bookflow_I18n::t('error.unauthorized')));
        }

        if (!Bookflow_Booking::can_customer_cancel($booking_id)) {
            wp_die(esc_html(Bookflow_I18n::t('error.cannot_cancel')));
        }

        $result = Bookflow_Booking::transition_status($booking_id, 'cancelled', 'Cancelled by customer');

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        do_action('bookflow_booking_cancelled_by_customer', $booking_id, $booking);

        wp_safe_redirect(wc_get_account_endpoint_url('my-bookings'));
        exit;
    }
}

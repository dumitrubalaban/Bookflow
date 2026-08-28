<?php
/**
 * Admin Calendar View
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Admin_Calendar {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_submenu']);
    }

    public function add_submenu() {
        add_submenu_page(
            'bookflow-bookings',
            Bookflow_I18n::t('admin.booking_calendar'),
            Bookflow_I18n::t('admin.booking_calendar'),
            'manage_woocommerce',
            'bookflow-calendar',
            [$this, 'render_page']
        );
    }

    public function render_page() {
        $year  = absint($_GET['cal_year'] ?? gmdate('Y'));
        $month = absint($_GET['cal_month'] ?? gmdate('m'));

        $prev_month = $month - 1;
        $prev_year = $year;
        if ($prev_month < 1) { $prev_month = 12; $prev_year--; }

        $next_month = $month + 1;
        $next_year = $year;
        if ($next_month > 12) { $next_month = 1; $next_year++; }

        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $first_day_of_week = (int) gmdate('N', mktime(0, 0, 0, $month, 1, $year)); // 1=Mon, 7=Sun

        // Load all bookings for this month
        $bookings_raw = Bookflow_Booking::query([
            'date_from'  => sprintf('%04d-%02d-01', $year, $month),
            'date_to'    => sprintf('%04d-%02d-%02d', $year, $month, $days_in_month),
            'status_not' => ['cancelled', 'refunded'],
            'limit'      => 500,
            'orderby'    => 'start_time',
            'order'      => 'ASC',
        ]);

        // Group by date
        $bookings_by_date = [];
        foreach ($bookings_raw as $b) {
            $bookings_by_date[$b->booking_date][] = $b;
        }

        $month_names = [];
        for ($i = 1; $i <= 12; $i++) {
            $month_names[$i] = Bookflow_I18n::t('calendar.month.' . $i);
        }

        $status_colors = [
            'pending'     => '#f0ad4e',
            'confirmed'   => '#5cb85c',
            'paid'        => '#337ab7',
            'in-progress' => '#5bc0de',
            'completed'   => '#6c757d',
            'no-show'     => '#d9534f',
        ];

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php Bookflow_I18n::te('admin.booking_calendar'); ?></h1>
            <hr class="wp-header-end">

            <div class="tablenav top">
                <div class="alignleft actions" style="display:flex; align-items:center; gap:8px;">
                    <a href="<?php echo esc_url(add_query_arg(['cal_year' => $prev_year, 'cal_month' => $prev_month])); ?>" class="button">&larr;</a>
                    <a href="<?php echo esc_url(add_query_arg(['cal_year' => $next_year, 'cal_month' => $next_month])); ?>" class="button">&rarr;</a>
                    <a href="<?php echo esc_url(add_query_arg(['cal_year' => gmdate('Y'), 'cal_month' => gmdate('m')])); ?>" class="button"><?php Bookflow_I18n::te('admin.today'); ?></a>
                    <h2 class="wp-heading-inline" style="margin-left:8px;"><?php echo esc_html($month_names[$month] . ' ' . $year); ?></h2>
                </div>
            </div>

            <style>
                /* Neutral wp-admin tokens (matches .wp-list-table thead / borders) instead of a custom color scheme. */
                .bookflow-cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:1px; background:#c3c4c7; border:1px solid #c3c4c7; }
                .bookflow-cal-header { background:#f0f0f1; color:#1d2327; padding:8px; text-align:center; font-weight:600; font-size:12px; border-bottom:1px solid #c3c4c7; }
                .bookflow-cal-day { background:#fff; min-height:100px; padding:5px; vertical-align:top; }
                .bookflow-cal-day.today { background:#f0f6fc; }
                .bookflow-cal-day.empty { background:#f6f7f7; }
                .bookflow-cal-day-num { font-weight:600; font-size:13px; margin-bottom:3px; color:#1d2327; }
                .bookflow-cal-booking { display:block; font-size:11px; padding:2px 4px; margin:1px 0; border-radius:2px; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; cursor:pointer; text-decoration:none; }
                .bookflow-cal-booking:hover, .bookflow-cal-booking:focus { opacity:0.85; color:#fff; }
                .bookflow-cal-more { font-size:10px; color:#646970; padding:2px 4px; }
            </style>

            <div class="postbox" style="padding:16px;">
                <div class="bookflow-cal-grid">
                    <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day) : ?>
                        <div class="bookflow-cal-header"><?php echo esc_html($day); ?></div>
                    <?php endforeach; ?>

                    <?php
                    // Empty cells before first day
                    for ($i = 1; $i < $first_day_of_week; $i++) {
                        echo '<div class="bookflow-cal-day empty"></div>';
                    }

                    $today = gmdate('Y-m-d');

                    for ($day = 1; $day <= $days_in_month; $day++) :
                        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                        $is_today = ($date === $today);
                        $day_bookings = $bookings_by_date[$date] ?? [];
                    ?>
                        <div class="bookflow-cal-day <?php echo $is_today ? 'today' : ''; ?>">
                            <div class="bookflow-cal-day-num"><?php echo esc_html($day); ?></div>
                            <?php
                            $shown = 0;
                            foreach ($day_bookings as $b) {
                                if ($shown >= 3) {
                                    $remaining = count($day_bookings) - 3;
                                    echo '<div class="bookflow-cal-more">+' . esc_html($remaining) . ' more</div>';
                                    break;
                                }
                                $color = $status_colors[$b->status] ?? '#646970';
                                $product = wc_get_product($b->product_id);
                                $title = sprintf('%s - %s (%d pax)', $b->start_time, $b->customer_name, $b->persons_total);
                                $url = admin_url('admin.php?page=bookflow-bookings&view=' . $b->id);
                                echo '<a href="' . esc_url($url) . '" class="bookflow-cal-booking" style="background:' . esc_attr($color) . ';" title="' . esc_attr($title) . '">';
                                echo esc_html($b->start_time . ' ' . ($b->customer_name ?: ($product ? $product->get_name() : '#' . $b->id)));
                                echo '</a>';
                                $shown++;
                            }
                            ?>
                        </div>
                    <?php endfor; ?>

                    <?php
                    // Fill remaining cells
                    $total_cells = $first_day_of_week - 1 + $days_in_month;
                    $remaining_cells = (7 - ($total_cells % 7)) % 7;
                    for ($i = 0; $i < $remaining_cells; $i++) {
                        echo '<div class="bookflow-cal-day empty"></div>';
                    }
                    ?>
                </div>
            </div>

            <div style="margin-top:15px; display:flex; gap:16px; flex-wrap:wrap;">
                <?php foreach ($status_colors as $s => $c) : ?>
                    <span style="font-size:13px; color:#1d2327;"><span style="display:inline-block;width:12px;height:12px;background:<?php echo esc_attr($c); ?>;border-radius:2px;vertical-align:middle;margin-right:5px;"></span><?php echo esc_html(Bookflow_I18n::status($s)); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}

<?php
/**
 * Widgets admin table — extends core WP_List_Table (the same base class
 * Posts/Pages/Bookings use) so the Widgets screen looks and behaves like
 * every other WordPress list screen: checkbox column, hover row actions,
 * search box. The Widget Builder itself only ever renders for a single
 * widget (?view=<id> or ?view=new) — this table is just the plain-HTML
 * list you land on first, matching Bookings' own list/detail split.
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Bookflow_Widgets_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct([
            'singular' => 'widget',
            'plural'   => 'widgets',
            'ajax'     => false,
        ]);
    }

    public function get_columns() {
        return [
            'cb'       => '<input type="checkbox" />',
            'name'     => Bookflow_I18n::t('admin.widget_name'),
            'product'  => Bookflow_I18n::t('admin.linked_product'),
            'shortcode' => Bookflow_I18n::t('admin.shortcode'),
            'default'  => Bookflow_I18n::t('admin.is_default'),
        ];
    }

    protected function get_sortable_columns() {
        return [
            'name' => ['name', false],
        ];
    }

    public function get_bulk_actions() {
        return [
            'delete' => Bookflow_I18n::t('admin.delete'),
        ];
    }

    protected function column_cb($item) {
        return sprintf('<input type="checkbox" name="widget_ids[]" value="%d" />', (int) $item->id);
    }

    protected function column_name($item) {
        $edit_url = add_query_arg(['page' => 'bookflow-widgets', 'view' => $item->id], admin_url('admin.php'));
        $delete_url = wp_nonce_url(
            add_query_arg(['page' => 'bookflow-widgets', 'row_action' => 'delete', 'widget' => $item->id], admin_url('admin.php')),
            'bookflow_widget_row_action_' . $item->id
        );
        $actions = [
            'edit'   => sprintf('<a href="%s">%s</a>', esc_url($edit_url), esc_html(Bookflow_I18n::t('admin.edit'))),
            'delete' => sprintf(
                '<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
                esc_url($delete_url),
                esc_js(Bookflow_I18n::t('admin.delete_widget_confirm')),
                esc_html(Bookflow_I18n::t('admin.delete'))
            ),
        ];

        $style = json_decode($item->style, true) ?: [];
        $dot = !empty($style['accent']) ? esc_attr($style['accent']) : '#5b8fc7';

        return sprintf(
            '<span style="display:inline-block;width:10px;height:10px;border-radius:50%%;background:%s;margin-right:8px;"></span><a href="%s"><strong>%s</strong></a>%s',
            $dot,
            esc_url($edit_url),
            esc_html($item->name),
            $this->row_actions($actions)
        );
    }

    protected function column_product($item) {
        if (!$item->product_id) {
            return '&mdash;';
        }
        $product = wc_get_product($item->product_id);
        if (!$product) {
            return '&mdash;';
        }
        return sprintf(
            '<a href="%s">%s</a>',
            esc_url(get_edit_post_link($item->product_id, 'raw')),
            esc_html($product->get_name())
        );
    }

    protected function column_shortcode($item) {
        return '<code>[bookflow_widget id="' . (int) $item->id . '"]</code>';
    }

    protected function column_default($item, $column_name = null) {
        if ($column_name === 'default') {
            return $item->is_default ? '★' : '';
        }
        return isset($item->$column_name) ? esc_html($item->$column_name) : '';
    }

    public function prepare_items() {
        $per_page = 20;
        $current_page = $this->get_pagenum();

        $search = sanitize_text_field(wp_unslash($_REQUEST['s'] ?? ''));

        global $wpdb;
        $where = '1=1';
        $values = [];
        if ($search !== '') {
            $where = 'name LIKE %s';
            $values[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $orderby = sanitize_text_field(wp_unslash($_GET['orderby'] ?? 'name'));
        $order = strtolower(sanitize_text_field(wp_unslash($_GET['order'] ?? 'asc'))) === 'desc' ? 'DESC' : 'ASC';
        $orderby = in_array($orderby, ['name', 'id'], true) ? $orderby : 'name';

        $sql = "SELECT * FROM {$wpdb->prefix}bookflow_widgets WHERE $where ORDER BY $orderby $order";
        $total_items = (int) $wpdb->get_var(
            $values ? $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}bookflow_widgets WHERE $where", ...$values) : "SELECT COUNT(*) FROM {$wpdb->prefix}bookflow_widgets"
        );

        $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', $per_page, ($current_page - 1) * $per_page);
        $this->items = $values ? $wpdb->get_results($wpdb->prepare($sql, ...$values)) : $wpdb->get_results($sql);

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ]);
    }
}

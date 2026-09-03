<?php
/**
 * Widget Builder — the Widget is the primary entity: each one owns a
 * linked WooCommerce booking product (auto-created if none is picked),
 * a visual style, a wizard step flow, its own `[bookflow_widget]`
 * shortcode, and an optional outbound webhook. Resources, availability,
 * and pricing stay on the linked product's existing Booking tab (already
 * a full, working editor) rather than being duplicated in this page.
 *
 * A product with no owning widget row (or one pointing at a deleted
 * widget) falls back to DEFAULT_STYLE / the full step order below, which
 * match the widget's original hardcoded look exactly — so sites that
 * never touch the builder see zero behavior change.
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Widgets {

    // The subset of wizard steps a widget preset can reorder/toggle.
    // 'contact' and 'confirm' are intentionally excluded — a booking can't
    // be completed without collecting the customer's details and their
    // terms acceptance, so those two always run last, in that order.
    const CUSTOMIZABLE_STEPS = ['language', 'location', 'day', 'staff', 'time', 'persons'];
    const FIXED_TAIL_STEPS = ['contact', 'confirm'];

    const DEFAULT_STYLE = [
        'accent'     => '#5b8fc7',
        'accentDark' => '#3f6c9e',
        'bg'         => '#10151f',
        'bgAlt'      => '#1a2233',
        'border'     => '#2a3448',
        'radius'     => 24,
        // Container/wrapper — how the widget sits inside whatever page a
        // merchant drops the shortcode into, not the widget's own look.
        'maxWidth'    => '',        // '' = full width of its container
        'padding'     => 0,         // extra px around the widget's own p-6/p-9
        'fontFamily'  => 'inherit', // 'inherit' = whatever the host page uses
        'customClass' => '',        // extra class on the outer wrapper
        'customCss'   => '',        // raw CSS, scoped to this widget instance
    ];

    // Locales this site ships translations for (languages/*.json) — the
    // set a widget's per-language text overrides can be entered in.
    const LOCALES = ['en_US', 'ro_RO', 'ru_RU'];

    // Curated i18n keys a widget's "Text" tab exposes for per-widget,
    // per-locale override — the customer-visible strings, not every key
    // the widget happens to localize (weekday/month names etc. are left
    // to the site's own translation files, not worth a per-widget UI).
    // Grouped + human-labeled (rather than one flat list of raw camelCase
    // keys) so the Text tab reads like a real settings panel instead of a
    // dump of internal variable names.
    const TEXT_KEY_GROUPS = [
        'steps' => [
            'label' => 'admin.text_group_steps',
            'keys'  => ['stepLanguage', 'stepLocation', 'stepDay', 'stepStaff', 'stepTime', 'stepPersons', 'stepContact', 'stepConfirm'],
        ],
        'navigation' => [
            'label' => 'admin.text_group_navigation',
            'keys'  => ['wizardBack', 'wizardNext', 'selectDate', 'selectTime', 'selectLocation', 'selectStaff', 'changeLocation', 'selectSchedule', 'selectResource'],
        ],
        'contact' => [
            'label' => 'admin.text_group_contact',
            'keys'  => ['fullName', 'phone', 'notesOptional', 'contactDetails'],
        ],
        'pricing' => [
            'label' => 'admin.text_group_pricing',
            'keys'  => ['perPerson', 'total', 'pricePerPerson', 'persons', 'bookingDetails', 'extrasTitle', 'depositPaidNow', 'balanceDue'],
        ],
        'messages' => [
            'label' => 'admin.text_group_messages',
            'keys'  => ['noSlots', 'noAvailability', 'soldOut', 'errorGeneric', 'errorName', 'errorEmail', 'errorPhone', 'retry'],
        ],
        'terms_waitlist' => [
            'label' => 'admin.text_group_terms_waitlist',
            'keys'  => ['termsAgree', 'gdprAgree', 'errorGdpr', 'notifyMe', 'waitlistNameLabel', 'waitlistEmailLabel', 'waitlistPhoneLabel', 'waitlistSubmit', 'waitlistSuccess', 'waitlistError'],
        ],
    ];

    // Human-readable field label per key, shown instead of the raw
    // camelCase name in the Text tab.
    const TEXT_KEY_LABELS = [
        'stepLanguage' => 'admin.text_label_step_language', 'stepLocation' => 'admin.text_label_step_location',
        'stepDay' => 'admin.text_label_step_day', 'stepStaff' => 'admin.text_label_step_staff',
        'stepTime' => 'admin.text_label_step_time', 'stepPersons' => 'admin.text_label_step_persons',
        'stepContact' => 'admin.text_label_step_contact', 'stepConfirm' => 'admin.text_label_step_confirm',
        'wizardBack' => 'admin.text_label_wizard_back', 'wizardNext' => 'admin.text_label_wizard_next',
        'selectDate' => 'admin.text_label_select_date', 'selectTime' => 'admin.text_label_select_time',
        'selectLocation' => 'admin.text_label_select_location', 'selectStaff' => 'admin.text_label_select_staff',
        'changeLocation' => 'admin.text_label_change_location', 'selectSchedule' => 'admin.text_label_select_schedule',
        'selectResource' => 'admin.text_label_select_resource',
        'fullName' => 'admin.text_label_full_name', 'phone' => 'admin.text_label_phone',
        'notesOptional' => 'admin.text_label_notes', 'contactDetails' => 'admin.text_label_contact_details',
        'perPerson' => 'admin.text_label_per_person', 'total' => 'admin.text_label_total',
        'pricePerPerson' => 'admin.text_label_price_per_person', 'persons' => 'admin.text_label_persons',
        'bookingDetails' => 'admin.text_label_booking_details', 'extrasTitle' => 'admin.text_label_extras_title',
        'depositPaidNow' => 'admin.text_label_deposit_paid_now', 'balanceDue' => 'admin.text_label_balance_due',
        'noSlots' => 'admin.text_label_no_slots', 'noAvailability' => 'admin.text_label_no_availability',
        'soldOut' => 'admin.text_label_sold_out', 'errorGeneric' => 'admin.text_label_error_generic',
        'errorName' => 'admin.text_label_error_name', 'errorEmail' => 'admin.text_label_error_email',
        'errorPhone' => 'admin.text_label_error_phone', 'retry' => 'admin.text_label_retry',
        'termsAgree' => 'admin.text_label_terms_agree', 'gdprAgree' => 'admin.text_label_gdpr_agree',
        'errorGdpr' => 'admin.text_label_error_gdpr', 'notifyMe' => 'admin.text_label_notify_me',
        'waitlistNameLabel' => 'admin.text_label_waitlist_name', 'waitlistEmailLabel' => 'admin.text_label_waitlist_email',
        'waitlistPhoneLabel' => 'admin.text_label_waitlist_phone', 'waitlistSubmit' => 'admin.text_label_waitlist_submit',
        'waitlistSuccess' => 'admin.text_label_waitlist_success', 'waitlistError' => 'admin.text_label_waitlist_error',
    ];

    // Flat allowlist, derived from the groups above — this is what
    // sanitize_text() actually validates saved overrides against.
    const TEXT_KEYS = [
        'stepLanguage', 'stepLocation', 'stepDay', 'stepStaff', 'stepTime',
        'stepPersons', 'stepContact', 'stepConfirm',
        'wizardBack', 'wizardNext',
        'selectDate', 'selectTime', 'selectLocation', 'selectStaff', 'changeLocation',
        'selectSchedule', 'selectResource',
        'fullName', 'phone', 'notesOptional', 'contactDetails',
        'perPerson', 'total', 'pricePerPerson', 'persons', 'bookingDetails', 'extrasTitle',
        'noSlots', 'noAvailability', 'soldOut', 'errorGeneric', 'errorName', 'errorEmail',
        'errorPhone', 'retry',
        'termsAgree', 'gdprAgree', 'errorGdpr',
        'notifyMe', 'waitlistNameLabel', 'waitlistEmailLabel', 'waitlistPhoneLabel',
        'waitlistSubmit', 'waitlistSuccess', 'waitlistError',
        'depositPaidNow', 'balanceDue',
    ];

    public function __construct() {
        add_action('admin_menu', [$this, 'add_submenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_bookflow_list_widgets', [$this, 'ajax_list']);
        add_action('wp_ajax_bookflow_save_widget', [$this, 'ajax_save']);
        add_action('wp_ajax_bookflow_delete_widget', [$this, 'ajax_delete']);
        add_action('wp_ajax_bookflow_widget_unlinked_products', [$this, 'ajax_unlinked_products']);
        add_action('wp_ajax_bookflow_test_webhook', [$this, 'ajax_test_webhook']);
        add_shortcode('bookflow_widget', [$this, 'render_shortcode']);
        add_action('bookflow_booking_created', [$this, 'dispatch_webhook_created'], 10, 2);
        add_action('bookflow_booking_status_changed', [$this, 'dispatch_webhook_status_changed'], 10, 3);
        add_filter('admin_body_class', [$this, 'admin_body_class']);
    }

    /**
     * The single-widget builder (?view=<id>|new) hides wp-admin's own
     * sidebar/toolbar for a full-canvas view — the same "go fullscreen for
     * the editor" pattern Elementor/Bricks use. Scoped to a body class so
     * it never touches the plain Widgets list screen.
     */
    public function admin_body_class($classes) {
        if (isset($_GET['page']) && $_GET['page'] === 'bookflow-widgets' && !empty($_GET['view'])) {
            $classes .= ' bookflow-widget-builder-fullscreen';
        }
        return $classes;
    }

    public function add_submenu() {
        add_submenu_page(
            'bookflow-bookings',
            Bookflow_I18n::t('admin.widgets'),
            Bookflow_I18n::t('admin.widgets'),
            'manage_woocommerce',
            'bookflow-widgets',
            [$this, 'render_page']
        );
    }

    public function enqueue($hook) {
        // Only the single-widget builder screen (?view=<id>|new) needs the
        // Svelte bundle — the plain list view is a native WP_List_Table,
        // same as Bookings' own list, with zero JS of its own.
        if (strpos($hook, 'bookflow-widgets') === false || empty($_GET['view'])) {
            return;
        }
        $bundle_js = BOOKFLOW_PLUGIN_DIR . 'admin/dist/admin-widgets.js';
        if (!file_exists($bundle_js)) {
            return;
        }
        $bundle_css = BOOKFLOW_PLUGIN_DIR . 'admin/dist/admin-widgets.css';
        if (file_exists($bundle_css)) {
            // Versioned by file mtime (not BOOKFLOW_VERSION) so every
            // `npm run build` busts the browser cache immediately — a
            // static version string here left browsers serving a stale
            // bundle after a rebuild until a hard refresh.
            wp_enqueue_style('bookflow-admin-widgets', BOOKFLOW_PLUGIN_URL . 'admin/dist/admin-widgets.css', [], filemtime($bundle_css));
        }
        wp_enqueue_script('bookflow-admin-widgets', BOOKFLOW_PLUGIN_URL . 'admin/dist/admin-widgets.js', [], filemtime($bundle_js), true);

        $step_labels = [];
        foreach (array_merge(self::CUSTOMIZABLE_STEPS, self::FIXED_TAIL_STEPS) as $step) {
            $step_labels[$step] = Bookflow_I18n::t('wizard.step_' . $step);
        }

        // English fallback for every overridable text key, shown as the
        // placeholder in the Text tab so an empty override visibly reads
        // "this is what customers see by default" rather than a blank box.
        $text_key_defaults = [];
        $text_key_i18n_map = [
            'stepLanguage' => 'wizard.step_language', 'stepLocation' => 'wizard.step_location',
            'stepDay' => 'wizard.step_day', 'stepStaff' => 'wizard.step_staff',
            'stepTime' => 'wizard.step_time', 'stepPersons' => 'wizard.step_persons',
            'stepContact' => 'wizard.step_contact', 'stepConfirm' => 'wizard.step_confirm',
            'wizardBack' => 'wizard.back', 'wizardNext' => 'wizard.next',
            'selectDate' => 'calendar.select_date', 'selectTime' => 'calendar.select_time',
            'selectLocation' => 'form.select_location', 'selectStaff' => 'form.select_resource',
            'changeLocation' => 'form.change_location', 'selectSchedule' => 'calendar.select_option',
            'selectResource' => 'calendar.select_resource',
            'fullName' => 'form.full_name', 'phone' => 'form.phone',
            'notesOptional' => 'form.notes_optional', 'contactDetails' => 'form.contact_details',
            'perPerson' => 'calendar.per_person', 'total' => 'calendar.total',
            'pricePerPerson' => 'form.price_per_person', 'persons' => 'form.persons',
            'bookingDetails' => 'form.booking_details', 'extrasTitle' => 'form.extras_title',
            'noSlots' => 'calendar.no_slots', 'noAvailability' => 'calendar.no_availability',
            'soldOut' => 'calendar.sold_out', 'errorGeneric' => 'calendar.error_generic',
            'errorName' => 'form.error_name', 'errorEmail' => 'form.error_email',
            'errorPhone' => 'form.error_phone', 'retry' => 'calendar.retry',
            'termsAgree' => 'form.terms_agree', 'gdprAgree' => 'form.gdpr_agree',
            'errorGdpr' => 'form.error_gdpr',
            'notifyMe' => 'calendar.notify_me', 'waitlistNameLabel' => 'calendar.waitlist_name',
            'waitlistEmailLabel' => 'calendar.waitlist_email', 'waitlistPhoneLabel' => 'calendar.waitlist_phone',
            'waitlistSubmit' => 'calendar.waitlist_submit', 'waitlistSuccess' => 'calendar.waitlist_success',
            'waitlistError' => 'calendar.waitlist_error',
            'depositPaidNow' => 'cart.deposit_paid_now', 'balanceDue' => 'cart.balance_due',
        ];
        $locale_names = [];
        foreach (self::LOCALES as $locale) {
            foreach (self::TEXT_KEYS as $key) {
                $text_key_defaults[$locale][$key] = isset($text_key_i18n_map[$key])
                    ? Bookflow_I18n::t_locale($text_key_i18n_map[$key], $locale)
                    : $key;
            }
            $locale_names[$locale] = Bookflow_I18n::t_locale('_locale_name', $locale);
        }

        $text_key_labels = [];
        foreach (self::TEXT_KEY_LABELS as $key => $label_key) {
            $text_key_labels[$key] = Bookflow_I18n::t($label_key);
        }
        $text_key_groups = [];
        foreach (self::TEXT_KEY_GROUPS as $group_key => $group) {
            $text_key_groups[] = [
                'key'   => $group_key,
                'label' => Bookflow_I18n::t($group['label']),
                'keys'  => $group['keys'],
            ];
        }

        $view_param = sanitize_text_field(wp_unslash($_GET['view']));

        wp_localize_script('bookflow-admin-widgets', 'bookflowAdminWidgets', [
            'ajaxUrl'           => admin_url('admin-ajax.php'),
            'nonce'             => wp_create_nonce('bookflow_admin_nonce'),
            'mode'              => $view_param === 'new' ? 'new' : 'edit',
            'widgetId'          => $view_param === 'new' ? 0 : absint($view_param),
            'listUrl'           => admin_url('admin.php?page=bookflow-widgets'),
            'defaultStyle'      => self::DEFAULT_STYLE,
            'customizableSteps' => self::CUSTOMIZABLE_STEPS,
            'fixedTailSteps'    => self::FIXED_TAIL_STEPS,
            'stepLabels'        => $step_labels,
            'editProductUrlBase' => admin_url('post.php?action=edit&post='),
            'locales'           => self::LOCALES,
            'localeNames'       => $locale_names,
            'textKeys'          => self::TEXT_KEYS,
            'textKeyDefaults'   => $text_key_defaults,
            'textKeyLabels'     => $text_key_labels,
            'textKeyGroups'     => $text_key_groups,
            'fontChoices'       => self::FONT_CHOICES,
            'i18n'              => [
                'widgetBuilder'   => Bookflow_I18n::t('admin.widget_builder'),
                'widgetBuilderDesc' => Bookflow_I18n::t('admin.widget_builder_desc'),
                'widgets'         => Bookflow_I18n::t('admin.widgets'),
                'addWidget'       => Bookflow_I18n::t('admin.add_widget'),
                'widgetName'      => Bookflow_I18n::t('admin.widget_name'),
                'widgetNameRequired' => Bookflow_I18n::t('admin.widget_name_required'),
                'customized'      => Bookflow_I18n::t('admin.customized'),
                'widgetNamePlaceholder' => Bookflow_I18n::t('admin.widget_name_placeholder'),
                'style'           => Bookflow_I18n::t('admin.style'),
                'steps'           => Bookflow_I18n::t('admin.steps'),
                'accentColor'     => Bookflow_I18n::t('admin.accent_color'),
                'accentColorDark' => Bookflow_I18n::t('admin.accent_color_dark'),
                'backgroundColor' => Bookflow_I18n::t('admin.background_color'),
                'backgroundColorAlt' => Bookflow_I18n::t('admin.background_color_alt'),
                'borderColor'     => Bookflow_I18n::t('admin.border_color'),
                'cornerRadius'    => Bookflow_I18n::t('admin.corner_radius'),
                'preview'         => Bookflow_I18n::t('admin.preview'),
                'save'            => Bookflow_I18n::t('admin.save'),
                'saving'          => Bookflow_I18n::t('admin.saving'),
                'delete'          => Bookflow_I18n::t('admin.delete'),
                'cancel'          => Bookflow_I18n::t('admin.cancel_edit'),
                'deleteConfirm'   => Bookflow_I18n::t('admin.delete_widget_confirm'),
                'setAsDefault'    => Bookflow_I18n::t('admin.set_as_default'),
                'isDefault'       => Bookflow_I18n::t('admin.is_default'),
                'noWidgets'       => Bookflow_I18n::t('admin.no_widgets'),
                'errorGeneric'    => Bookflow_I18n::t('calendar.error_generic'),
                'stepsHelp'       => Bookflow_I18n::t('admin.steps_help'),
                'stepsAlwaysLastNote' => Bookflow_I18n::t('admin.steps_always_last_note'),
                'linkedProduct'   => Bookflow_I18n::t('admin.linked_product'),
                'createNew'       => Bookflow_I18n::t('admin.create_new'),
                'linkExistingProduct' => Bookflow_I18n::t('admin.link_existing_product'),
                'manageResources' => Bookflow_I18n::t('admin.manage_resources'),
                'shortcode'       => Bookflow_I18n::t('admin.shortcode'),
                'shortcodeHelp'   => Bookflow_I18n::t('admin.shortcode_help'),
                'webhookUrl'      => Bookflow_I18n::t('admin.webhook_url'),
                'webhookUrlPlaceholder' => Bookflow_I18n::t('admin.webhook_url_placeholder'),
                'webhookUrlHelp'  => Bookflow_I18n::t('admin.webhook_url_help'),
                'copy'            => Bookflow_I18n::t('admin.copy'),
                'testWebhook'     => Bookflow_I18n::t('admin.test_webhook'),
                'testingWebhook'  => Bookflow_I18n::t('admin.testing_webhook'),
                'webhookTestSuccess' => Bookflow_I18n::t('admin.webhook_test_success'),
                'tabStyle'        => Bookflow_I18n::t('admin.tab_style'),
                'tabContainer'    => Bookflow_I18n::t('admin.tab_container'),
                'tabText'         => Bookflow_I18n::t('admin.tab_text'),
                'tabSteps'        => Bookflow_I18n::t('admin.tab_steps'),
                'tabIntegrations' => Bookflow_I18n::t('admin.tab_integrations'),
                'maxWidth'        => Bookflow_I18n::t('admin.max_width'),
                'maxWidthPlaceholder' => Bookflow_I18n::t('admin.max_width_placeholder'),
                'maxWidthHelp'    => Bookflow_I18n::t('admin.max_width_help'),
                'padding'         => Bookflow_I18n::t('admin.padding'),
                'fontFamily'      => Bookflow_I18n::t('admin.font_family'),
                'customClass'     => Bookflow_I18n::t('admin.custom_class'),
                'customClassPlaceholder' => Bookflow_I18n::t('admin.custom_class_placeholder'),
                'customCss'       => Bookflow_I18n::t('admin.custom_css'),
                'customCssHelp'   => Bookflow_I18n::t('admin.custom_css_help'),
                'textTabHelp'     => Bookflow_I18n::t('admin.text_tab_help'),
                'textPlaceholderIsDefault' => Bookflow_I18n::t('admin.text_placeholder_is_default'),
                'livePreview'     => Bookflow_I18n::t('admin.live_preview'),
                'openInNewTab'    => Bookflow_I18n::t('admin.open_in_new_tab'),
                'noProductYet'    => Bookflow_I18n::t('admin.no_product_yet'),
                'resetOverride'   => Bookflow_I18n::t('admin.reset_override'),
            ],
        ]);
    }

    public function render_page() {
        // Detail/builder view: ?view=<id> (edit) or ?view=new (create).
        if (!empty($_GET['view'])) {
            $this->render_builder_page();
            return;
        }

        // Single row_action=delete link from the list table (nonced GET,
        // same pattern as Bookings' trash/restore/delete row actions).
        if (!empty($_GET['row_action']) && $_GET['row_action'] === 'delete' && !empty($_GET['widget'])) {
            $widget_id = absint($_GET['widget']);
            if (wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'bookflow_widget_row_action_' . $widget_id)) {
                self::delete($widget_id);
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(Bookflow_I18n::t('admin.widget_deleted')) . '</p></div>';
            }
        }

        // Bulk delete from the list table's own <form method="post"> —
        // WP_List_Table posts here with its own bulk-<plural> nonce.
        if (!empty($_POST['widget_ids']) && !empty($_POST['action']) && $_POST['action'] === 'delete') {
            check_admin_referer('bulk-widgets');
            foreach ((array) $_POST['widget_ids'] as $id) {
                self::delete(absint($id));
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(Bookflow_I18n::t('admin.widget_deleted')) . '</p></div>';
        }

        $table = new Bookflow_Widgets_List_Table();
        $table->prepare_items();
        $add_url = add_query_arg(['page' => 'bookflow-widgets', 'view' => 'new'], admin_url('admin.php'));
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php Bookflow_I18n::te('admin.widgets'); ?></h1>
            <a href="<?php echo esc_url($add_url); ?>" class="page-title-action"><?php Bookflow_I18n::te('admin.add_widget'); ?></a>
            <hr class="wp-header-end">
            <p class="description"><?php Bookflow_I18n::te('admin.widget_builder_desc'); ?></p>
            <form method="post">
                <input type="hidden" name="page" value="bookflow-widgets">
                <?php
                $table->search_box(Bookflow_I18n::t('admin.search'), 'widget');
                $table->display();
                ?>
            </form>
        </div>
        <?php
    }

    private function render_builder_page() {
        ?>
        <style>
            /* Full-canvas builder, same pattern Elementor/Bricks use for
               their editor screen: hide wp-admin's own toolbar and sidebar
               so the builder gets the whole viewport, with its own compact
               header replacing them (below) for navigation back out.
               render_builder_page() only ever runs for ?view=<id>|new, so
               this whole block is already page-scoped by PHP — no need to
               additionally gate each rule behind a body class in CSS (and
               `body.foo html.wp-toolbar` never matched anything anyway:
               <html> is body's ancestor, never its descendant, so that
               selector was physically unmatchable — the real bug behind
               the leftover gap at the top of the page). */
            #wpadminbar,
            #adminmenumain,
            #wpfooter,
            .update-nag,
            .notice {
                display: none !important;
            }
            html.wp-toolbar {
                padding-top: 0 !important;
            }
            #wpcontent,
            #wpbody-content {
                margin-left: 0;
                padding-left: 0;
                padding-bottom: 0;
            }
            /* The whole chain from <html> down to the root needs an actual
               height for the builder's own `h-full` flex layout to fill —
               a flex child can't be "full height" of a parent whose height
               is just "as tall as its content" (the default), which is why
               the two panels were shrink-wrapping to their content instead
               of filling the window. */
            html, body {
                height: 100%;
            }
            #wpwrap, #wpcontent, #wpbody, #wpbody-content {
                height: 100%;
            }
            .wrap {
                margin: 0;
                height: 100%;
                display: flex;
                flex-direction: column;
                box-sizing: border-box;
            }
            .bookflow-builder-topbar {
                flex-shrink: 0;
                display: flex;
                align-items: center;
                gap: 8px;
                height: 46px;
                padding: 0 16px;
                background: #1d2327;
                border-bottom: 1px solid #2c3338;
            }
            .bookflow-builder-topbar a {
                display: flex;
                align-items: center;
                gap: 6px;
                color: #f0f0f1;
                text-decoration: none;
                font-size: 13px;
                font-weight: 500;
            }
            .bookflow-builder-topbar a:hover {
                color: #72aee6;
            }
            #bookflow-admin-widgets-root {
                flex: 1;
                min-height: 0;
                padding: 20px;
                box-sizing: border-box;
            }
        </style>
        <div class="wrap">
            <div class="bookflow-builder-topbar">
                <a href="<?php echo esc_url(admin_url('admin.php?page=bookflow-widgets')); ?>">&larr; <?php Bookflow_I18n::te('admin.widgets'); ?></a>
            </div>
            <div id="bookflow-admin-widgets-root">
                <?php if (!file_exists(BOOKFLOW_PLUGIN_DIR . 'admin/dist/admin-widgets.js')) : ?>
                    <p><em>Run <code>npm run build</code> in <code>svelte-src/</code> to build the widget builder.</em></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // --- Shortcode: [bookflow_widget id="5"] renders that widget's linked
    // product's booking form, independent of the current post/page. ---

    public function render_shortcode($atts) {
        $atts = shortcode_atts(['id' => 0], $atts, 'bookflow_widget');
        $widget = self::get(absint($atts['id']));
        if (!$widget || !$widget->product_id) {
            return '';
        }
        $product = wc_get_product($widget->product_id);
        if (!$product || $product->get_type() !== 'booking') {
            return '';
        }
        $frontend = Bookflow_Frontend::instance();
        return $frontend ? $frontend->render_for_product($product) : '';
    }

    // --- Webhooks ---

    public function dispatch_webhook_created($data, $booking_id) {
        $product_id = absint($data['product_id'] ?? 0);
        $this->maybe_dispatch($product_id, [
            'event'      => 'booking.created',
            'booking_id' => (int) $booking_id,
        ]);
    }

    public function dispatch_webhook_status_changed($booking_id, $old_status, $new_status) {
        $booking = Bookflow_Booking::get($booking_id);
        if (!$booking) {
            return;
        }
        $this->maybe_dispatch((int) $booking->product_id, [
            'event'      => 'booking.status_changed',
            'booking_id' => (int) $booking_id,
            'old_status' => $old_status,
            'new_status' => $new_status,
        ]);
    }

    private function maybe_dispatch($product_id, $payload) {
        if (!$product_id) {
            return;
        }
        $widget = self::get_by_product_id($product_id);
        if (!$widget || empty($widget->webhook_url)) {
            return;
        }
        $payload['widget_id'] = (int) $widget->id;
        $payload['timestamp'] = current_time('mysql');

        wp_remote_post($widget->webhook_url, [
            'timeout'  => 5,
            // Non-blocking: a slow/unreachable webhook endpoint must never
            // delay the checkout or admin request that triggered it.
            'blocking' => false,
            'headers'  => ['Content-Type' => 'application/json'],
            'body'     => wp_json_encode($payload),
        ]);
    }

    // --- AJAX ---

    public function ajax_list() {
        check_ajax_referer('bookflow_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $rows = self::get_all();
        wp_send_json_success(['items' => array_map([__CLASS__, 'prepare'], $rows)]);
    }

    /**
     * Booking-type products not already owned by another widget, for the
     * builder's "link existing product" picker.
     */
    public function ajax_unlinked_products() {
        check_ajax_referer('bookflow_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        global $wpdb;
        $linked_ids = $wpdb->get_col("SELECT product_id FROM {$wpdb->prefix}bookflow_widgets WHERE product_id IS NOT NULL");
        $products = function_exists('wc_get_products') ? wc_get_products([
            'limit' => -1, 'type' => ['booking'], 'orderby' => 'title', 'order' => 'ASC',
        ]) : [];
        $result = [];
        foreach ($products as $p) {
            if (in_array($p->get_id(), array_map('intval', $linked_ids), true)) {
                continue;
            }
            $result[] = ['id' => $p->get_id(), 'name' => $p->get_name() . ' (#' . $p->get_id() . ')'];
        }
        wp_send_json_success(['items' => $result]);
    }

    public function ajax_save() {
        check_ajax_referer('bookflow_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $id = absint($_POST['id'] ?? 0);
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        if ($name === '') {
            wp_send_json_error(['message' => Bookflow_I18n::t('admin.widget_name')]);
        }

        $style_raw = isset($_POST['style']) ? json_decode(wp_unslash($_POST['style']), true) : [];
        $style = self::sanitize_style(is_array($style_raw) ? $style_raw : []);

        $steps_raw = isset($_POST['steps']) ? json_decode(wp_unslash($_POST['steps']), true) : [];
        $steps = self::sanitize_steps(is_array($steps_raw) ? $steps_raw : []);

        $text_raw = isset($_POST['text']) ? json_decode(wp_unslash($_POST['text']), true) : [];
        $text = self::sanitize_text(is_array($text_raw) ? $text_raw : []);

        $is_default = !empty($_POST['is_default']) ? 1 : 0;

        $webhook_url = '';
        if (!empty($_POST['webhook_url'])) {
            $webhook_url = esc_url_raw(sanitize_text_field(wp_unslash($_POST['webhook_url'])));
        }

        $data = [
            'name'        => $name,
            'style'       => wp_json_encode($style),
            'steps'       => wp_json_encode($steps),
            'text'        => wp_json_encode($text),
            'is_default'  => $is_default,
            'webhook_url' => $webhook_url ?: null,
        ];

        $link_product_id = absint($_POST['link_product_id'] ?? 0);

        // A product can only ever belong to one widget — otherwise
        // resolve_for_product() would have two candidate rows and silently
        // pick whichever the query happens to return first.
        if ($link_product_id) {
            $existing_owner = self::get_by_product_id($link_product_id);
            if ($existing_owner && (int) $existing_owner->id !== $id) {
                wp_send_json_error(['message' => Bookflow_I18n::t('admin.product_already_linked')]);
            }
        }

        if ($id) {
            if ($link_product_id) {
                $data['product_id'] = $link_product_id;
            }
            self::update($id, $data);
        } else {
            $product_id = $link_product_id ?: self::create_linked_product($name);
            if (is_wp_error($product_id)) {
                wp_send_json_error(['message' => $product_id->get_error_message()]);
            }
            $data['product_id'] = $product_id;
            $id = self::create($data);
        }

        wp_send_json_success(['id' => $id]);
    }

    /**
     * Fires the webhook synchronously (unlike the real event dispatch,
     * which is fire-and-forget) so the admin can see pass/fail immediately
     * when testing their endpoint from the builder.
     */
    public function ajax_test_webhook() {
        check_ajax_referer('bookflow_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $url = esc_url_raw(sanitize_text_field(wp_unslash($_POST['webhook_url'] ?? '')));
        if (!$url) {
            wp_send_json_error(['message' => Bookflow_I18n::t('admin.webhook_url')]);
        }

        $response = wp_remote_post($url, [
            'timeout'  => 8,
            'blocking' => true,
            'headers'  => ['Content-Type' => 'application/json'],
            'body'     => wp_json_encode([
                'event'      => 'webhook.test',
                'booking_id' => 0,
                'timestamp'  => current_time('mysql'),
            ]),
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status >= 200 && $status < 300) {
            wp_send_json_success(['status' => $status]);
        }
        wp_send_json_error(['message' => sprintf(Bookflow_I18n::t('admin.webhook_test_failed'), $status)]);
    }

    public function ajax_delete() {
        check_ajax_referer('bookflow_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $id = absint($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error(['message' => 'Invalid ID']);
        }
        self::delete($id);
        wp_send_json_success();
    }

    // --- CRUD ---

    public static function get_all() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}bookflow_widgets ORDER BY id ASC");
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bookflow_widgets WHERE id = %d", $id));
    }

    public static function get_by_product_id($product_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bookflow_widgets WHERE product_id = %d", $product_id));
    }

    public static function create($data) {
        global $wpdb;
        if (!empty($data['is_default'])) {
            self::clear_default();
        }
        $wpdb->insert($wpdb->prefix . 'bookflow_widgets', $data);
        return (int) $wpdb->insert_id;
    }

    public static function update($id, $data) {
        global $wpdb;
        if (!empty($data['is_default'])) {
            self::clear_default();
        }
        $wpdb->update($wpdb->prefix . 'bookflow_widgets', $data, ['id' => $id]);
        return true;
    }

    public static function delete($id) {
        global $wpdb;
        // The linked WC product is deliberately left alone — deleting the
        // widget preset shouldn't delete a real product with its own order
        // history; it just stops having an owning widget.
        $wpdb->delete($wpdb->prefix . 'bookflow_widgets', ['id' => $id]);
    }

    private static function clear_default() {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'bookflow_widgets', ['is_default' => 0], ['is_default' => 1]);
    }

    /**
     * Auto-provision a minimal bookable WC product for a new widget that
     * wasn't linked to an existing one — hidden from the shop catalog
     * (reached via the widget's shortcode or a direct link, not browsing)
     * with sane starter defaults the merchant can refine on its own edit
     * screen afterward.
     */
    private static function create_linked_product($name) {
        if (!class_exists('WC_Product_Booking')) {
            return new WP_Error('no_product_type', 'WooCommerce booking product type unavailable.');
        }
        $product = new WC_Product_Booking();
        $product->set_name($name);
        $product->set_status('publish');
        $product->set_catalog_visibility('hidden');
        $product_id = $product->save();
        if (!$product_id) {
            return new WP_Error('product_create_failed', 'Could not create the linked product.');
        }
        update_post_meta($product_id, '_bookflow_min_persons', 1);
        update_post_meta($product_id, '_bookflow_max_persons', 10);
        update_post_meta($product_id, '_bookflow_duration', 60);
        update_post_meta($product_id, '_bookflow_base_price', 0);
        update_post_meta($product_id, '_bookflow_time_slots', "09:00\n11:00\n14:00");
        return $product_id;
    }

    private static function prepare($row) {
        $product = $row->product_id ? wc_get_product($row->product_id) : null;
        return [
            'id'          => (int) $row->id,
            'name'        => $row->name,
            'style'       => self::sanitize_style((array) json_decode($row->style, true)),
            'steps'       => self::sanitize_steps((array) json_decode($row->steps, true)),
            'text'        => self::sanitize_text((array) json_decode($row->text, true)),
            'is_default'  => (bool) $row->is_default,
            'product_id'  => $row->product_id ? (int) $row->product_id : null,
            'product_name' => $product ? $product->get_name() : null,
            'product_permalink' => $product ? $product->get_permalink() : null,
            'edit_product_url' => $row->product_id ? get_edit_post_link($row->product_id, 'raw') : null,
            'webhook_url' => $row->webhook_url ?: '',
            'shortcode'   => '[bookflow_widget id="' . (int) $row->id . '"]',
        ];
    }

    const FONT_CHOICES = [
        'inherit', 'system-ui', 'Georgia, serif', "'Poppins', sans-serif",
        "'Playfair Display', serif", "'Roboto', sans-serif", "'Inter', sans-serif",
    ];

    private static function sanitize_style($style) {
        $out = self::DEFAULT_STYLE;
        foreach (['accent', 'accentDark', 'bg', 'bgAlt', 'border'] as $key) {
            if (!empty($style[$key]) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $style[$key])) {
                $out[$key] = $style[$key];
            }
        }
        if (isset($style['radius'])) {
            $out['radius'] = max(0, min(48, absint($style['radius'])));
        }
        if (isset($style['maxWidth'])) {
            $val = trim((string) $style['maxWidth']);
            // Only a bare CSS length is accepted (px/%/rem/em) — enough for
            // "cap the widget's width", not a place to smuggle arbitrary CSS.
            $out['maxWidth'] = preg_match('/^\d+(\.\d+)?(px|%|rem|em)$/', $val) ? $val : '';
        }
        if (isset($style['padding'])) {
            $out['padding'] = max(0, min(120, absint($style['padding'])));
        }
        if (isset($style['fontFamily']) && in_array($style['fontFamily'], self::FONT_CHOICES, true)) {
            $out['fontFamily'] = $style['fontFamily'];
        }
        if (isset($style['customClass'])) {
            // sanitize_html_class strips anything that isn't a valid single
            // CSS class token; a merchant wanting multiple classes can still
            // target the widget's own stable #bookflow-booking-form id.
            $out['customClass'] = sanitize_html_class(trim((string) $style['customClass']));
        }
        if (isset($style['customCss'])) {
            // Free-form CSS is inherently attacker-controllable if a lower-
            // trust role ever gets this capability, but it's injected via a
            // <style> tag (never interpreted as HTML/JS) and this action is
            // already gated to manage_woocommerce — stripping tags blocks
            // the one realistic escape (a literal </style> breaking out).
            $out['customCss'] = wp_strip_all_tags((string) $style['customCss']);
        }
        return $out;
    }

    /**
     * Per-locale text overrides: { "en_US": { "stepDay": "...", ... }, ... }
     * — keeps only known locales and known override keys with non-empty
     * string values, so a widget's saved text can only ever add labels the
     * frontend actually knows how to render, never arbitrary keys.
     */
    private static function sanitize_text($text) {
        $out = [];
        foreach (self::LOCALES as $locale) {
            if (empty($text[$locale]) || !is_array($text[$locale])) {
                continue;
            }
            $locale_out = [];
            foreach (self::TEXT_KEYS as $key) {
                if (!empty($text[$locale][$key]) && is_string($text[$locale][$key])) {
                    $locale_out[$key] = sanitize_text_field($text[$locale][$key]);
                }
            }
            if (!empty($locale_out)) {
                $out[$locale] = $locale_out;
            }
        }
        return $out;
    }

    /**
     * Keep only known customizable step keys (dropping anything stale from
     * an older step set), de-duplicate, and always append the two fixed
     * tail steps in their fixed order — a saved config can never omit or
     * reorder Contact/Confirm.
     */
    private static function sanitize_steps($steps) {
        $steps = array_values(array_unique(array_intersect((array) $steps, self::CUSTOMIZABLE_STEPS)));
        if (empty($steps)) {
            $steps = self::CUSTOMIZABLE_STEPS;
        }
        return array_merge($steps, self::FIXED_TAIL_STEPS);
    }

    /**
     * Resolve the widget config a product should render with: the widget
     * that owns it if any, else the site's default widget, else the
     * hardcoded fallback that matches the widget's original look.
     *
     * `text` is pre-resolved to a flat {key: value} map for the current
     * request's locale (Bookflow_I18n::current_locale()) — the frontend
     * just merges it straight over its own i18n defaults, no per-locale
     * lookup logic needed client-side.
     */
    public static function resolve_for_product($product_id) {
        $row = self::get_by_product_id($product_id);

        if (!$row) {
            global $wpdb;
            $row = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}bookflow_widgets WHERE is_default = 1 LIMIT 1");
        }

        if (!$row) {
            return [
                'style' => self::DEFAULT_STYLE,
                'steps' => array_merge(self::CUSTOMIZABLE_STEPS, self::FIXED_TAIL_STEPS),
                'text'  => [],
            ];
        }

        $text = self::sanitize_text((array) json_decode($row->text, true));
        $locale = Bookflow_I18n::current_locale();

        return [
            'style' => self::sanitize_style((array) json_decode($row->style, true)),
            'steps' => self::sanitize_steps((array) json_decode($row->steps, true)),
            'text'  => $text[$locale] ?? [],
        ];
    }
}

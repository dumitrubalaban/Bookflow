<?php
/**
 * Booking Form Template — step wizard
 *
 * @package DailyBookingBox
 */

if (!defined('ABSPATH')) {
    exit;
}

global $product;
$product_id     = $product->get_id();
$min_persons    = $product->get_min_persons();
$max_persons    = $product->get_max_persons();
$has_person_types = Bookflow_Person_Types::product_has_types($product_id);
$person_types   = $has_person_types ? Bookflow_Person_Types::get_for_product($product_id) : [];
$has_resources  = $product->has_resources();
$terms_text     = get_post_meta($product_id, '_bookflow_terms_text', true);
$extras         = Bookflow_Extras::get_all('active');
?>

<div class="bookflow-booking-form bookflow-wizard" id="bookflow-booking-form">

    <!-- Wizard progress stepper, populated by JS from WIZARD_STEPS -->
    <div class="bookflow-wizard-stepper" id="bookflow-wizard-stepper"></div>

    <!-- Language -->
    <div class="bookflow-wizard-step" data-step="language" id="bookflow-step-language">
        <h3 class="bookflow-section-title"><?php Bookflow_I18n::te('wizard.step_language'); ?></h3>
        <input type="hidden" id="bookflow-language" value="">
        <div class="bookflow-custom-select" id="bookflow-lang-select">
            <div class="bookflow-custom-select__trigger"><span><?php Bookflow_I18n::te('calendar.select_option'); ?></span>
                <svg width="12" height="7" viewBox="0 0 12 7" fill="none"><path d="M1 1l5 5 5-5" stroke="currentColor" stroke-width="1.5"/></svg>
            </div>
            <div class="bookflow-custom-select__options" id="bookflow-lang-options"></div>
        </div>
    </div>

    <!-- Location -->
    <div class="bookflow-wizard-step" data-step="location" id="bookflow-step-location">
        <h3 class="bookflow-section-title"><?php Bookflow_I18n::te('form.select_location'); ?></h3>
        <div class="bookflow-locations-grid" id="bookflow-locations-grid"></div>
        <input type="hidden" name="bookflow_location_tag" id="bookflow-location-tag" value="">
    </div>

    <!-- Day -->
    <div class="bookflow-wizard-step" data-step="day" id="bookflow-step-day">
        <h3 class="bookflow-section-title"><?php Bookflow_I18n::te('form.select_date'); ?></h3>
        <div class="bookflow-calendar" id="bookflow-calendar">
            <div class="bookflow-calendar-header">
                <button type="button" class="bookflow-cal-prev" id="bookflow-cal-prev">&larr;</button>
                <span class="bookflow-cal-month" id="bookflow-cal-month"></span>
                <button type="button" class="bookflow-cal-next" id="bookflow-cal-next">&rarr;</button>
            </div>
            <div class="bookflow-calendar-weekdays" id="bookflow-cal-weekdays"></div>
            <div class="bookflow-calendar-days" id="bookflow-cal-days"></div>
        </div>
        <input type="hidden" name="bookflow_booking_date" id="bookflow-booking-date" value="">
        <input type="hidden" name="bookflow_schedule_id" id="bookflow-schedule-id" value="">
    </div>

    <!-- Staff / person who performs the trip -->
    <?php if ($has_resources) : ?>
    <div class="bookflow-wizard-step" data-step="staff" id="bookflow-step-staff">
        <h3 class="bookflow-section-title"><?php Bookflow_I18n::te('form.select_resource'); ?></h3>
        <div class="bookflow-resources-grid" id="bookflow-resources-grid"></div>
        <input type="hidden" name="bookflow_resource_id" id="bookflow-resource-id" value="">
    </div>
    <?php endif; ?>

    <!-- Time Slots -->
    <div class="bookflow-wizard-step" data-step="time" id="bookflow-step-time">
        <h3 class="bookflow-section-title"><?php Bookflow_I18n::te('form.select_time'); ?></h3>
        <div class="bookflow-slots-grid" id="bookflow-slots-grid"></div>
        <input type="hidden" name="bookflow_start_time" id="bookflow-start-time" value="">
    </div>

    <!-- Persons -->
    <div class="bookflow-wizard-step" data-step="persons" id="bookflow-step-persons">
        <?php if ($has_person_types) : ?>
        <h3 class="bookflow-section-title"><?php Bookflow_I18n::te('form.participants'); ?></h3>
        <div class="bookflow-person-types" id="bookflow-person-types-section">
            <?php foreach ($person_types as $i => $pt) : ?>
            <div class="bookflow-person-type-row" data-type-id="<?php echo esc_attr($pt->id); ?>">
                <div class="bookflow-person-type-info">
                    <span class="bookflow-person-type-name"><?php echo esc_html($pt->name); ?></span>
                    <span class="bookflow-person-type-cost"><?php echo wp_kses_post(wc_price($pt->cost)); ?></span>
                </div>
                <div class="bookflow-persons-input">
                    <button type="button" class="bookflow-persons-btn bookflow-pt-minus" data-index="<?php echo esc_attr($i); ?>">-</button>
                    <input type="number"
                           name="bookflow_person_types[<?php echo esc_attr($i); ?>][quantity]"
                           class="bookflow-pt-qty"
                           value="<?php echo esc_attr($pt->min_qty); ?>"
                           min="<?php echo esc_attr($pt->min_qty); ?>"
                           max="<?php echo esc_attr($pt->max_qty); ?>"
                           readonly>
                    <input type="hidden" name="bookflow_person_types[<?php echo esc_attr($i); ?>][person_type_id]" value="<?php echo esc_attr($pt->id); ?>">
                    <button type="button" class="bookflow-persons-btn bookflow-pt-plus" data-index="<?php echo esc_attr($i); ?>">+</button>
                </div>
            </div>
            <?php endforeach; ?>
            <input type="hidden" name="bookflow_persons_total" id="bookflow-persons-total" value="0">
        </div>
        <?php else : ?>
        <h3 class="bookflow-section-title"><?php Bookflow_I18n::te('form.number_of_persons'); ?></h3>
        <div class="bookflow-persons-input" id="bookflow-persons-section">
            <button type="button" class="bookflow-persons-btn" id="bookflow-persons-minus">-</button>
            <input type="number" name="bookflow_persons_total" id="bookflow-persons"
                   value="<?php echo esc_attr($min_persons); ?>"
                   min="<?php echo esc_attr($min_persons); ?>"
                   max="<?php echo esc_attr($max_persons); ?>"
                   readonly>
            <button type="button" class="bookflow-persons-btn" id="bookflow-persons-plus">+</button>
        </div>
        <?php endif; ?>
        <span class="bookflow-spots-left" id="bookflow-spots-left"></span>
    </div>

    <!-- Contact -->
    <div class="bookflow-wizard-step" data-step="contact" id="bookflow-step-contact">
        <h3 class="bookflow-section-title"><?php Bookflow_I18n::te('form.contact_details'); ?></h3>
        <div class="bookflow-form-fields" id="bookflow-contact-section">
            <div class="bookflow-field" data-validate="name">
                <label for="bookflow-customer-name"><?php Bookflow_I18n::te('form.full_name'); ?></label>
                <input type="text" name="bookflow_customer_name" id="bookflow-customer-name" required>
                <span class="bookflow-field-error"></span>
            </div>
            <div class="bookflow-field" data-validate="phone">
                <label for="bookflow-customer-phone"><?php Bookflow_I18n::te('form.phone'); ?></label>
                <input type="tel" name="bookflow_customer_phone" id="bookflow-customer-phone" required>
                <span class="bookflow-field-error"></span>
            </div>
            <div class="bookflow-field">
                <label for="bookflow-notes"><?php Bookflow_I18n::te('form.notes_optional'); ?></label>
                <textarea name="bookflow_notes" id="bookflow-notes" rows="3"></textarea>
            </div>
        </div>
    </div>

    <!-- Confirm: recap + extras + price summary + submit -->
    <div class="bookflow-wizard-step" data-step="confirm" id="bookflow-step-confirm">
        <div class="bookflow-recap" id="bookflow-recap">
            <h3 class="bookflow-section-title"><?php Bookflow_I18n::te('form.booking_details'); ?></h3>
            <div class="bookflow-recap-list" id="bookflow-recap-list"></div>
        </div>
        <?php if (!empty($extras)) : ?>
        <div class="bookflow-extras-block" id="bookflow-extras-block">
            <h3 class="bookflow-section-title"><?php Bookflow_I18n::te('form.extras_title'); ?></h3>
            <div class="bookflow-extras-list">
                <?php foreach ($extras as $ex) : ?>
                <label class="bookflow-extra-row">
                    <input type="checkbox" name="bookflow_extras[]" class="bookflow-extra-check" value="<?php echo esc_attr($ex->id); ?>">
                    <span class="bookflow-extra-title"><?php echo esc_html($ex->title); ?></span>
                    <span class="bookflow-extra-price"><?php echo wp_kses_post(wc_price($ex->price)); ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <div class="bookflow-price-summary" id="bookflow-summary-section">
            <div class="bookflow-summary-row">
                <span><?php Bookflow_I18n::te('form.price_per_person'); ?></span>
                <span id="bookflow-price-per-person">-</span>
            </div>
            <div class="bookflow-summary-row">
                <span><?php Bookflow_I18n::te('form.persons'); ?></span>
                <span id="bookflow-summary-persons">-</span>
            </div>
            <div class="bookflow-summary-row bookflow-summary-total">
                <span><?php Bookflow_I18n::te('form.total'); ?></span>
                <span id="bookflow-total-price">-</span>
            </div>
            <div class="bookflow-summary-row bookflow-hidden" id="bookflow-deposit-row">
                <span><?php Bookflow_I18n::te('cart.deposit_paid_now'); ?></span>
                <span id="bookflow-deposit-amount">-</span>
            </div>
            <div class="bookflow-summary-row bookflow-hidden" id="bookflow-balance-row">
                <span><?php Bookflow_I18n::te('cart.balance_due'); ?></span>
                <span id="bookflow-balance-amount">-</span>
            </div>
        </div>
        <?php if ($terms_text) : ?>
        <div class="bookflow-terms-block" id="bookflow-terms-block">
            <p class="bookflow-terms-text"><?php echo nl2br(esc_html($terms_text)); ?></p>
            <label class="bookflow-terms-row" id="bookflow-terms-row">
                <input type="checkbox" name="bookflow_terms_accepted" id="bookflow-terms-accepted" value="1">
                <span><?php Bookflow_I18n::te('form.terms_agree'); ?></span>
            </label>
        </div>
        <?php endif; ?>
    </div>

    <div class="bookflow-wizard-nav">
        <button type="button" class="bookflow-wizard-back" id="bookflow-wizard-back"><?php Bookflow_I18n::te('wizard.back'); ?></button>
        <button type="button" class="bookflow-wizard-next" id="bookflow-wizard-next"><?php Bookflow_I18n::te('wizard.next'); ?></button>
    </div>


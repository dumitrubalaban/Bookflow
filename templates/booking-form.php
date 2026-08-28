<?php
/**
 * Booking Form Template
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
?>

<div class="bookflow-booking-form" id="bookflow-booking-form">

    <!-- Calendar -->
    <div class="bookflow-section">
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
    </div>

    <!-- Time Slots -->
    <div class="bookflow-section bookflow-hidden" id="bookflow-slots-section">
        <h3 class="bookflow-section-title"><?php Bookflow_I18n::te('form.select_time'); ?></h3>
        <div class="bookflow-slots-grid" id="bookflow-slots-grid"></div>
        <input type="hidden" name="bookflow_start_time" id="bookflow-start-time" value="">
    </div>

    <!-- Resources -->
    <?php if ($has_resources) : ?>
    <div class="bookflow-section bookflow-hidden" id="bookflow-resources-section">
        <h3 class="bookflow-section-title"><?php Bookflow_I18n::te('form.select_resource'); ?></h3>
        <div class="bookflow-resources-grid" id="bookflow-resources-grid"></div>
        <input type="hidden" name="bookflow_resource_id" id="bookflow-resource-id" value="">
    </div>
    <?php endif; ?>

    <!-- Person Types -->
    <?php if ($has_person_types) : ?>
    <div class="bookflow-section bookflow-hidden" id="bookflow-person-types-section">
        <h3 class="bookflow-section-title"><?php Bookflow_I18n::te('form.participants'); ?></h3>
        <div class="bookflow-person-types">
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
        </div>
        <input type="hidden" name="bookflow_persons_total" id="bookflow-persons-total" value="0">
    </div>

    <!-- Simple Persons (hidden, value computed from person types) -->

    <?php else : ?>

    <!-- Simple Persons -->
    <div class="bookflow-section bookflow-hidden" id="bookflow-persons-section">
        <h3 class="bookflow-section-title"><?php Bookflow_I18n::te('form.number_of_persons'); ?></h3>
        <div class="bookflow-persons-input">
            <button type="button" class="bookflow-persons-btn" id="bookflow-persons-minus">-</button>
            <input type="number" name="bookflow_persons_total" id="bookflow-persons"
                   value="<?php echo esc_attr($min_persons); ?>"
                   min="<?php echo esc_attr($min_persons); ?>"
                   max="<?php echo esc_attr($max_persons); ?>"
                   readonly>
            <button type="button" class="bookflow-persons-btn" id="bookflow-persons-plus">+</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Contact Details -->
    <div class="bookflow-section bookflow-hidden" id="bookflow-contact-section">
        <h3 class="bookflow-section-title"><?php Bookflow_I18n::te('form.contact_details'); ?></h3>
        <div class="bookflow-form-fields">
            <div class="bookflow-field">
                <label for="bookflow-customer-name"><?php Bookflow_I18n::te('form.full_name'); ?></label>
                <input type="text" name="bookflow_customer_name" id="bookflow-customer-name" required>
            </div>
            <div class="bookflow-field">
                <label for="bookflow-customer-phone"><?php Bookflow_I18n::te('form.phone'); ?></label>
                <input type="tel" name="bookflow_customer_phone" id="bookflow-customer-phone" required>
            </div>
            <div class="bookflow-field">
                <label for="bookflow-notes"><?php Bookflow_I18n::te('form.notes_optional'); ?></label>
                <textarea name="bookflow_notes" id="bookflow-notes" rows="3"></textarea>
            </div>
        </div>
    </div>

    <!-- Price Summary -->
    <div class="bookflow-section bookflow-hidden" id="bookflow-summary-section">
        <div class="bookflow-price-summary">
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
        </div>
    </div>

</div>

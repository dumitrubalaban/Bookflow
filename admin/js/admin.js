/**
 * Bookflow - Admin JS
 */
jQuery(function ($) {
    // Show booking tabs only for booking product type
    function toggleBookingTabs() {
        var type = $('#product-type').val();
        var isBooking = type === 'booking';

        $('.show_if_booking').toggle(isBooking);

        // Show person types panel only for booking type
        $('#bookflow_person_types_data').toggle(isBooking);

        if (isBooking) {
            $('.show_if_simple, .show_if_external').hide();
            $('li.shipping_options').hide();
        }
    }

    $('#product-type').on('change', toggleBookingTabs);
    toggleBookingTabs();

    // Toggle person types group visibility based on checkbox
    $('#_bookflow_enable_person_types').on('change', function () {
        $('.bookflow-person-types-group').toggle($(this).is(':checked'));
    });

    // Person Types - Add row
    var $ptContainer = $('#bookflow-person-types-list');
    $(document).on('click', '.bookflow-add-person-type', function (e) {
        e.preventDefault();
        var idx = $ptContainer.find('.bookflow-person-type-row').length;
        var i18n = window.bookflowAdminI18n || {};
        var html = '<div class="bookflow-person-type-row options_group" style="border-top:1px solid #eee; padding-top:10px;">';
        html += '<input type="hidden" name="bookflow_person_types[' + idx + '][id]" value="">';
        html += '<p class="form-field"><label>' + (i18n.type_name || 'Type Name') + '</label><input type="text" name="bookflow_person_types[' + idx + '][name]" placeholder="' + (i18n.type_name_placeholder || 'e.g. Adult, Child') + '" style="width:50%;"></p>';
        html += '<p class="form-field"><label>' + (i18n.cost_per_person || 'Cost per person') + '</label><input type="text" name="bookflow_person_types[' + idx + '][cost]" style="width:50%;"></p>';
        html += '<p class="form-field"><label>' + (i18n.min_qty || 'Min qty') + '</label><input type="number" name="bookflow_person_types[' + idx + '][min_qty]" value="0" style="width:50%;"></p>';
        html += '<p class="form-field"><label>' + (i18n.max_qty || 'Max qty') + '</label><input type="number" name="bookflow_person_types[' + idx + '][max_qty]" value="10" style="width:50%;"></p>';
        html += '<p style="padding-left:12px;"><button type="button" class="button bookflow-remove-person-type" style="color:#d63638;">' + (i18n.remove || 'Remove') + '</button></p>';
        html += '</div>';
        $ptContainer.append(html);
    });

    // Person Types - Remove row
    $(document).on('click', '.bookflow-remove-person-type', function () {
        $(this).closest('.bookflow-person-type-row').remove();
    });
});

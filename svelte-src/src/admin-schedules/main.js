import { mount } from 'svelte';
import '../app.css';
import AdminCrud from '../admin-shared/AdminCrud.svelte';

function init() {
    var target = document.getElementById('bookflow-admin-schedules-root');
    if (!target || typeof window.bookflowAdminSchedules === 'undefined') return;
    var config = window.bookflowAdminSchedules;
    var productOptions = (config.products || []).map(function (p) {
        return { value: p.id, label: p.name };
    });

    mount(AdminCrud, {
        target: target,
        props: {
            config: config,
            listAction: 'bookflow_list_schedules',
            saveAction: 'bookflow_save_schedule',
            deleteAction: 'bookflow_delete_schedule',
            emptyItem: function () {
                return {
                    id: 0, product_id: productOptions[0] ? productOptions[0].value : '',
                    option_group: 'language', option_label: '', option_value: '',
                    available_days: [], time_slots: '',
                    max_persons: 0, max_bookings_per_slot: 0, price_modifier: 0,
                    sort_order: 0, status: 'active',
                };
            },
            fields: [
                { key: 'product_id', label: config.i18n.product, type: 'select', options: productOptions },
                { key: 'option_group', label: config.i18n.optionGroup, type: 'text', description: 'e.g. "language"' },
                { key: 'option_label', label: config.i18n.optionLabel, type: 'text', description: 'e.g. "Română"' },
                { key: 'option_value', label: config.i18n.optionValue, type: 'text', description: 'e.g. "ro"' },
                { key: 'available_days', label: config.i18n.availableDays, type: 'days', dayNames: config.dayNames, dayLabels: config.dayLabels, dayFormat: 'array' },
                { key: 'time_slots', label: config.i18n.timeSlots, type: 'textarea', rows: 4, description: 'One time per line, e.g. 10:00' },
                { key: 'max_persons', label: config.i18n.maxPersons, type: 'number', min: 0 },
                { key: 'max_bookings_per_slot', label: config.i18n.maxBookingsPerSlot, type: 'number', min: 0 },
                { key: 'price_modifier', label: config.i18n.priceModifier, type: 'number', step: 0.01 },
                { key: 'sort_order', label: config.i18n.sortOrder, type: 'number', min: 0 },
                { key: 'status', label: config.i18n.status, type: 'select', options: [
                    { value: 'active', label: config.i18n.active },
                    { value: 'inactive', label: config.i18n.inactive },
                ] },
            ],
            columns: [
                { key: 'product_name', label: config.i18n.product },
                { key: 'option_label', label: config.i18n.optionLabel },
                { key: 'option_value', label: config.i18n.optionValue },
                { key: 'status', label: config.i18n.status, render: function (item) { return item.status === 'active' ? config.i18n.active : config.i18n.inactive; } },
            ],
        },
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

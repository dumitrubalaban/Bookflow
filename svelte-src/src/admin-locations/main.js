import { mount } from 'svelte';
import '../app.css';
import AdminCrud from '../admin-shared/AdminCrud.svelte';

function init() {
    var target = document.getElementById('bookflow-admin-locations-root');
    if (!target || typeof window.bookflowAdminLocations === 'undefined') return;
    var config = window.bookflowAdminLocations;

    mount(AdminCrud, {
        target: target,
        props: {
            config: config,
            listAction: 'bookflow_list_locations',
            saveAction: 'bookflow_save_location',
            deleteAction: 'bookflow_delete_location',
            emptyItem: function () {
                return {
                    id: 0, name: '', address: '', lat: '', lng: '',
                    available_days: [], blocked_dates: '', holidays: '',
                    sort_order: 0, status: 'active',
                };
            },
            fields: [
                { key: 'name', label: config.i18n.name, type: 'text' },
                { key: 'address', label: config.i18n.address, type: 'text' },
                { key: 'lat', label: config.i18n.lat, type: 'text' },
                { key: 'lng', label: config.i18n.lng, type: 'text' },
                { key: 'available_days', label: config.i18n.availableDays, type: 'days', dayNames: config.dayNames, dayLabels: config.dayLabels },
                { key: 'blocked_dates', label: config.i18n.blockedDates, type: 'datelist', rows: 3 },
                { key: 'holidays', label: config.i18n.holidays, type: 'datelist', rows: 3 },
                { key: 'sort_order', label: config.i18n.sortOrder, type: 'number', min: 0 },
                { key: 'status', label: config.i18n.status, type: 'select', options: [
                    { value: 'active', label: config.i18n.active },
                    { value: 'inactive', label: config.i18n.inactive },
                ] },
            ],
            columns: [
                { key: 'name', label: config.i18n.name },
                { key: 'address', label: config.i18n.address },
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

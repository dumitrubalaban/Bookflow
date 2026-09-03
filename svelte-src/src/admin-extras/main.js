import { mount } from 'svelte';
import '../app.css';
import AdminCrud from '../admin-shared/AdminCrud.svelte';

function init() {
    var target = document.getElementById('bookflow-admin-extras-root');
    if (!target || typeof window.bookflowAdminExtras === 'undefined') return;
    var config = window.bookflowAdminExtras;

    mount(AdminCrud, {
        target: target,
        props: {
            config: config,
            listAction: 'bookflow_list_extras',
            saveAction: 'bookflow_save_extra',
            deleteAction: 'bookflow_delete_extra',
            emptyItem: function () {
                return { id: 0, title: '', description: '', price: 0, sort_order: 0, status: 'active' };
            },
            fields: [
                { key: 'title', label: config.i18n.title, type: 'text' },
                { key: 'description', label: config.i18n.description, type: 'textarea' },
                { key: 'price', label: config.i18n.price, type: 'number', min: 0, step: 0.01 },
                { key: 'sort_order', label: config.i18n.sortOrder, type: 'number', min: 0 },
                { key: 'status', label: config.i18n.status, type: 'select', options: [
                    { value: 'active', label: config.i18n.active },
                    { value: 'inactive', label: config.i18n.inactive },
                ] },
            ],
            columns: [
                { key: 'title', label: config.i18n.title },
                { key: 'price', label: config.i18n.price, render: function (item) { return item.price.toFixed(2); } },
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

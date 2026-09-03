import { mount } from 'svelte';
import '../app.css';
import AdminCrud from '../admin-shared/AdminCrud.svelte';

function init() {
    var target = document.getElementById('bookflow-admin-resources-root');
    if (!target || typeof window.bookflowAdminResources === 'undefined') return;
    var config = window.bookflowAdminResources;

    mount(AdminCrud, {
        target: target,
        props: {
            config: config,
            listAction: 'bookflow_list_resources',
            saveAction: 'bookflow_save_resource',
            deleteAction: 'bookflow_delete_resource',
            emptyItem: function () {
                return { id: 0, title: '', description: '', capacity: 1, sort_order: 0, status: 'active', photo_id: 0, photo_id_url: '' };
            },
            fields: [
                { key: 'title', label: config.i18n.title, type: 'text' },
                { key: 'description', label: config.i18n.description, type: 'textarea' },
                { key: 'capacity', label: config.i18n.capacity, type: 'number', min: 0 },
                { key: 'photo_id', label: config.i18n.photo, type: 'media' },
                { key: 'sort_order', label: config.i18n.sortOrder, type: 'number', min: 0 },
                { key: 'status', label: config.i18n.status, type: 'select', options: [
                    { value: 'active', label: config.i18n.active },
                    { value: 'inactive', label: config.i18n.inactive },
                ] },
            ],
            columns: [
                { key: 'title', label: config.i18n.title },
                { key: 'capacity', label: config.i18n.capacity },
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

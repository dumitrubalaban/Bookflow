import { mount } from 'svelte';
import '../app.css';
import AdminWidgets from './AdminWidgets.svelte';

function init() {
    const target = document.getElementById('bookflow-admin-widgets-root');
    if (!target || typeof window.bookflowAdminWidgets === 'undefined') return;

    mount(AdminWidgets, {
        target: target,
        props: { config: window.bookflowAdminWidgets },
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

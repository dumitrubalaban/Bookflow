import { mount } from 'svelte';
import '../app.css';
import AdminReports from './AdminReports.svelte';

function init() {
    const target = document.getElementById('bookflow-admin-reports-root');
    if (!target || typeof window.bookflowAdminReports === 'undefined') return;

    mount(AdminReports, {
        target: target,
        props: { config: window.bookflowAdminReports },
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

import { mount } from 'svelte';
import '../app.css';
import AdminCalendar from './AdminCalendar.svelte';

function init() {
    var target = document.getElementById('bookflow-admin-calendar-root');
    if (!target || typeof window.bookflowAdminCalendar === 'undefined') return;
    mount(AdminCalendar, { target: target, props: { config: window.bookflowAdminCalendar } });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

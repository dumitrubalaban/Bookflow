import { mount } from 'svelte';
import './app.css';
import App from './App.svelte';

function init() {
    var target = document.getElementById('bookflow-svelte-root');
    if (!target || typeof window.bookflowBooking === 'undefined') return;
    mount(App, { target: target, props: { config: window.bookflowBooking } });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

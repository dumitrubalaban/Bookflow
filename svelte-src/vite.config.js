import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import tailwindcss from '@tailwindcss/vite';

// Builds a single self-contained IIFE bundle that the plugin enqueues
// instead of the legacy public/js/booking-calendar.js. Output lands
// directly in the plugin's own public/ dir so PHP can wp_enqueue it
// with no extra build step at runtime.
export default defineConfig({
    plugins: [tailwindcss(), svelte()],
    build: {
        outDir: '../public/dist',
        emptyOutDir: true,
        lib: {
            entry: 'src/main.js',
            name: 'BookflowWidget',
            formats: ['iife'],
            fileName: () => 'bookflow-widget.js',
        },
        rollupOptions: {
            output: {
                assetFileNames: 'bookflow-widget.[ext]',
            },
        },
    },
});

import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import tailwindcss from '@tailwindcss/vite';

// Second, independent bundle for the wp-admin booking calendar screen.
// Kept as its own vite config (rather than a second `lib.entry`, which
// Vite's library mode doesn't support) so it ships as its own small
// enqueue-able file instead of being pulled into the public-facing widget.
export default defineConfig({
    plugins: [tailwindcss(), svelte()],
    build: {
        outDir: '../admin/dist',
        emptyOutDir: false,
        lib: {
            entry: 'src/admin-calendar/main.js',
            name: 'BookflowAdminCalendar',
            formats: ['iife'],
            fileName: () => 'admin-calendar.js',
        },
        rollupOptions: {
            output: {
                assetFileNames: 'admin-calendar.[ext]',
            },
        },
    },
});

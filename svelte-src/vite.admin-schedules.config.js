import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [tailwindcss(), svelte()],
    build: {
        outDir: '../admin/dist',
        emptyOutDir: false,
        lib: {
            entry: 'src/admin-schedules/main.js',
            name: 'BookflowAdminSchedules',
            formats: ['iife'],
            fileName: () => 'admin-schedules.js',
        },
        rollupOptions: {
            output: {
                assetFileNames: 'admin-schedules.[ext]',
            },
        },
    },
});

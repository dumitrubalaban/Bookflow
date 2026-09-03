import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [tailwindcss(), svelte()],
    build: {
        outDir: '../admin/dist',
        emptyOutDir: false,
        lib: {
            entry: 'src/admin-reports/main.js',
            name: 'BookflowAdminReports',
            formats: ['iife'],
            fileName: () => 'admin-reports.js',
        },
        rollupOptions: {
            output: {
                assetFileNames: 'admin-reports.[ext]',
            },
        },
    },
});

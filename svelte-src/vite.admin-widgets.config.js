import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [tailwindcss(), svelte()],
    build: {
        outDir: '../admin/dist',
        emptyOutDir: false,
        lib: {
            entry: 'src/admin-widgets/main.js',
            name: 'BookflowAdminWidgets',
            formats: ['iife'],
            fileName: () => 'admin-widgets.js',
        },
        rollupOptions: {
            output: {
                assetFileNames: 'admin-widgets.[ext]',
            },
        },
    },
});

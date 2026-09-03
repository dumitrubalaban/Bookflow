import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [tailwindcss(), svelte()],
    build: {
        outDir: '../admin/dist',
        emptyOutDir: false,
        lib: {
            entry: 'src/admin-resources/main.js',
            name: 'BookflowAdminResources',
            formats: ['iife'],
            fileName: () => 'admin-resources.js',
        },
        rollupOptions: {
            output: {
                assetFileNames: 'admin-resources.[ext]',
            },
        },
    },
});

import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [tailwindcss(), svelte()],
    build: {
        outDir: '../admin/dist',
        emptyOutDir: false,
        lib: {
            entry: 'src/admin-extras/main.js',
            name: 'BookflowAdminExtras',
            formats: ['iife'],
            fileName: () => 'admin-extras.js',
        },
        rollupOptions: {
            output: {
                assetFileNames: 'admin-extras.[ext]',
            },
        },
    },
});

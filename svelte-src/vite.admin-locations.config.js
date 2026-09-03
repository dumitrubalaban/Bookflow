import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [tailwindcss(), svelte()],
    build: {
        outDir: '../admin/dist',
        emptyOutDir: false,
        lib: {
            entry: 'src/admin-locations/main.js',
            name: 'BookflowAdminLocations',
            formats: ['iife'],
            fileName: () => 'admin-locations.js',
        },
        rollupOptions: {
            output: {
                assetFileNames: 'admin-locations.[ext]',
            },
        },
    },
});

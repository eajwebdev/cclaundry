import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // Separate entry: MapLibre is heavy, so only pages that show a
                // map pull it in. It never lands in the main bundle.
                'resources/js/maps.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
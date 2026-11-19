import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    // Let Vue rewrite asset paths in templates so images and
                    // fonts resolve through Vite rather than being emitted raw.
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});

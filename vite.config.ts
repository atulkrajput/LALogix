import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.tsx'],
            // `vite build --ssr` writes bootstrap/ssr/ssr.js (run by `php artisan inertia:start-ssr`).
            ssr: 'resources/js/ssr.tsx',
            refresh: true,
        }),
        react(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    ssr: {
        // Bundle everything so the server only needs Node, not node_modules.
        noExternal: true,
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**', '**/old/**'],
        },
    },
});

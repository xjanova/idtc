import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/admin.css', 'resources/js/admin.js'],
            refresh: true,
            publicDirectory: 'public_html',
        }),
        tailwindcss(),
    ],
    // Not 'public_html': Vite copies publicDir into the build output, which would
    // copy the whole web root into public_html/build on every deploy (xmanstudio lesson).
    publicDir: false,
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});

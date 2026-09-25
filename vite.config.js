import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import statamic from '@statamic/cms/vite-plugin';
import tailwindcss from '@tailwindcss/vite';

/*
 * The three values handed to `laravel()` must byte-match the provider's
 * `$vite` property. Statamic 6 reads the addon's Vite configuration from that
 * property and from nowhere else.
 */
export default defineConfig({
    plugins: [
        statamic(),
        tailwindcss(),
        laravel({
            hotFile: 'dist/hot',
            publicDirectory: 'dist',
            input: ['resources/js/cp.js', 'resources/css/cp.css'],
        }),
    ],
});

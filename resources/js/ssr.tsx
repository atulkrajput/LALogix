/**
 * Inertia server-side rendering entry (built by `npm run build` into bootstrap/ssr/ssr.js).
 *
 * Run it with `php artisan inertia:start-ssr` (see DEPLOY.md). When the SSR
 * process is not running, Laravel falls back to normal client-side rendering.
 */
import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import ReactDOMServer from 'react-dom/server';
import { resolvePage, type PageModule } from './resolvePage';

const pages = import.meta.glob<PageModule>('./Pages/**/*.tsx', { eager: true });

createServer((page) =>
    createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        title: (title) => title,
        resolve: (name) => resolvePage(name, pages[`./Pages/${name}.tsx`]),
        setup: ({ App, props }) => <App {...props} />,
    }),
);

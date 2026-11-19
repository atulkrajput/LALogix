import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot, hydrateRoot } from 'react-dom/client';
import { captureAttribution } from '@/lib/attribution';
import { resolvePage, type PageModule } from './resolvePage';

const pages = import.meta.glob<PageModule>('./Pages/**/*.tsx');

createInertiaApp({
    title: (title) => title,
    resolve: async (name) => {
        const importPage = pages[`./Pages/${name}.tsx`];
        if (!importPage) throw new Error(`Page not found: ${name}`);
        return resolvePage(name, await importPage());
    },
    setup({ el, App, props }) {
        // Remember which ad / campaign brought this visitor (sent with lead forms).
        if (!window.location.pathname.startsWith('/admin')) captureAttribution();

        // Server-rendered HTML (Inertia SSR) is hydrated; otherwise render from scratch.
        if (el.hasChildNodes()) {
            hydrateRoot(el, <App {...props} />);
        } else {
            createRoot(el).render(<App {...props} />);
        }
    },
    progress: {
        color: '#C89B3C',
    },
});

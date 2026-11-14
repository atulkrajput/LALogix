import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import SiteShell from '@/components/layouts/SiteShell';
import AdminLayout from '@/components/layouts/AdminLayout';
import PageTitle from '@/components/layouts/PageTitle';
import { captureAttribution } from '@/lib/attribution';

type PageModule = {
    default: React.ComponentType & { layout?: (page: ReactNode) => ReactNode };
};

const pages = import.meta.glob<PageModule>('./Pages/**/*.tsx');

createInertiaApp({
    title: (title) => title,
    resolve: async (name) => {
        const importPage = pages[`./Pages/${name}.tsx`];
        if (!importPage) throw new Error(`Page not found: ${name}`);

        const page = await importPage();

        // Persistent layouts: website pages get Navbar/Footer, admin pages get the CRM shell.
        if (page.default.layout === undefined) {
            if (name === 'Admin/Login') {
                page.default.layout = (p) => <PageTitle>{p}</PageTitle>;
            } else if (name.startsWith('Admin/')) {
                page.default.layout = (p) => (
                    <PageTitle>
                        <AdminLayout>{p}</AdminLayout>
                    </PageTitle>
                );
            } else {
                page.default.layout = (p) => (
                    <PageTitle>
                        <SiteShell>{p}</SiteShell>
                    </PageTitle>
                );
            }
        }

        return page;
    },
    setup({ el, App, props }) {
        // Remember which ad / campaign brought this visitor (sent with lead forms).
        if (!window.location.pathname.startsWith('/admin')) captureAttribution();
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: '#C89B3C',
    },
});

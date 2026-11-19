import type { ReactNode } from 'react';
import SiteShell from '@/components/layouts/SiteShell';
import AdminLayout from '@/components/layouts/AdminLayout';
import PageTitle from '@/components/layouts/PageTitle';

export type PageModule = {
    default: React.ComponentType & { layout?: (page: ReactNode) => ReactNode };
};

/**
 * Shared by the browser (app.tsx) and SSR (ssr.tsx) entries: attaches the
 * persistent layout. Website pages get Navbar/Footer, admin pages the CRM shell.
 */
export function resolvePage(name: string, page: PageModule | undefined): PageModule {
    if (!page) throw new Error(`Page not found: ${name}`);

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
}

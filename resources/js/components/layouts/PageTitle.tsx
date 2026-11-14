import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

/**
 * Keeps <title> in sync on client-side (Inertia) navigation. The full SEO tags
 * are rendered by the server in resources/views/app.blade.php on first load.
 */
export default function PageTitle({ children }: { children: ReactNode }) {
    const { meta } = usePage<{ meta?: { title?: string } }>().props;

    return (
        <>
            {meta?.title && <Head title={meta.title} />}
            {children}
        </>
    );
}

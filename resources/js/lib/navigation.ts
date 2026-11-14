import { router, usePage } from '@inertiajs/react';
import { useMemo } from 'react';

/**
 * Small replacements for the next/navigation hooks the old app used,
 * backed by Inertia's router.
 */

export function usePathname(): string {
    const { url } = usePage();
    return url.split('?')[0].split('#')[0] || '/';
}

export function useSearchParams(): URLSearchParams {
    const { url } = usePage();
    return useMemo(() => new URLSearchParams(url.split('?')[1] ?? ''), [url]);
}

const appRouter = {
    push: (href: string) => router.visit(href),
    replace: (href: string) => router.visit(href, { replace: true, preserveState: true, preserveScroll: true }),
    back: () => window.history.back(),
    refresh: () => router.reload(),
};

export function useRouter() {
    return appRouter;
}

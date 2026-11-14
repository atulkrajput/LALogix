import { Link as InertiaLink, type InertiaLinkProps } from '@inertiajs/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';

export interface LinkProps extends AnchorHTMLAttributes<HTMLAnchorElement> {
    href: string;
    children?: ReactNode;
    /** Accepted for compatibility with the old next/link API; ignored. */
    prefetch?: boolean;
    scroll?: boolean;
}

/** Internal app paths (not anchors, external URLs, mailto:, tel:, downloads, files). */
function isInternal(href: string) {
    return href.startsWith('/') && !href.startsWith('//') && !href.startsWith('/api/') && !/\.[a-z0-9]{2,5}$/i.test(href);
}

/**
 * Drop-in replacement for next/link: internal links navigate with Inertia
 * (no full page reload); everything else is a plain <a>.
 */
export default function Link({ href, children, prefetch: _prefetch, scroll: _scroll, target, ...rest }: LinkProps) {
    if (isInternal(href) && !target) {
        return (
            <InertiaLink href={href} {...(rest as Omit<InertiaLinkProps, 'href'>)}>
                {children}
            </InertiaLink>
        );
    }

    return (
        <a href={href} target={target} {...rest}>
            {children}
        </a>
    );
}

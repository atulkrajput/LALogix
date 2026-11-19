import { useCallback, useEffect, useRef, useState } from 'react';
import Link from '@/components/ui/Link';
import Icon, { WhatsAppIcon } from '@/components/ui/Icon';
import { logo, mainLinks, ctaButton } from '@/data/navigation';
import { useSite, whatsappUrl, type ProductSummary } from '@/lib/site';
import { usePathname } from '@/lib/navigation';
import { trackEvent } from '@/lib/analytics';

const linkClass =
  'rounded-md px-3 py-2 text-sm font-medium text-neutral-700 transition-colors hover:text-neutral-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600';

export default function Navbar() {
  const { company, products } = useSite();
  const pathname = usePathname();
  const [scrolled, setScrolled] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const hamburgerRef = useRef<HTMLButtonElement>(null);
  const drawerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 20);
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  // Close the drawer on navigation.
  useEffect(() => setMobileOpen(false), [pathname]);

  useEffect(() => {
    document.body.style.overflow = mobileOpen ? 'hidden' : '';
    if (!mobileOpen) return;
    drawerRef.current?.querySelector<HTMLElement>('a[href], button')?.focus();
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setMobileOpen(false);
        hamburgerRef.current?.focus();
      }
    };
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
  }, [mobileOpen]);

  const close = useCallback(() => setMobileOpen(false), []);
  const waHref = whatsappUrl(company.whatsapp, "Hi, I'm interested in LALogix products.");

  return (
    <header
      className={`fixed inset-x-0 top-0 z-50 transition-colors duration-300 ${
        scrolled || mobileOpen ? 'border-b border-neutral-200 bg-white/95 shadow-sm backdrop-blur' : 'bg-white/80 backdrop-blur-sm'
      }`}
    >
      <nav className="mx-auto flex h-16 max-w-8xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8" aria-label="Main navigation">
        <Link href="/" className="flex-shrink-0" aria-label="LALogix home">
          <img src={logo.src} alt={logo.alt} width={logo.width} height={logo.height} className="h-11 w-auto" {...{ fetchpriority: "high" }} />
        </Link>

        {/* Desktop links */}
        <div className="hidden items-center gap-1 lg:flex">
          <ProductsMenu products={products} />
          {mainLinks.map((l) => (
            <Link key={l.href} href={l.href} className={linkClass} aria-current={pathname === l.href ? 'page' : undefined}>
              {l.label}
            </Link>
          ))}
        </div>

        {/* Desktop contact + CTA */}
        <div className="hidden items-center gap-3 lg:flex">
          <a href={`tel:${company.tel}`} className="inline-flex items-center gap-1.5 text-sm font-medium text-neutral-700 hover:text-brand-700">
            <Icon name="phone" className="h-4 w-4" />
            <span className="hidden xl:inline">{company.phone_display}</span>
            <span className="sr-only xl:hidden">Call {company.phone_display}</span>
          </a>
          <a
            href={waHref}
            target="_blank"
            rel="noopener noreferrer"
            onClick={() => trackEvent('whatsapp_click', { location: 'header' })}
            className="inline-flex items-center gap-1.5 text-sm font-medium text-[#128C4B] hover:text-[#0b6b38]"
          >
            <WhatsAppIcon className="h-4 w-4" />
            <span className="hidden xl:inline">{company.whatsapp_display}</span>
            <span className="sr-only xl:hidden">WhatsApp {company.whatsapp_display}</span>
          </a>
          <Link
            href={ctaButton.href}
            className="rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-brand-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2"
          >
            {ctaButton.label}
          </Link>
        </div>

        {/* Mobile: call + menu */}
        <div className="flex items-center gap-1 lg:hidden">
          <a href={`tel:${company.tel}`} className="rounded-md p-2.5 text-neutral-700 hover:bg-neutral-100" aria-label={`Call ${company.phone_display}`}>
            <Icon name="phone" className="h-5 w-5" />
          </a>
          <button
            ref={hamburgerRef}
            type="button"
            className="rounded-md p-2.5 text-neutral-700 hover:bg-neutral-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600"
            onClick={() => setMobileOpen((o) => !o)}
            aria-expanded={mobileOpen}
            aria-controls="mobile-menu"
            aria-label={mobileOpen ? 'Close menu' : 'Open menu'}
          >
            <Icon name={mobileOpen ? 'x' : 'menu'} className="h-6 w-6" />
          </button>
        </div>
      </nav>

      {/* Mobile drawer */}
      <div
        id="mobile-menu"
        ref={drawerRef}
        hidden={!mobileOpen}
        className="max-h-[calc(100vh-4rem)] overflow-y-auto border-t border-neutral-200 bg-white px-4 pb-6 pt-2 lg:hidden"
      >
        <p className="px-3 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-neutral-500">Products</p>
        {products.map((p) => (
          <Link key={p.slug} href={`/products/${p.slug}`} onClick={close} className="flex items-center gap-3 rounded-md px-3 py-2.5 text-neutral-800 hover:bg-neutral-100">
            <Icon name={p.icon} className="h-5 w-5 text-brand-700" />
            <span className="font-medium">{p.name}</span>
            {p.category && <span className="text-sm text-neutral-500">{p.category}</span>}
          </Link>
        ))}
        <Link href="/products" onClick={close} className="block rounded-md px-3 py-2.5 text-sm font-medium text-brand-700 hover:bg-neutral-100">
          See all products
        </Link>
        <div className="my-2 border-t border-neutral-200" />
        {mainLinks.map((l) => (
          <Link key={l.href} href={l.href} onClick={close} className="block rounded-md px-3 py-2.5 font-medium text-neutral-800 hover:bg-neutral-100">
            {l.label}
          </Link>
        ))}
        <div className="mt-4 grid gap-2">
          <Link href={ctaButton.href} onClick={close} className="rounded-lg bg-brand-700 px-4 py-3 text-center font-semibold text-white hover:bg-brand-800">
            {ctaButton.label}
          </Link>
          <a
            href={waHref}
            target="_blank"
            rel="noopener noreferrer"
            onClick={() => trackEvent('whatsapp_click', { location: 'mobile_menu' })}
            className="inline-flex items-center justify-center gap-2 rounded-lg border border-neutral-300 px-4 py-3 font-medium text-neutral-800"
          >
            <WhatsAppIcon className="h-5 w-5 text-[#128C4B]" /> WhatsApp {company.whatsapp_display}
          </a>
        </div>
      </div>
    </header>
  );
}

/** "Products ▾" dropdown: opens on hover, click and keyboard. */
function ProductsMenu({ products }: { products: ProductSummary[] }) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    if (!open) return;
    const onDoc = (e: MouseEvent) => {
      if (!ref.current?.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDoc);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    <div
      ref={ref}
      className="relative"
      onMouseEnter={() => {
        if (timer.current) clearTimeout(timer.current);
        setOpen(true);
      }}
      onMouseLeave={() => {
        timer.current = setTimeout(() => setOpen(false), 150);
      }}
      onBlur={(e) => {
        if (!e.currentTarget.contains(e.relatedTarget as Node)) setOpen(false);
      }}
    >
      <button
        type="button"
        className={`${linkClass} inline-flex items-center gap-1`}
        aria-expanded={open}
        aria-controls="products-menu"
        onClick={() => setOpen((o) => !o)}
      >
        Products
        <Icon name="chevron-down" className={`h-4 w-4 transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>
      <div
        id="products-menu"
        hidden={!open}
        className="absolute left-0 top-full mt-1 w-80 rounded-xl border border-neutral-200 bg-white p-2 shadow-lg"
      >
        {products.map((p) => (
          <Link
            key={p.slug}
            href={`/products/${p.slug}`}
            onClick={() => setOpen(false)}
            className="flex items-start gap-3 rounded-lg px-3 py-2 hover:bg-brand-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-600"
          >
            <Icon name={p.icon} className="mt-0.5 h-5 w-5 flex-shrink-0 text-brand-700" />
            <span>
              <span className="block text-sm font-semibold text-neutral-900">
                {p.name}
                {p.status === 'coming_soon' && <span className="ml-2 rounded bg-neutral-100 px-1.5 py-0.5 text-xs font-medium text-neutral-600">Coming soon</span>}
              </span>
              {p.category && <span className="block text-xs text-neutral-600">{p.category}</span>}
            </span>
          </Link>
        ))}
        <Link href="/products" onClick={() => setOpen(false)} className="mt-1 block rounded-lg px-3 py-2 text-sm font-medium text-brand-700 hover:bg-brand-50">
          Compare all products
        </Link>
      </div>
    </div>
  );
}

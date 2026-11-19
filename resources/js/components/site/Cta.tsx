import Link from '@/components/ui/Link';
import Icon, { WhatsAppIcon } from '@/components/ui/Icon';
import { trackEvent } from '@/lib/analytics';
import { useSite, whatsappUrl, type ProductCard } from '@/lib/site';

const base =
  'inline-flex items-center justify-center gap-2 rounded-lg px-5 py-3 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2';

export const btn = {
  primary: `${base} bg-brand-700 text-white hover:bg-brand-800 hover:text-white focus-visible:ring-brand-600`,
  secondary: `${base} border border-neutral-300 bg-white text-neutral-900 hover:border-neutral-400 hover:bg-neutral-50 hover:text-neutral-900 focus-visible:ring-brand-600`,
  dark: `${base} bg-neutral-900 text-white hover:bg-neutral-800 hover:text-white focus-visible:ring-neutral-900`,
  whatsapp: `${base} bg-[#0f7a40] text-white hover:bg-[#0b6233] hover:text-white focus-visible:ring-[#0f7a40]`,
};

/** Book-demo link, pre-selecting the product on the form. */
export function BookDemoButton({ product, className = btn.primary, label = 'Book a Free Demo' }: { product?: string; className?: string; label?: string }) {
  return (
    <Link href={product ? `/book-demo?product=${product}` : '/book-demo'} className={className} onClick={() => trackEvent('book_demo_click', { product: product ?? 'general' })}>
      {label}
    </Link>
  );
}

/**
 * "Try live demo": opens the product's demo or site. Products without a public
 * demo yet send people to the demo form, where we share a demo login.
 */
export function TryDemoButton({ product, className = btn.secondary }: { product: ProductCard; className?: string }) {
  const { showTodos } = useSite();
  const demo = product.demo;
  if (!demo) return null;

  if (!demo.url) {
    return (
      <span className="inline-flex flex-col">
        <Link
          href={`/book-demo?product=${product.slug}`}
          className={className}
          onClick={() => trackEvent('try_demo_click', { product: product.name, destination: 'demo_login_request' })}
        >
          {demo.label}
        </Link>
        <span className="mt-1 text-xs text-neutral-600">Demo login shared on request{showTodos && demo.note ? ` (${demo.note})` : ''}</span>
      </span>
    );
  }

  return (
    <a
      href={demo.url}
      target="_blank"
      rel="noopener"
      className={className}
      onClick={() => trackEvent('try_demo_click', { product: product.name, destination: demo.url })}
    >
      {demo.label}
      <Icon name="external-link" className="h-4 w-4" />
      <span className="sr-only">(opens in a new tab)</span>
    </a>
  );
}

export function WhatsAppButton({ text, label, location, className = btn.whatsapp }: { text: string; label?: string; location: string; className?: string }) {
  const { company } = useSite();
  const href = whatsappUrl(company.whatsapp, text);
  if (!href) return null;
  return (
    <a
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      className={className}
      onClick={() => trackEvent('whatsapp_click', { location })}
    >
      <WhatsAppIcon className="h-5 w-5" />
      {label ?? 'Chat on WhatsApp'}
    </a>
  );
}

/** Google Play badge-style link (only when a real Play Store URL is configured). */
export function PlayStoreLink({ url, product }: { url: string | null; product: string }) {
  if (!url || !url.startsWith('http')) return null;
  return (
    <a
      href={url}
      target="_blank"
      rel="noopener"
      className={`${base} bg-black text-white hover:bg-neutral-800 hover:text-white focus-visible:ring-neutral-900`}
      onClick={() => trackEvent('try_demo_click', { product, destination: 'google_play' })}
    >
      <Icon name="smartphone" className="h-4 w-4" /> Get it on Google Play
    </a>
  );
}

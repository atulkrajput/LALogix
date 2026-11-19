import Link from '@/components/ui/Link';
import Icon, { WhatsAppIcon } from '@/components/ui/Icon';
import { logo, companyLinks, legalLinks } from '@/data/navigation';
import { useSite, whatsappUrl } from '@/lib/site';
import { trackEvent } from '@/lib/analytics';

const linkClass = 'text-sm text-neutral-300 transition-colors hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400';

export default function Footer() {
  const { company, products } = useSite();
  const year = new Date().getFullYear();
  const waHref = whatsappUrl(company.whatsapp, "Hi, I'm interested in LALogix products.");

  return (
    <footer className="bg-neutral-950 text-neutral-300">
      <div className="mx-auto max-w-8xl px-4 py-12 sm:px-6 lg:px-8 lg:py-16">
        <div className="grid gap-10 md:grid-cols-2 lg:grid-cols-12">
          {/* Company + contact */}
          <div className="lg:col-span-4">
            <Link href="/" aria-label="LALogix home">
              <img src={logo.src} alt={logo.alt} width={logo.width} height={logo.height} loading="lazy" className="h-11 w-auto brightness-0 invert" />
            </Link>
            <p className="mt-4 max-w-sm text-sm text-neutral-300">
              Ready-to-use cloud software for Indian schools, dairies and shops. All products are built, run and billed by {company.name}.
            </p>
            <address className="mt-6 space-y-3 text-sm not-italic">
              {waHref && (
              <a
                href={waHref}
                target="_blank"
                rel="noopener noreferrer"
                onClick={() => trackEvent('whatsapp_click', { location: 'footer' })}
                className={`${linkClass} flex items-center gap-2`}
              >
                <WhatsAppIcon className="h-4 w-4 text-[#25D366]" /> WhatsApp {company.whatsapp_handle}
              </a>
              )}
              <a href={`mailto:${company.email}`} className={`${linkClass} flex items-center gap-2`}>
                <Icon name="mail" className="h-4 w-4 text-brand-400" /> {company.email}
              </a>
              <p className="flex items-start gap-2 text-neutral-300">
                <Icon name="map-pin" className="mt-0.5 h-4 w-4 flex-shrink-0 text-brand-400" />
                <span>
                  {company.name}
                  <br />
                  {company.address}
                </span>
              </p>
            </address>
          </div>

          <FooterGroup title="Products" className="lg:col-span-3">
            {products.map((p) => (
              <li key={p.slug}>
                <Link href={`/products/${p.slug}`} className={linkClass}>
                  {p.name}
                  {p.category ? ` · ${p.category}` : ''}
                </Link>
              </li>
            ))}
          </FooterGroup>

          <FooterGroup title="Company" className="lg:col-span-2">
            {companyLinks.map((l) => (
              <li key={l.href}>
                <Link href={l.href} className={linkClass}>
                  {l.label}
                </Link>
              </li>
            ))}
          </FooterGroup>

          <FooterGroup title="Policies" className="lg:col-span-3">
            {legalLinks.map((l) => (
              <li key={l.href}>
                <Link href={l.href} className={linkClass}>
                  {l.label}
                </Link>
              </li>
            ))}
          </FooterGroup>
        </div>

        <div className="mt-10 flex flex-col gap-3 border-t border-neutral-800 pt-6 text-sm text-neutral-400 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-sm text-neutral-400">
            &copy; {year} {company.name}. {company.legal_form ? `${company.legal_form}, ` : ''}
            {company.city}, {company.state}.
          </p>
          <div className="flex gap-4">
            {Object.entries(company.social).map(([name, url]) => (
              <a key={name} href={url} target="_blank" rel="noopener noreferrer" className={linkClass}>
                {name}
              </a>
            ))}
          </div>
        </div>
      </div>
    </footer>
  );
}

function FooterGroup({ title, className = '', children }: { title: string; className?: string; children: React.ReactNode }) {
  return (
    <div className={className}>
      <h2 className="text-sm font-semibold uppercase tracking-wide text-white">{title}</h2>
      <ul className="mt-4 space-y-2.5" role="list">
        {children}
      </ul>
    </div>
  );
}

import Icon from '@/components/ui/Icon';
import Link from '@/components/ui/Link';
import Faq from '@/components/site/Faq';
import ProductCard from '@/components/site/ProductCard';
import NotifyForm from '@/components/forms/NotifyForm';
import { BookDemoButton, TryDemoButton, WhatsAppButton, PlayStoreLink, btn } from '@/components/site/Cta';
import { trackEvent } from '@/lib/analytics';
import type { Product, ProductCard as Card } from '@/lib/site';

/**
 * One template for every product page, driven by the product entry in
 * config/lalogix.php. Product + SoftwareApplication + FAQPage JSON-LD are
 * rendered by the server (App\Support\Seo::product).
 */
export default function ProductShow({ product, related }: { product: Product; related: Card[] }) {
  const comingSoon = product.status === 'coming_soon';
  const siteHost = product.site_url ? product.site_url.replace(/^https?:\/\//, '').replace(/\/$/, '') : null;

  return (
    <main>
      {/* 1–2. Logo + promise, Try / Book buttons */}
      <section className="bg-gradient-to-br from-brand-50 via-white to-brand-100">
        <div className="mx-auto grid max-w-7xl items-center gap-10 px-4 py-12 sm:px-6 md:py-16 lg:grid-cols-2 lg:px-8">
          <div>
            <nav aria-label="Breadcrumb" className="mb-4 text-sm text-neutral-600">
              <Link href="/products" className="text-neutral-700 hover:text-neutral-900">
                Products
              </Link>{' '}
              / <span aria-current="page">{product.name}</span>
            </nav>
            <div className="flex items-center gap-3">
              <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-brand-700 shadow-sm">
                <Icon name={product.icon} className="h-7 w-7" />
              </span>
              <div>
                <h1 className="text-3xl font-bold tracking-tight text-neutral-900 md:text-4xl">{product.name}</h1>
                {product.category && <p className="text-base font-medium text-neutral-700">{product.category}</p>}
              </div>
              {comingSoon && <span className="rounded-full bg-amber-100 px-3 py-1 text-sm font-semibold text-amber-900">Coming soon</span>}
            </div>
            {product.promise && <p className="mt-6 text-2xl font-semibold leading-snug text-neutral-900 md:text-3xl">{product.promise}</p>}
            {product.bullets.length > 0 && (
              <ul className="mt-5 flex flex-wrap gap-2" role="list">
                {product.bullets.map((b) => (
                  <li key={b} className="rounded-full bg-white px-3 py-1 text-sm font-medium text-neutral-800 shadow-sm">
                    {b}
                  </li>
                ))}
              </ul>
            )}
            <div className="mt-8 flex flex-wrap items-start gap-3">
              {comingSoon ? (
                <a href="#notify" className={btn.primary}>
                  <Icon name="bell" className="h-4 w-4" /> Notify me at launch
                </a>
              ) : (
                <>
                  <TryDemoButton product={product} className={btn.primary} />
                  {product.billed && <BookDemoButton product={product.slug} className={btn.secondary} label="Book a Free Demo" />}
                </>
              )}
              <PlayStoreLink url={product.play_store_url} product={product.name} />
            </div>
          </div>
          {product.screenshots[0] && (
            <img
              src={product.screenshots[0].src}
              alt={product.screenshots[0].alt}
              width={product.screenshots[0].width}
              height={product.screenshots[0].height}
              className="w-full rounded-2xl border border-neutral-200 shadow-lg"
              {...{ fetchpriority: 'high' }}
            />
          )}
        </div>
      </section>

      {/* Coming soon: notify-me */}
      {comingSoon && (
        <section id="notify" className="scroll-mt-24 bg-white">
          <div className="mx-auto max-w-3xl px-4 py-12 sm:px-6">
            <h2 className="text-2xl font-bold text-neutral-900">Be the first to know</h2>
            <p className="mt-2 text-neutral-700">Leave your number and we will WhatsApp you when {product.name} opens.</p>
            <div className="mt-6">
              <NotifyForm productKey={product.lead_key} productName={product.name} />
            </div>
          </div>
        </section>
      )}

      {/* 3. Outcomes */}
      {product.outcomes.length > 0 && (
        <section className="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8" aria-labelledby="outcomes-title">
          <h2 id="outcomes-title" className="text-center text-3xl font-bold text-neutral-900">
            What changes with {product.name}
          </h2>
          <div className="mt-10 grid gap-6 md:grid-cols-3">
            {product.outcomes.map((o, i) => (
              <div key={o.title} className="rounded-2xl border border-neutral-200 bg-white p-6">
                <span className="text-sm font-bold text-brand-700">0{i + 1}</span>
                <h3 className="mt-2 text-lg font-semibold text-neutral-900">{o.title}</h3>
                {o.text && <p className="mt-2 text-neutral-700">{o.text}</p>}
              </div>
            ))}
          </div>
        </section>
      )}

      {/* 4. Screenshots */}
      {product.screenshots.length > 1 && (
        <section className="bg-white" aria-labelledby="screens-title">
          <div className="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
            <h2 id="screens-title" className="text-center text-3xl font-bold text-neutral-900">
              See {product.name}
            </h2>
            <div className="mt-10 grid gap-6 sm:grid-cols-2">
              {product.screenshots.map((s) => (
                <figure key={s.src}>
                  <img src={s.src} alt={s.alt} width={s.width} height={s.height} loading="lazy" decoding="async" className="w-full rounded-xl border border-neutral-200" />
                  <figcaption className="mt-2 text-sm text-neutral-600">{s.alt.replace(`${product.name}: `, '')}</figcaption>
                </figure>
              ))}
            </div>
          </div>
        </section>
      )}

      {/* 5. Features + 6. Who it's for */}
      <section className="mx-auto grid max-w-7xl gap-10 px-4 py-14 sm:px-6 lg:grid-cols-3 lg:px-8">
        {product.features.length > 0 && (
          <div className="lg:col-span-2">
            <h2 className="text-3xl font-bold text-neutral-900">Features</h2>
            <ul className="mt-6 grid gap-5 sm:grid-cols-2" role="list">
              {product.features.map((f) => (
                <li key={f.title} className="flex gap-3">
                  <Icon name="check" className="mt-1 h-5 w-5 flex-shrink-0 text-[#0f7a40]" />
                  <div>
                    <h3 className="text-base font-semibold text-neutral-900">{f.title}</h3>
                    {f.text && <p className="mt-1 text-neutral-700">{f.text}</p>}
                  </div>
                </li>
              ))}
            </ul>
          </div>
        )}
        {product.who_for.length > 0 && (
          <div className="rounded-2xl bg-neutral-900 p-6 text-white">
            <h2 className="text-2xl font-bold text-white">Who it's for</h2>
            <ul className="mt-4 space-y-3" role="list">
              {product.who_for.map((w) => (
                <li key={w} className="flex gap-2 text-neutral-200">
                  <Icon name="arrow-right" className="mt-1 h-4 w-4 flex-shrink-0 text-brand-400" />
                  {w}
                </li>
              ))}
            </ul>
          </div>
        )}
      </section>

      {/* 7. Pricing hint */}
      {product.price_hint && (
        <section className="mx-auto max-w-7xl px-4 pb-14 sm:px-6 lg:px-8">
          <div className="flex flex-col items-start justify-between gap-4 rounded-2xl border border-brand-200 bg-brand-50 p-6 md:flex-row md:items-center">
            <div>
              <h2 className="text-xl font-bold text-neutral-900">Pricing</h2>
              <p className="mt-1 text-2xl font-bold text-neutral-900">{product.price_hint}</p>
              {product.trial && <p className="mt-1 text-neutral-700">{product.trial}</p>}
              {product.billed && <p className="mt-1 text-sm text-neutral-600">Billed by LALogix Enterprises with a GST invoice.</p>}
            </div>
            <Link href="/pricing" className={btn.secondary}>
              Compare pricing for all products
            </Link>
          </div>
        </section>
      )}

      {/* 8. FAQ */}
      <Faq items={product.faq} title={`${product.name} questions`} />

      {/* 9. WhatsApp CTA + 10. Product site / Play Store */}
      <section className="bg-neutral-900">
        <div className="mx-auto flex max-w-4xl flex-col items-center gap-4 px-4 py-14 text-center sm:px-6">
          <h2 className="text-3xl font-bold text-white">Questions about {product.name}?</h2>
          <p className="text-neutral-300">Message us on WhatsApp and talk to a person, not a bot.</p>
          <div className="flex flex-wrap justify-center gap-3">
            <WhatsAppButton text={product.whatsapp_text} label={`Ask about ${product.name} on WhatsApp`} location={`product_${product.slug}`} />
            {product.site_url && siteHost && (
              <a
                href={product.site_url}
                target="_blank"
                rel="noopener"
                className={btn.secondary}
                onClick={() => trackEvent('try_demo_click', { product: product.name, destination: product.site_url })}
              >
                Visit {siteHost} <Icon name="external-link" className="h-4 w-4" />
                <span className="sr-only">(opens in a new tab)</span>
              </a>
            )}
            <PlayStoreLink url={product.play_store_url} product={product.name} />
          </div>
        </div>
      </section>

      {related.length > 0 && (
        <section className="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8" aria-labelledby="related-title">
          <h2 id="related-title" className="text-2xl font-bold text-neutral-900">
            Other LALogix products
          </h2>
          <div className="mt-6 grid gap-6 md:grid-cols-3">
            {related.map((p) => (
              <ProductCard key={p.slug} product={p} />
            ))}
          </div>
        </section>
      )}
    </main>
  );
}

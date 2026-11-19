import Link from '@/components/ui/Link';
import Icon from '@/components/ui/Icon';
import PageHero from '@/components/site/PageHero';
import { BookDemoButton, TryDemoButton, WhatsAppButton, btn } from '@/components/site/Cta';
import type { ProductCard } from '@/lib/site';

/** /pricing: one card per product with its price hint and trial line (from config/lalogix.php). */
export default function Pricing({ products }: { products: ProductCard[] }) {
  return (
    <main>
      <PageHero
        title="Simple pricing in rupees"
        subtitle="Every product is billed by LALogix Enterprises with a GST invoice. Try it first, pay only when it works for you."
      />

      <section className="mx-auto max-w-7xl px-4 py-12 sm:px-6 md:py-16 lg:px-8">
        <h2 className="sr-only">Prices by product</h2>
        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {products.map((p) => (
            <article key={p.slug} className="flex flex-col rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm">
              <div className="flex items-center gap-3">
                <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-700">
                  <Icon name={p.icon} className="h-6 w-6" />
                </span>
                <div>
                  <h3 className="text-lg font-bold text-neutral-900">{p.name}</h3>
                  {p.category && <p className="text-sm text-neutral-600">{p.category}</p>}
                </div>
              </div>
              <p className="mt-5 text-2xl font-bold text-neutral-900">{p.price_hint}</p>
              {p.trial && <p className="mt-1 text-sm text-neutral-700">{p.trial}</p>}
              {p.billed && <p className="mt-1 text-sm text-neutral-600">GST invoice included</p>}
              <div className="mt-auto flex flex-wrap gap-2 pt-6">
                {p.status === 'coming_soon' ? (
                  <Link href={`/products/${p.slug}#notify`} className={btn.primary}>
                    Notify me
                  </Link>
                ) : (
                  <>
                    <TryDemoButton product={p} className={btn.secondary} />
                    {p.billed && <BookDemoButton product={p.slug} label="Ask for a quote" />}
                  </>
                )}
              </div>
              <Link href={`/products/${p.slug}`} className="mt-4 text-sm font-semibold text-brand-700 hover:text-brand-800">
                See what {p.name} includes
              </Link>
            </article>
          ))}
        </div>

        <div className="mt-12 rounded-2xl border border-neutral-200 bg-white p-6 text-center md:p-8">
          <h2 className="text-2xl font-bold text-neutral-900">Need a plan for more than one product?</h2>
          <p className="mx-auto mt-2 max-w-2xl text-neutral-700">One team, one bill: we can combine products on a single GST invoice.</p>
          <div className="mt-5 flex flex-wrap justify-center gap-3">
            <BookDemoButton label="Talk to us" />
            <WhatsAppButton text="Hi, I'd like pricing for LALogix products." location="pricing" />
          </div>
        </div>
      </section>
    </main>
  );
}

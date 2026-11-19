import Link from '@/components/ui/Link';
import Icon from '@/components/ui/Icon';
import PageHero from '@/components/site/PageHero';
import { BookDemoButton } from '@/components/site/Cta';
import type { ProductCard } from '@/lib/site';

interface Industry {
  icon: string;
  title: string;
  text: string | null;
  products: string[];
}

/** /industries: which product fits which kind of business. */
export default function Industries({ industries, products }: { industries: Industry[]; products: ProductCard[] }) {
  const bySlug = Object.fromEntries(products.map((p) => [p.slug, p]));

  return (
    <main>
      <PageHero title="Software for your kind of business" subtitle="Find the ready-made LALogix product that fits how you work." />
      <section className="mx-auto max-w-6xl px-4 py-12 sm:px-6 md:py-16">
        <div className="grid gap-6 md:grid-cols-2">
          {industries.map((ind) => (
            <article key={ind.title} className="rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm">
              <div className="flex items-center gap-3">
                <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-700">
                  <Icon name={ind.icon} className="h-6 w-6" />
                </span>
                <h2 className="text-xl font-bold text-neutral-900">{ind.title}</h2>
              </div>
              {ind.text && <p className="mt-3 text-neutral-700">{ind.text}</p>}
              <div className="mt-4 flex flex-wrap gap-3">
                {ind.products
                  .map((slug) => bySlug[slug])
                  .filter(Boolean)
                  .map((p) => (
                    <Link key={p.slug} href={`/products/${p.slug}`} className="inline-flex items-center gap-1 font-semibold text-brand-700 hover:text-brand-800">
                      Explore {p.name}
                      <Icon name="arrow-right" className="h-4 w-4" />
                    </Link>
                  ))}
              </div>
            </article>
          ))}
        </div>
        <div className="mt-12 text-center">
          <p className="text-neutral-700">Your business is not listed?</p>
          <div className="mt-3">
            <BookDemoButton label="Tell us what you need" />
          </div>
        </div>
      </section>
    </main>
  );
}

import Link from '@/components/ui/Link';
import Icon from '@/components/ui/Icon';
import { BookDemoButton, TryDemoButton, PlayStoreLink, btn } from './Cta';
import type { ProductCard as Card } from '@/lib/site';

/** Product card: logo, promise, 3 bullets, price hint, Try + Book buttons, descriptive link. */
export default function ProductCard({ product, headingLevel = 'h3' }: { product: Card; headingLevel?: 'h2' | 'h3' }) {
  const Heading = headingLevel;
  const comingSoon = product.status === 'coming_soon';

  return (
    <article className="flex h-full flex-col rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm transition-shadow hover:shadow-md">
      <div className="flex items-center gap-3">
        <span className="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700">
          <Icon name={product.icon} className="h-6 w-6" />
        </span>
        <div>
          <Heading className="text-lg font-bold text-neutral-900">{product.name}</Heading>
          {product.category && <p className="text-sm text-neutral-600">{product.category}</p>}
        </div>
        {comingSoon && <span className="ml-auto rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900">Coming soon</span>}
      </div>

      {product.promise && <p className="mt-4 text-base font-medium text-neutral-800">{product.promise}</p>}

      {product.bullets.length > 0 && (
        <ul className="mt-4 space-y-2" role="list">
          {product.bullets.map((b) => (
            <li key={b} className="flex items-start gap-2 text-sm text-neutral-700">
              <Icon name="check" className="mt-0.5 h-4 w-4 flex-shrink-0 text-[#128C4B]" />
              {b}
            </li>
          ))}
        </ul>
      )}

      {product.price_hint && <p className="mt-4 text-sm font-semibold text-neutral-900">{product.price_hint}</p>}

      <div className="mt-auto pt-6">
        <div className="flex flex-wrap gap-2">
          {comingSoon ? (
            <Link href={`/products/${product.slug}#notify`} className={btn.primary}>
              <Icon name="bell" className="h-4 w-4" /> Notify me
            </Link>
          ) : (
            <>
              <TryDemoButton product={product} className={btn.primary} />
              {product.billed && <BookDemoButton product={product.slug} className={btn.secondary} label="Book Demo" />}
            </>
          )}
          <PlayStoreLink url={product.play_store_url} product={product.name} />
        </div>
        <Link href={`/products/${product.slug}`} className="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-brand-700 hover:text-brand-800">
          Explore {product.name}
          <Icon name="arrow-right" className="h-4 w-4" />
        </Link>
      </div>
    </article>
  );
}

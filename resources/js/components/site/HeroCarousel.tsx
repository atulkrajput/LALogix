import { useEffect, useState } from 'react';
import Icon from '@/components/ui/Icon';
import type { ProductCard } from '@/lib/site';

/** Hero screenshot carousel: one slide per product (first screenshot of each). */
export default function HeroCarousel({ products }: { products: ProductCard[] }) {
  const slides = products.filter((p) => p.screenshots[0]).map((p) => ({ product: p, shot: p.screenshots[0] }));
  const [index, setIndex] = useState(0);
  const [paused, setPaused] = useState(false);

  useEffect(() => {
    if (paused || slides.length < 2) return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const t = setInterval(() => setIndex((i) => (i + 1) % slides.length), 5000);
    return () => clearInterval(t);
  }, [paused, slides.length]);

  if (!slides.length) return null;
  const go = (i: number) => setIndex((i + slides.length) % slides.length);

  return (
    <div
      className="relative"
      role="region"
      aria-roledescription="carousel"
      aria-label="Product screenshots"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      onFocus={() => setPaused(true)}
      onBlur={() => setPaused(false)}
    >
      <div className="relative aspect-[8/5] overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-xl">
        {slides.map(({ product, shot }, i) => (
          <img
            key={product.slug}
            src={shot.src}
            alt={shot.alt}
            width={shot.width}
            height={shot.height}
            loading={i === 0 ? 'eager' : 'lazy'}
            decoding="async"
            {...(i === 0 ? { fetchpriority: 'high' } : {})}
            aria-hidden={i !== index}
            className={`absolute inset-0 h-full w-full object-cover transition-opacity duration-500 ${i === index ? 'opacity-100' : 'opacity-0'}`}
          />
        ))}
      </div>
      <div className="mt-3 flex items-center justify-between gap-3">
        <p className="text-sm font-medium text-neutral-800" aria-live="polite">
          {slides[index].product.name}
          {slides[index].product.category ? ` · ${slides[index].product.category}` : ''}
        </p>
        <div className="flex items-center gap-1">
          <button type="button" onClick={() => go(index - 1)} className="rounded-full p-2 text-neutral-700 hover:bg-white" aria-label="Previous screenshot">
            <Icon name="chevron-left" className="h-5 w-5" />
          </button>
          {slides.map((s, i) => (
            <button
              key={s.product.slug}
              type="button"
              onClick={() => go(i)}
              aria-label={`Show ${s.product.name}`}
              aria-current={i === index}
              className="flex h-6 w-6 items-center justify-center"
            >
              <span className={`block h-2 rounded-full transition-all ${i === index ? 'w-5 bg-brand-700' : 'w-2 bg-neutral-400'}`} />
            </button>
          ))}
          <button type="button" onClick={() => go(index + 1)} className="rounded-full p-2 text-neutral-700 hover:bg-white" aria-label="Next screenshot">
            <Icon name="chevron-right" className="h-5 w-5" />
          </button>
        </div>
      </div>
    </div>
  );
}

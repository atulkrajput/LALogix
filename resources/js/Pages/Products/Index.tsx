import PageHero from '@/components/site/PageHero';
import ProductCard from '@/components/site/ProductCard';
import { BookDemoButton, WhatsAppButton } from '@/components/site/Cta';
import type { ProductCard as Card } from '@/lib/site';

/** /products: all products. */
export default function ProductsIndex({ products }: { products: Card[] }) {
  return (
    <main>
      <PageHero
        title="Products built and running today"
        subtitle="Every LALogix product is ready to use. Try a live demo first, then book a free demo when you want help setting up."
      />
      <section className="mx-auto max-w-7xl px-4 py-12 sm:px-6 md:py-16 lg:px-8">
        <h2 className="sr-only">All products</h2>
        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {products.map((p) => (
            <ProductCard key={p.slug} product={p} />
          ))}
        </div>
      </section>
      <section className="bg-neutral-900">
        <div className="mx-auto flex max-w-4xl flex-col items-center gap-4 px-4 py-12 text-center sm:px-6">
          <h2 className="text-2xl font-bold text-white md:text-3xl">Not sure which one fits?</h2>
          <p className="text-neutral-300">Tell us about your business and we will suggest the right product.</p>
          <div className="flex flex-wrap justify-center gap-3">
            <BookDemoButton />
            <WhatsAppButton text="Hi, which LALogix product fits my business?" location="products_index" />
          </div>
        </div>
      </section>
    </main>
  );
}

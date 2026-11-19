import Icon from '@/components/ui/Icon';
import Faq from '@/components/site/Faq';
import ProductCard from '@/components/site/ProductCard';
import HeroCarousel from '@/components/site/HeroCarousel';
import Quiz, { type QuizConfig } from '@/components/site/Quiz';
import ShortDemoForm from '@/components/forms/ShortDemoForm';
import { BookDemoButton, WhatsAppButton, btn } from '@/components/site/Cta';
import { useSite, type Faq as FaqItem, type ProductCard as Card } from '@/lib/site';
import { trackEvent } from '@/lib/analytics';

interface HomeContent {
  hero: { title: string; subtitle: string };
  how_it_works: { title: string; text: string }[];
  why: { icon: string; title: string; text: string | null }[];
  proof: {
    counters: { value: string; label: string }[];
    testimonials: { quote: string; name: string; role?: string; product?: string }[];
  };
  faq: FaqItem[];
  final_cta: { title: string; reply_line: string | null };
}

interface Props {
  home: HomeContent;
  products: Card[];
  quiz: QuizConfig;
}

/** Homepage. All text comes from config/lalogix.php (`home`, `products`, `quiz`). */
export default function Home({ home, products, quiz }: Props) {
  const { company } = useSite();
  const counters = home.proof.counters.filter((c) => c.value && c.label);
  const testimonials = home.proof.testimonials.filter((t) => t.quote && t.name);

  return (
    <main>
      {/* 1. Hero */}
      <section className="bg-gradient-to-br from-brand-50 via-white to-brand-100">
        <div className="mx-auto grid max-w-7xl items-center gap-10 px-4 py-10 sm:px-6 md:py-16 lg:grid-cols-2 lg:px-8">
          <div>
            <h1 className="text-[2rem] font-bold leading-tight tracking-tight text-neutral-900 sm:text-5xl lg:text-[3.25rem]">{home.hero.title}</h1>
            <p className="mt-5 text-lg leading-relaxed text-neutral-700">{home.hero.subtitle}</p>
            <div className="mt-8 flex flex-wrap gap-3">
              <BookDemoButton className={`${btn.primary} px-6 py-3.5 text-base`} />
              <a
                href="#products"
                className={`${btn.secondary} px-6 py-3.5 text-base`}
                onClick={() => trackEvent('try_demo_click', { product: 'all', destination: 'product_grid' })}
              >
                Try a Live Demo
              </a>
            </div>
            {company.trust_line && <p className="mt-6 text-sm font-medium text-neutral-700">{company.trust_line}</p>}
          </div>
          <HeroCarousel products={products} />
        </div>
      </section>

      {/* 2. Product grid */}
      <section id="products" className="scroll-mt-20" aria-labelledby="products-title">
        <div className="mx-auto max-w-7xl px-4 py-14 sm:px-6 md:py-20 lg:px-8">
          <h2 id="products-title" className="text-center text-3xl font-bold text-neutral-900 md:text-4xl">
            Pick your product. Try it in two minutes.
          </h2>
          <div className="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {products.map((p) => (
              <ProductCard key={p.slug} product={p} />
            ))}
          </div>
        </div>
      </section>

      {/* 3. Quiz */}
      <section id="find-your-solution" className="bg-white" aria-labelledby="quiz-title">
        <div className="mx-auto max-w-4xl px-4 py-14 sm:px-6 md:py-20">
          <h2 id="quiz-title" className="text-center text-3xl font-bold text-neutral-900 md:text-4xl">
            Find your solution
          </h2>
          <p className="mt-3 text-center text-neutral-700">Three quick questions and we will point you to the right product.</p>
          <div className="mt-8 rounded-2xl border border-neutral-200 bg-neutral-50 p-5 md:p-8">
            <Quiz quiz={quiz} products={products} />
          </div>
        </div>
      </section>

      {/* 4. How it works */}
      <section aria-labelledby="how-title">
        <div className="mx-auto max-w-7xl px-4 py-14 sm:px-6 md:py-20 lg:px-8">
          <h2 id="how-title" className="text-center text-3xl font-bold text-neutral-900 md:text-4xl">
            How it works
          </h2>
          <ol className="mt-10 grid gap-6 md:grid-cols-3">
            {home.how_it_works.map((s, i) => (
              <li key={s.title} className="rounded-2xl border border-neutral-200 bg-white p-6">
                <span className="flex h-10 w-10 items-center justify-center rounded-full bg-brand-700 font-bold text-white">{i + 1}</span>
                <h3 className="mt-4 text-lg font-semibold text-neutral-900">{s.title}</h3>
                <p className="mt-2 text-neutral-700">{s.text}</p>
              </li>
            ))}
          </ol>
        </div>
      </section>

      {/* 5. Proof: only rendered when config has real counters / testimonials */}
      {(counters.length > 0 || testimonials.length > 0) && (
        <section className="bg-white" aria-labelledby="proof-title">
          <div className="mx-auto max-w-7xl px-4 py-14 sm:px-6 md:py-20 lg:px-8">
            <h2 id="proof-title" className="text-center text-3xl font-bold text-neutral-900 md:text-4xl">
              Businesses running on LALogix
            </h2>
            {counters.length > 0 && (
              <dl className="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                {counters.map((c) => (
                  <div key={c.label} className="rounded-2xl bg-neutral-50 p-6 text-center">
                    <dd className="text-4xl font-bold text-neutral-900">{c.value}</dd>
                    <dt className="mt-1 text-neutral-700">{c.label}</dt>
                  </div>
                ))}
              </dl>
            )}
            {testimonials.length > 0 && (
              <div className="mt-10 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                {testimonials.map((t) => (
                  <figure key={t.name + t.quote.slice(0, 20)} className="rounded-2xl border border-neutral-200 p-6">
                    <blockquote className="text-neutral-800">“{t.quote}”</blockquote>
                    <figcaption className="mt-4 text-sm font-semibold text-neutral-900">
                      {t.name}
                      {t.role && <span className="font-normal text-neutral-600">, {t.role}</span>}
                    </figcaption>
                  </figure>
                ))}
              </div>
            )}
          </div>
        </section>
      )}

      {/* 6. Why LALogix */}
      <section className="bg-neutral-900" aria-labelledby="why-title">
        <div className="mx-auto max-w-7xl px-4 py-14 sm:px-6 md:py-20 lg:px-8">
          <h2 id="why-title" className="text-center text-3xl font-bold text-white md:text-4xl">
            Why LALogix
          </h2>
          <div className="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            {home.why.map((w) => (
              <div key={w.title} className="rounded-2xl bg-neutral-800 p-6">
                <Icon name={w.icon} className="h-7 w-7 text-brand-400" />
                <h3 className="mt-4 text-lg font-semibold text-white">{w.title}</h3>
                {w.text && <p className="mt-2 text-neutral-300">{w.text}</p>}
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* 7. FAQ (FAQPage JSON-LD is rendered by the server) */}
      <Faq items={home.faq} />

      {/* 8. Final CTA */}
      <section className="bg-gradient-to-br from-brand-50 via-white to-brand-100" aria-labelledby="final-cta-title">
        <div className="mx-auto max-w-6xl px-4 py-14 sm:px-6 md:py-20">
          <h2 id="final-cta-title" className="text-center text-3xl font-bold text-neutral-900 md:text-4xl">
            {home.final_cta.title}
          </h2>
          <div className="mt-10 grid gap-6 md:grid-cols-5">
            <div className="rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm md:col-span-3">
              <ShortDemoForm />
            </div>
            <div className="flex flex-col justify-center rounded-2xl bg-neutral-900 p-6 text-center md:col-span-2">
              <p className="text-lg font-semibold text-white">Rather just message us?</p>
              <p className="mt-2 text-neutral-300">Talk to the team on WhatsApp.</p>
              <div className="mt-5">
                <WhatsAppButton text="Hi, I'd like to see my business on LALogix." label={`WhatsApp ${company.whatsapp_display}`} location="home_final_cta" />
              </div>
            </div>
          </div>
          {home.final_cta.reply_line && <p className="mt-6 text-center text-neutral-700">{home.final_cta.reply_line}</p>}
        </div>
      </section>
    </main>
  );
}

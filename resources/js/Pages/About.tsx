import Icon from '@/components/ui/Icon';
import Link from '@/components/ui/Link';
import PageHero from '@/components/site/PageHero';
import { BookDemoButton, WhatsAppButton } from '@/components/site/Cta';
import { intro, story, milestones, principles } from '@/data/about';
import { useSite } from '@/lib/site';

export default function AboutPage() {
  const { company, products } = useSite();

  return (
    <main>
      <PageHero title="About LALogix" subtitle={intro} />

      <section className="mx-auto max-w-3xl px-4 py-14 sm:px-6">
        <h2 className="text-3xl font-bold text-neutral-900">Our story</h2>
        <p className="mt-4 text-lg leading-relaxed text-neutral-700">{story}</p>
        <ol className="mt-8 space-y-4">
          {milestones.map((m) => (
            <li key={m.year} className="flex items-start gap-4">
              <span className="shrink-0 rounded-full bg-brand-700 px-3 py-1 text-sm font-bold text-white">{m.year}</span>
              <span className="text-neutral-800">{m.event}</span>
            </li>
          ))}
        </ol>
      </section>

      <section className="bg-white">
        <div className="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
          <h2 className="text-center text-3xl font-bold text-neutral-900">How we work</h2>
          <div className="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            {principles.map((p) => (
              <div key={p.title} className="rounded-2xl border border-neutral-200 p-6">
                <Icon name={p.icon} className="h-7 w-7 text-brand-700" />
                <h3 className="mt-4 text-lg font-semibold text-neutral-900">{p.title}</h3>
                <p className="mt-2 text-neutral-700">{p.text}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      <section className="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
        <h2 className="text-3xl font-bold text-neutral-900">Our products</h2>
        <ul className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3" role="list">
          {products.map((p) => (
            <li key={p.slug}>
              <Link href={`/products/${p.slug}`} className="flex items-center gap-3 rounded-xl border border-neutral-200 bg-white p-4 hover:border-brand-600">
                <Icon name={p.icon} className="h-6 w-6 text-brand-700" />
                <span>
                  <span className="block font-semibold text-neutral-900">Explore {p.name}</span>
                  {p.category && <span className="block text-sm text-neutral-600">{p.category}</span>}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      </section>

      <section className="bg-neutral-900">
        <div className="mx-auto flex max-w-4xl flex-col items-center gap-4 px-4 py-14 text-center sm:px-6">
          <h2 className="text-3xl font-bold text-white">Talk to the team</h2>
          <p className="text-neutral-300">
            {company.name} · {company.legal_form} · {company.address}
          </p>
          <div className="flex flex-wrap justify-center gap-3">
            <BookDemoButton />
            <WhatsAppButton text="Hi, I'd like to know more about LALogix." location="about" />
          </div>
        </div>
      </section>
    </main>
  );
}

import Icon from '@/components/ui/Icon';
import type { Faq as FaqItem } from '@/lib/site';

/**
 * FAQ accordion built on <details>, so it works before JavaScript loads (SSR).
 * The matching FAQPage JSON-LD is rendered by the server (App\Support\Seo).
 */
export default function Faq({ items, title = 'Frequently asked questions', id = 'faq' }: { items: FaqItem[]; title?: string; id?: string }) {
  const visible = items.filter((f) => f.q && f.a);
  if (!visible.length) return null;

  return (
    <section id={id} className="bg-white" aria-labelledby={`${id}-title`}>
      <div className="mx-auto max-w-3xl px-4 py-14 sm:px-6 md:py-20">
        <h2 id={`${id}-title`} className="text-center text-3xl font-bold text-neutral-900 md:text-4xl">
          {title}
        </h2>
        <div className="mt-8 divide-y divide-neutral-200 rounded-2xl border border-neutral-200">
          {visible.map((f) => (
            <details key={f.q} className="group px-5 py-4 [&_summary::-webkit-details-marker]:hidden">
              <summary className="flex cursor-pointer list-none items-center justify-between gap-4 text-left font-semibold text-neutral-900">
                {f.q}
                <Icon name="chevron-down" className="h-5 w-5 flex-shrink-0 text-neutral-500 transition-transform group-open:rotate-180" />
              </summary>
              <p className="mt-3 text-neutral-700">{f.a}</p>
            </details>
          ))}
        </div>
      </div>
    </section>
  );
}

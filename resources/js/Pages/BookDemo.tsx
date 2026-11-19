import { useEffect, useState } from 'react';
import ShortDemoForm from '@/components/forms/ShortDemoForm';
import { useSite, whatsappUrl } from '@/lib/site';
import { WhatsAppIcon } from '@/components/ui/Icon';
import { trackEvent } from '@/lib/analytics';

/** /book-demo: every "Book a Free Demo" button lands here. */
export default function BookDemoPage() {
  const { company, calendlyUrl } = useSite();

  return (
    <main>
      <section className="bg-gradient-to-br from-brand-50 via-white to-brand-100">
        <div className="mx-auto max-w-3xl px-4 py-12 text-center sm:px-6 md:py-16">
          <h1 className="text-4xl font-bold tracking-tight text-neutral-900 sm:text-5xl">Book a free demo</h1>
          <p className="mt-4 text-lg leading-relaxed text-neutral-700">
            Leave your name and mobile number. We will WhatsApp you to fix a time and show you the product on a short call.
          </p>
        </div>
      </section>

      <section className="mx-auto grid max-w-5xl gap-8 px-4 py-12 sm:px-6 md:grid-cols-5 md:py-16">
        <div className="rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm md:col-span-3">
          <ShortDemoForm />
        </div>
        <aside className="space-y-4 md:col-span-2">
          <div className="rounded-2xl border border-neutral-200 bg-white p-6">
            <h2 className="text-lg font-semibold text-neutral-900">Prefer WhatsApp?</h2>
            <p className="mt-2 text-sm text-neutral-700">Message us and we will set up the demo there.</p>
            <a
              href={whatsappUrl(company.whatsapp, "Hi, I'd like to book a free demo of a LALogix product.")}
              target="_blank"
              rel="noopener noreferrer"
              onClick={() => trackEvent('whatsapp_click', { location: 'book_demo' })}
              className="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-[#0f7a40] px-4 py-3 font-semibold text-white hover:bg-[#0b6233] hover:text-white"
            >
              <WhatsAppIcon className="h-5 w-5" /> WhatsApp {company.whatsapp_display}
            </a>
          </div>
        </aside>
      </section>

      {calendlyUrl && <CalendlyEmbed url={calendlyUrl} />}
    </main>
  );
}

/** Shown only when CALENDLY_URL is set. The script loads after the page is interactive. */
function CalendlyEmbed({ url }: { url: string }) {
  const [ready, setReady] = useState(false);
  useEffect(() => {
    const s = document.createElement('script');
    s.src = 'https://assets.calendly.com/assets/external/widget.js';
    s.async = true;
    s.onload = () => setReady(true);
    document.body.appendChild(s);
    return () => {
      s.remove();
    };
  }, []);

  return (
    <section className="mx-auto max-w-5xl px-4 pb-16 sm:px-6">
      <h2 className="mb-4 text-2xl font-semibold text-neutral-900">Or pick a time yourself</h2>
      <div className="calendly-inline-widget min-h-[700px] rounded-2xl border border-neutral-200 bg-white" data-url={url} aria-busy={!ready} />
    </section>
  );
}

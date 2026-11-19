import Icon, { WhatsAppIcon } from '@/components/ui/Icon';
import PageHero from '@/components/site/PageHero';
import ShortDemoForm from '@/components/forms/ShortDemoForm';
import { useSite, whatsappUrl } from '@/lib/site';
import { trackEvent } from '@/lib/analytics';

export default function ContactPage() {
  const { company } = useSite();
  const row = 'flex items-start gap-3 rounded-xl border border-neutral-200 bg-white p-4 text-neutral-900 hover:border-brand-600 hover:text-neutral-900';

  return (
    <main>
      <PageHero title="Contact LALogix" subtitle="WhatsApp is the fastest way to reach us. You can also call, e-mail or leave your number below." />
      <section className="mx-auto grid max-w-6xl gap-8 px-4 py-12 sm:px-6 md:grid-cols-5 md:py-16">
        <div className="space-y-3 md:col-span-2">
          <h2 className="text-xl font-semibold text-neutral-900">Reach us directly</h2>
          <a
            href={whatsappUrl(company.whatsapp, "Hi, I'd like to talk to LALogix.")}
            target="_blank"
            rel="noopener noreferrer"
            onClick={() => trackEvent('whatsapp_click', { location: 'contact' })}
            className={row}
          >
            <WhatsAppIcon className="mt-0.5 h-5 w-5 text-[#0f7a40]" />
            <span>
              <span className="block font-semibold">WhatsApp</span>
              <span className="text-neutral-700">{company.whatsapp_display}</span>
            </span>
          </a>
          <a href={`tel:${company.tel}`} className={row}>
            <Icon name="phone" className="mt-0.5 h-5 w-5 text-brand-700" />
            <span>
              <span className="block font-semibold">Phone</span>
              <span className="text-neutral-700">{company.phone_display}</span>
            </span>
          </a>
          <a href={`mailto:${company.email}`} className={row}>
            <Icon name="mail" className="mt-0.5 h-5 w-5 text-brand-700" />
            <span>
              <span className="block font-semibold">E-mail</span>
              <span className="text-neutral-700">{company.email}</span>
            </span>
          </a>
          <div className={row}>
            <Icon name="map-pin" className="mt-0.5 h-5 w-5 text-brand-700" />
            <span>
              <span className="block font-semibold">{company.name}</span>
              <span className="text-neutral-700">{company.address}</span>
              {company.working_days && <span className="mt-1 block text-sm text-neutral-600">{company.working_days}</span>}
            </span>
          </div>
        </div>
        <div className="rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm md:col-span-3">
          <h2 className="mb-4 text-xl font-semibold text-neutral-900">Send us a message</h2>
          <ShortDemoForm submitLabel="Send message" />
        </div>
      </section>
    </main>
  );
}

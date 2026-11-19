import { useId, useState } from 'react';
import Link from '@/components/ui/Link';
import { submitLead } from '@/lib/leads';
import { INDIAN_MOBILE, useSite } from '@/lib/site';
import { useSearchParams } from '@/lib/navigation';
import { trackEvent } from '@/lib/analytics';
import { btn } from '@/components/site/Cta';

interface Props {
  /** Product slug to pre-select (otherwise taken from ?product= in the URL). */
  product?: string;
  submitLabel?: string;
}

type Errors = Partial<Record<'name' | 'phone' | 'consent', string>>;

const field = 'mt-1 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2.5 text-neutral-900 focus:border-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-600';

/**
 * Short demo form (replaces the old 6-field form): name, Indian mobile,
 * product, optional message and consent. Saved as a lead with source_widget=form.
 */
export default function ShortDemoForm({ product, submitLabel = 'Book my free demo' }: Props) {
  const { products, company } = useSite();
  const params = useSearchParams();
  const id = useId();
  const initialSlug = product ?? params.get('product') ?? '';
  const initial = products.find((p) => p.slug === initialSlug)?.lead_key ?? '';

  const [values, setValues] = useState({ name: '', phone: '', product: initial, message: '', consent: false, website: '' });
  const [errors, setErrors] = useState<Errors>({});
  const [serverError, setServerError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const [done, setDone] = useState(false);

  const set = (k: keyof typeof values) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
    setValues((v) => ({ ...v, [k]: e.target.type === 'checkbox' ? (e.target as HTMLInputElement).checked : e.target.value }));

  const validate = (): Errors => {
    const e: Errors = {};
    if (values.name.trim().length < 2) e.name = 'Please enter your name.';
    if (!INDIAN_MOBILE.test(values.phone.trim())) e.phone = 'Please enter a valid 10-digit Indian mobile number.';
    if (!values.consent) e.consent = 'Please agree so we can contact you.';
    return e;
  };

  const onSubmit = async (ev: React.FormEvent) => {
    ev.preventDefault();
    const e = validate();
    setErrors(e);
    if (Object.keys(e).length) return;

    setSending(true);
    setServerError(null);
    const res = await submitLead(
      {
        name: values.name.trim(),
        phone: values.phone.trim(),
        product: values.product || 'general',
        message: values.message.trim() || undefined,
        source_widget: 'form',
        consent: true,
        website: values.website,
      },
      '/api/demo',
    );
    setSending(false);
    if (res.ok) {
      setDone(true);
      const name = products.find((p) => p.lead_key === values.product)?.name ?? 'general';
      trackEvent('demo_form_submit', { product: name });
    } else {
      setServerError(res.error ?? 'Something went wrong.');
      if (res.errors?.phone) setErrors((x) => ({ ...x, phone: res.errors!.phone[0] }));
    }
  };

  if (done) {
    return (
      <div className="rounded-xl border border-green-200 bg-green-50 p-6 text-center" role="status">
        <p className="text-lg font-semibold text-green-900">
          Thanks! We'll WhatsApp you{company.reply_hours ? ` within ${company.reply_hours} hours` : ' soon'}.
        </p>
        {company.whatsapp_handle && <p className="mt-1 text-green-800">Our WhatsApp username is {company.whatsapp_handle}.</p>}
      </div>
    );
  }

  const err = (k: keyof Errors) =>
    errors[k] ? (
      <p id={`${id}-${k}-error`} className="mt-1 text-sm text-red-700" role="alert">
        {errors[k]}
      </p>
    ) : null;

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4" aria-label="Book a free demo">
      {serverError && (
        <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
          {serverError}
        </p>
      )}
      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <label htmlFor={`${id}-name`} className="block text-sm font-medium text-neutral-800">
            Name <span aria-hidden="true">*</span>
          </label>
          <input
            id={`${id}-name`}
            value={values.name}
            onChange={set('name')}
            autoComplete="name"
            required
            aria-invalid={!!errors.name}
            aria-describedby={errors.name ? `${id}-name-error` : undefined}
            className={field}
          />
          {err('name')}
        </div>
        <div>
          <label htmlFor={`${id}-phone`} className="block text-sm font-medium text-neutral-800">
            Mobile number <span aria-hidden="true">*</span>
          </label>
          <input
            id={`${id}-phone`}
            type="tel"
            inputMode="tel"
            value={values.phone}
            onChange={set('phone')}
            autoComplete="tel"
            placeholder="98765 43210"
            required
            aria-invalid={!!errors.phone}
            aria-describedby={errors.phone ? `${id}-phone-error` : undefined}
            className={field}
          />
          {err('phone')}
        </div>
      </div>
      <div>
        <label htmlFor={`${id}-product`} className="block text-sm font-medium text-neutral-800">
          Product
        </label>
        <select id={`${id}-product`} value={values.product} onChange={set('product')} className={field}>
          <option value="">Not sure yet</option>
          {products.map((p) => (
            <option key={p.slug} value={p.lead_key}>
              {p.name}
              {p.category ? ` (${p.category})` : ''}
            </option>
          ))}
        </select>
      </div>
      <div>
        <label htmlFor={`${id}-message`} className="block text-sm font-medium text-neutral-800">
          Message <span className="font-normal text-neutral-600">(optional)</span>
        </label>
        <textarea id={`${id}-message`} value={values.message} onChange={set('message')} rows={3} maxLength={2000} className={field} />
      </div>
      {/* Honeypot: hidden from people, bots fill it. */}
      <div className="hidden" aria-hidden="true">
        <label htmlFor={`${id}-website`}>Website</label>
        <input id={`${id}-website`} name="website" tabIndex={-1} autoComplete="off" value={values.website} onChange={set('website')} />
      </div>
      <div>
        <label className="flex items-start gap-2 text-sm text-neutral-700">
          <input
            type="checkbox"
            checked={values.consent}
            onChange={set('consent')}
            className="mt-0.5 h-4 w-4 rounded border-neutral-400"
            aria-invalid={!!errors.consent}
            aria-describedby={errors.consent ? `${id}-consent-error` : undefined}
          />
          <span>
            I agree to be contacted on WhatsApp or phone about my request, as described in the{' '}
            <Link href="/privacy-policy" className="text-brand-700 underline">
              privacy policy
            </Link>
            .
          </span>
        </label>
        {err('consent')}
      </div>
      <button type="submit" disabled={sending} className={`${btn.primary} w-full py-3.5 text-base`}>
        {sending ? 'Sending…' : submitLabel}
      </button>
    </form>
  );
}

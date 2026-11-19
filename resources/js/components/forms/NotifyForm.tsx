import { useState } from 'react';
import Link from '@/components/ui/Link';
import { submitLead } from '@/lib/leads';
import { INDIAN_MOBILE } from '@/lib/site';
import { trackEvent } from '@/lib/analytics';
import { btn } from '@/components/site/Cta';

/** "Notify me" for coming-soon products: name + mobile, saved as a lead. */
export default function NotifyForm({ productKey, productName }: { productKey: string; productName: string }) {
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [website, setWebsite] = useState('');
  const [state, setState] = useState<'idle' | 'sending' | 'done'>('idle');
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!INDIAN_MOBILE.test(phone.trim())) {
      setError('Please enter a valid 10-digit Indian mobile number.');
      return;
    }
    setState('sending');
    setError(null);
    const res = await submitLead({
      name: name.trim() || undefined,
      phone: phone.trim(),
      product: productKey,
      message: `Notify me when ${productName} launches`,
      source_widget: 'form',
      consent: true,
      website,
    });
    if (res.ok) {
      setState('done');
      trackEvent('notify_me_submit', { product: productName });
    } else {
      setState('idle');
      setError(res.error ?? 'Something went wrong.');
    }
  };

  if (state === 'done') {
    return (
      <p className="rounded-xl bg-green-50 p-4 text-green-800" role="status">
        Thanks! We will WhatsApp you when {productName} opens.
      </p>
    );
  }

  return (
    <form onSubmit={submit} noValidate className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
      <div>
        <label htmlFor="notify-name" className="block text-sm font-medium text-neutral-800">
          Name
        </label>
        <input id="notify-name" value={name} onChange={(e) => setName(e.target.value)} autoComplete="name" className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2.5" />
      </div>
      <div>
        <label htmlFor="notify-phone" className="block text-sm font-medium text-neutral-800">
          Mobile number <span aria-hidden="true">*</span>
        </label>
        <input
          id="notify-phone"
          type="tel"
          inputMode="tel"
          required
          value={phone}
          onChange={(e) => setPhone(e.target.value)}
          autoComplete="tel"
          aria-invalid={!!error}
          aria-describedby={error ? 'notify-error' : undefined}
          className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2.5"
        />
      </div>
      <input type="text" name="website" value={website} onChange={(e) => setWebsite(e.target.value)} tabIndex={-1} autoComplete="off" className="hidden" aria-hidden="true" />
      <button type="submit" disabled={state === 'sending'} className={btn.primary}>
        {state === 'sending' ? 'Saving…' : 'Notify me'}
      </button>
      {error && (
        <p id="notify-error" role="alert" className="text-sm text-red-700 sm:col-span-3">
          {error}
        </p>
      )}
      <p className="text-xs text-neutral-600 sm:col-span-3">
        By submitting you agree to be contacted on WhatsApp/phone as per our <Link href="/privacy-policy">privacy policy</Link>.
      </p>
    </form>
  );
}

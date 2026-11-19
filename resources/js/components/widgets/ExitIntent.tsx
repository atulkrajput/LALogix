import { useEffect, useRef, useState } from 'react';
import Icon from '@/components/ui/Icon';
import Link from '@/components/ui/Link';
import { submitLead } from '@/lib/leads';
import { INDIAN_MOBILE, useSite } from '@/lib/site';
import { usePathname } from '@/lib/navigation';
import { trackEvent } from '@/lib/analytics';

const COOKIE = 'lx_exit';

/**
 * Exit-intent popup: desktop only (fine pointer, ≥1024px), once per visitor
 * (cookie, 1 year), when the mouse leaves through the top of the window.
 * Asks for a phone number and saves a lead with source_widget=exit.
 * Not shown on the book-demo, policy or thank-you pages, or when no offer text is set.
 */
export default function ExitIntent() {
  const { exitOffer, products } = useSite();
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const [phone, setPhone] = useState('');
  const [website, setWebsite] = useState('');
  const [state, setState] = useState<'idle' | 'sending' | 'done'>('idle');
  const [error, setError] = useState<string | null>(null);
  const dialogRef = useRef<HTMLDivElement>(null);

  const excluded = /^\/(book-demo|thank-you|privacy-policy|terms-and-conditions|refund-policy|shipping-policy|admin)/.test(pathname);

  useEffect(() => {
    if (!exitOffer.text || excluded) return;
    if (document.cookie.split('; ').some((c) => c.startsWith(`${COOKIE}=`))) return;
    if (!window.matchMedia('(pointer: fine) and (min-width: 1024px)').matches) return;

    let armed = false;
    const arm = setTimeout(() => (armed = true), 8000); // not in the first seconds of a visit
    const onLeave = (e: MouseEvent) => {
      if (!armed || e.relatedTarget || e.clientY > 0) return;
      document.cookie = `${COOKIE}=1; Max-Age=${365 * 86400}; Path=/; SameSite=Lax`;
      setOpen(true);
      document.removeEventListener('mouseout', onLeave);
    };
    document.addEventListener('mouseout', onLeave);
    return () => {
      clearTimeout(arm);
      document.removeEventListener('mouseout', onLeave);
    };
  }, [exitOffer.text, excluded]);

  useEffect(() => {
    if (!open) return;
    dialogRef.current?.querySelector<HTMLElement>('input')?.focus();
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open]);

  if (!open) return null;

  const product = products.find((p) => pathname === `/products/${p.slug}`);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!INDIAN_MOBILE.test(phone.trim())) return setError('Please enter a valid 10-digit Indian mobile number.');
    setError(null);
    setState('sending');
    const res = await submitLead({
      phone: phone.trim(),
      product: product?.lead_key,
      message: `Exit offer: ${exitOffer.title ?? ''}`.trim(),
      source_widget: 'exit',
      consent: true,
      website,
    });
    if (res.ok) {
      setState('done');
      trackEvent('exit_offer_submit', { product: product?.name ?? 'general' });
    } else {
      setState('idle');
      setError(res.error ?? 'Something went wrong.');
    }
  };

  return (
    <div className="fixed inset-0 z-[70] flex items-center justify-center bg-black/50 p-4" onClick={() => setOpen(false)}>
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="exit-title"
        className="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"
        onClick={(e) => e.stopPropagation()}
      >
        <button type="button" onClick={() => setOpen(false)} aria-label="Close" className="absolute right-3 top-3 rounded p-1 text-neutral-600 hover:bg-neutral-100">
          <Icon name="x" className="h-5 w-5" />
        </button>
        {state === 'done' ? (
          <p className="pr-6 text-lg font-semibold text-green-900" role="status">
            Thanks! We will WhatsApp you shortly.
          </p>
        ) : (
          <form onSubmit={submit} noValidate>
            <h2 id="exit-title" className="pr-6 text-2xl font-bold text-neutral-900">
              {exitOffer.title}
            </h2>
            <p className="mt-2 text-neutral-700">{exitOffer.text}</p>
            <label htmlFor="exit-phone" className="mt-5 block text-sm font-medium text-neutral-800">
              Mobile number
            </label>
            <input
              id="exit-phone"
              type="tel"
              inputMode="tel"
              autoComplete="tel"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
              aria-invalid={!!error}
              aria-describedby={error ? 'exit-error' : undefined}
              className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2.5"
            />
            <input value={website} onChange={(e) => setWebsite(e.target.value)} tabIndex={-1} autoComplete="off" className="hidden" aria-hidden="true" />
            {error && (
              <p id="exit-error" className="mt-2 text-sm text-red-700" role="alert">
                {error}
              </p>
            )}
            <button type="submit" disabled={state === 'sending'} className="mt-4 w-full rounded-lg bg-brand-700 px-4 py-3 font-semibold text-white hover:bg-brand-800">
              {state === 'sending' ? 'Sending…' : 'WhatsApp me the offer'}
            </button>
            <p className="mt-3 text-xs text-neutral-600">
              We only use your number to contact you about LALogix (<Link href="/privacy-policy">privacy policy</Link>).
            </p>
          </form>
        )}
      </div>
    </div>
  );
}

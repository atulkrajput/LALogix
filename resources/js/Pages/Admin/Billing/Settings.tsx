import { useEffect, useState } from 'react';
import { api, btnPrimary, btnSecondary, inputCls, type StateOption } from '@/lib/billing';
import { Card, ErrorBanner, Field, Notice } from '@/components/billing/ui';

type Profile = Record<string, any>;

const TEXT_FIELDS = [
  'legal_name', 'trade_name', 'gstin', 'pan', 'state_code', 'address_line1', 'address_line2', 'city', 'pincode',
  'email', 'phone', 'website', 'bank_name', 'bank_account_name', 'bank_account_number', 'bank_ifsc', 'bank_branch',
  'upi_id', 'lut_number', 'invoice_notes', 'invoice_terms',
] as const;

const camel = (s: string) => s.replace(/_([a-z0-9])/g, (_, c) => c.toUpperCase());

/** Seller details printed on every invoice. */
export default function BillingSettings() {
  const [form, setForm] = useState<Profile | null>(null);
  const [states, setStates] = useState<StateOption[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api('/api/billing/settings').then(data => {
      const p = data.profile;
      const f: Profile = {};
      TEXT_FIELDS.forEach(k => { f[k] = p[camel(k)] ?? ''; });
      f.payment_terms_days = p.paymentTermsDays ?? 15;
      f.round_off_total = p.roundOffTotal ?? true;
      f.logo = p.logo ?? null;
      f.signature = p.signature ?? null;
      setForm(f);
      setStates(data.states);
    }).catch(e => setError(e.message));
  }, []);

  if (!form) return <div className="text-sm text-neutral-400">{error ?? 'Loading…'}</div>;

  const set = (key: string) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
    let value: string = e.target.value;
    if (key === 'gstin') {
      value = value.toUpperCase().replace(/\s/g, '');
      const code = value.slice(0, 2);
      if (value.length >= 2 && states.some(s => s.code === code)) {
        setForm(f => ({ ...f!, gstin: value, state_code: code, pan: f!.pan || (value.length >= 12 ? value.slice(2, 12) : '') }));
        return;
      }
    }
    setForm(f => ({ ...f!, [key]: value }));
  };

  const pickImage = (key: 'logo' | 'signature') => (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    if (!/^image\/(png|jpeg)$/.test(file.type)) { setError('Use a PNG or JPG image.'); return; }
    if (file.size > 400 * 1024) { setError('The image must be smaller than 400 KB.'); return; }
    const reader = new FileReader();
    reader.onload = () => setForm(f => ({ ...f!, [key]: reader.result as string }));
    reader.readAsDataURL(file);
  };

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true); setError(null); setNotice(null);
    try {
      await api('/api/billing/settings', 'PUT', { ...form, payment_terms_days: Number(form.payment_terms_days) || 0 });
      setNotice('Business profile saved.');
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (err) {
      setError((err as Error).message);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } finally {
      setSaving(false);
    }
  };

  const text = (key: string, label: string, props: React.InputHTMLAttributes<HTMLInputElement> = {}, hint?: string) => (
    <Field label={label} hint={hint}><input value={form[key] ?? ''} onChange={set(key)} className={inputCls} {...props} /></Field>
  );

  return (
    <form onSubmit={save} className="mx-auto max-w-4xl space-y-5">
      <ErrorBanner message={error} onClose={() => setError(null)} />
      <Notice message={notice} />

      <Card title="Business & GST">
        <div className="grid gap-3 sm:grid-cols-2">
          {text('legal_name', 'Legal name *', { required: true }, 'As on your GST registration.')}
          {text('trade_name', 'Trade name', {}, 'Shown as the main name on invoices when set.')}
          {text('gstin', 'GSTIN', { maxLength: 15, className: `${inputCls} font-mono uppercase` }, 'Needed before issuing invoices.')}
          <Field label="GST state *" hint="Decides CGST+SGST (same state) or IGST (other states).">
            <select required value={form.state_code} onChange={set('state_code')} className={inputCls}>
              <option value="">Select state</option>
              {states.map(s => <option key={s.code} value={s.code}>{s.code} - {s.name}</option>)}
            </select>
          </Field>
          {text('pan', 'PAN', { maxLength: 10, className: `${inputCls} font-mono uppercase` })}
          {text('lut_number', 'LUT / ARN number', {}, 'For zero-rated exports without IGST.')}
        </div>
      </Card>

      <Card title="Address & contact">
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="sm:col-span-2">{text('address_line1', 'Address line 1')}</div>
          <div className="sm:col-span-2">{text('address_line2', 'Address line 2')}</div>
          {text('city', 'City')}
          {text('pincode', 'PIN code')}
          {text('email', 'Email', { type: 'email' })}
          {text('phone', 'Phone')}
          {text('website', 'Website')}
        </div>
      </Card>

      <Card title="Payment details (printed on invoices)">
        <div className="grid gap-3 sm:grid-cols-2">
          {text('bank_account_name', 'Account name')}
          {text('bank_account_number', 'Account number')}
          {text('bank_ifsc', 'IFSC', { className: `${inputCls} font-mono uppercase` })}
          {text('bank_name', 'Bank name')}
          {text('bank_branch', 'Branch')}
          {text('upi_id', 'UPI ID')}
        </div>
      </Card>

      <Card title="Invoice defaults">
        <div className="grid gap-3 sm:grid-cols-2">
          {text('payment_terms_days', 'Payment terms (days)', { type: 'number', min: 0, max: 365 }, 'Due date = invoice date + these days.')}
          <label className="mt-6 flex items-center gap-2 text-sm text-neutral-700">
            <input type="checkbox" checked={!!form.round_off_total} onChange={e => setForm({ ...form, round_off_total: e.target.checked })} />
            Round the invoice total to the nearest rupee
          </label>
          <Field label="Default notes" className="sm:col-span-2"><textarea rows={2} value={form.invoice_notes} onChange={set('invoice_notes')} className={inputCls} placeholder="Thank you for your business." /></Field>
          <Field label="Default terms" className="sm:col-span-2"><textarea rows={3} value={form.invoice_terms} onChange={set('invoice_terms')} className={inputCls} placeholder="Payment due within 15 days. Interest @18% p.a. on late payments." /></Field>
        </div>
      </Card>

      <Card title="Logo & signature">
        <div className="grid gap-5 sm:grid-cols-2">
          {(['logo', 'signature'] as const).map(key => (
            <div key={key}>
              <div className="mb-2 text-xs font-medium capitalize text-neutral-600">{key}</div>
              <div className="flex h-24 items-center justify-center rounded-lg border border-dashed border-neutral-300 bg-neutral-50">
                {form[key] ? <img src={form[key]} alt={key} className="max-h-20 max-w-[80%]" /> : <span className="text-xs text-neutral-400">None</span>}
              </div>
              <div className="mt-2 flex gap-2">
                <label className={`${btnSecondary} relative cursor-pointer`}>
                  Upload
                  <input type="file" accept="image/png,image/jpeg" onChange={pickImage(key)} className="sr-only" />
                </label>
                {form[key] && <button type="button" onClick={() => setForm({ ...form, [key]: null })} className={btnSecondary}>Remove</button>}
              </div>
              <p className="mt-1 text-xs text-neutral-400">PNG or JPG, under 400 KB.</p>
            </div>
          ))}
        </div>
      </Card>

      <div className="flex justify-end">
        <button type="submit" disabled={saving} className={btnPrimary}>{saving ? 'Saving…' : 'Save business profile'}</button>
      </div>
    </form>
  );
}

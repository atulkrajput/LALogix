import { useState } from 'react';
import { api, btnPrimary, btnSecondary, inputCls, type Client, type StateOption } from '@/lib/billing';
import { ErrorBanner, Field } from './ui';

const EMPTY = {
  name: '', company: '', gstin: '', pan: '', email: '', phone: '',
  address_line1: '', address_line2: '', city: '', state_code: '', pincode: '', country: 'India', notes: '',
};

/** Create / edit a billing client. GSTIN fills in state and PAN automatically on the server. */
export default function ClientForm({ client, states, onSaved, onCancel }: {
  client?: Client | null;
  states: StateOption[];
  onSaved: (client: Client) => void;
  onCancel: () => void;
}) {
  const [form, setForm] = useState(client ? {
    name: client.name, company: client.company ?? '', gstin: client.gstin ?? '', pan: client.pan ?? '',
    email: client.email ?? '', phone: client.phone ?? '', address_line1: client.addressLine1 ?? '',
    address_line2: client.addressLine2 ?? '', city: client.city ?? '', state_code: client.stateCode ?? '',
    pincode: client.pincode ?? '', country: client.country || 'India', notes: client.notes ?? '',
  } : EMPTY);
  const [noReminders, setNoReminders] = useState(!!client?.noReminders);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const indian = form.country.trim().toLowerCase() === 'india';

  const set = (key: keyof typeof EMPTY) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
    let value = e.target.value;
    if (key === 'gstin') {
      value = value.toUpperCase().replace(/\s/g, '');
      // The GSTIN's first two digits are the state code.
      const code = value.slice(0, 2);
      if (value.length >= 2 && states.some(s => s.code === code)) {
        setForm(f => ({ ...f, gstin: value, state_code: code }));
        return;
      }
    }
    setForm(f => ({ ...f, [key]: value }));
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const data = await api<{ client: Client }>(client ? `/api/billing/clients/${client.id}` : '/api/billing/clients', client ? 'PUT' : 'POST', { ...form, no_reminders: noReminders });
      onSaved(data.client);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={submit} className="space-y-4">
      <ErrorBanner message={error} />
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Contact / billing name *"><input required value={form.name} onChange={set('name')} className={inputCls} /></Field>
        <Field label="Company" hint="Invoices are billed to the company when set."><input value={form.company} onChange={set('company')} className={inputCls} /></Field>
        <Field label="Country"><input value={form.country} onChange={set('country')} className={inputCls} /></Field>
        {indian && (
          <Field label="GSTIN" hint="Leave empty for unregistered (B2C) clients.">
            <input value={form.gstin} onChange={set('gstin')} maxLength={15} placeholder="22AAAAA0000A1Z5" className={`${inputCls} font-mono uppercase`} />
          </Field>
        )}
        <Field label="Email"><input type="email" value={form.email} onChange={set('email')} className={inputCls} /></Field>
        <Field label="Phone"><input value={form.phone} onChange={set('phone')} className={inputCls} /></Field>
        <Field label="Address line 1" className="sm:col-span-2"><input value={form.address_line1} onChange={set('address_line1')} className={inputCls} /></Field>
        <Field label="Address line 2" className="sm:col-span-2"><input value={form.address_line2} onChange={set('address_line2')} className={inputCls} /></Field>
        <Field label="City"><input value={form.city} onChange={set('city')} className={inputCls} /></Field>
        {indian && (
          <Field label="State *" hint={form.gstin ? 'Taken from the GSTIN.' : 'Decides CGST+SGST or IGST.'}>
            <select required value={form.state_code} onChange={set('state_code')} disabled={form.gstin.length >= 2} className={inputCls}>
              <option value="">Select state</option>
              {states.map(s => <option key={s.code} value={s.code}>{s.code} - {s.name}</option>)}
            </select>
          </Field>
        )}
        <Field label={indian ? 'PIN code' : 'Postal code'}><input value={form.pincode} onChange={set('pincode')} className={inputCls} /></Field>
        {indian && <Field label="PAN" hint={form.gstin ? 'Taken from the GSTIN if empty.' : undefined}><input value={form.pan} onChange={set('pan')} maxLength={10} className={`${inputCls} font-mono uppercase`} /></Field>}
        <Field label="Notes" className="sm:col-span-2"><textarea rows={2} value={form.notes} onChange={set('notes')} className={inputCls} /></Field>
        <label className="flex items-center gap-2 text-sm text-neutral-700 sm:col-span-2">
          <input type="checkbox" checked={noReminders} onChange={e => setNoReminders(e.target.checked)} /> Don't send automatic payment reminders to this client
        </label>
      </div>
      <div className="flex justify-end gap-2">
        <button type="button" onClick={onCancel} className={btnSecondary}>Cancel</button>
        <button type="submit" disabled={saving} className={btnPrimary}>{saving ? 'Saving…' : client ? 'Save client' : 'Add client'}</button>
      </div>
    </form>
  );
}

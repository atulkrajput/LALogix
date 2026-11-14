import { useState } from 'react';
import { api, btnPrimary, btnSecondary, inputCls, money, today, type Client, type Product, type WorkItem } from '@/lib/billing';
import { ErrorBanner, Field } from './ui';

const GST_RATES = [0, 5, 12, 18, 28];

/** Log billable work for a client (invoiced later from the work list or the invoice builder). */
export default function WorkForm({ item, clients, products, clientId, onSaved, onCancel }: {
  item?: WorkItem | null;
  clients: Pick<Client, 'id' | 'name' | 'company'>[];
  products: Product[];
  clientId?: number | null;
  onSaved: (item: WorkItem) => void;
  onCancel: () => void;
}) {
  const services = products.find(p => p.kind === 'services') ?? products[0];
  const [form, setForm] = useState({
    client_id: String(item?.clientId ?? clientId ?? ''),
    product_id: String(item?.productId ?? services?.id ?? ''),
    title: item?.title ?? '',
    description: item?.description ?? '',
    sac: item?.sac ?? services?.defaultSac ?? '',
    quantity: item?.quantity ?? 1,
    unit: item?.unit ?? 'NOS',
    rate: item?.rate ?? '',
    gst_rate: item?.gstRate ?? services?.defaultGstRate ?? 18,
    work_date: item?.workDate ?? today(),
  });
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const set = (key: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
    const value = e.target.value;
    if (key === 'product_id') {
      const p = products.find(x => x.id === Number(value));
      setForm(f => ({ ...f, product_id: value, sac: p?.defaultSac ?? f.sac, gst_rate: p?.defaultGstRate ?? f.gst_rate }));
      return;
    }
    setForm(f => ({ ...f, [key]: value }));
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true); setError(null);
    try {
      const body = { ...form, client_id: Number(form.client_id), product_id: form.product_id ? Number(form.product_id) : null,
        quantity: Number(form.quantity), rate: Number(form.rate), gst_rate: Number(form.gst_rate), sac: form.sac || null };
      const data = await api<{ workItem: WorkItem }>(item ? `/api/billing/work/${item.id}` : '/api/billing/work', item ? 'PUT' : 'POST', body);
      onSaved(data.workItem);
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
        <Field label="Client *">
          <select required value={form.client_id} onChange={set('client_id')} className={inputCls} disabled={!!clientId && !item}>
            <option value="">Select client</option>
            {clients.map(c => <option key={c.id} value={c.id}>{c.company || c.name}</option>)}
          </select>
        </Field>
        <Field label="Product / business line">
          <select value={form.product_id} onChange={set('product_id')} className={inputCls}>
            {products.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        </Field>
        <Field label="Work done *" className="sm:col-span-2"><input required value={form.title} onChange={set('title')} className={inputCls} placeholder="e.g. Website redesign – phase 1" /></Field>
        <Field label="Details (printed under the line)" className="sm:col-span-2"><textarea rows={2} value={form.description} onChange={set('description')} className={inputCls} /></Field>
        <Field label="Date"><input type="date" value={form.work_date} onChange={set('work_date')} className={inputCls} /></Field>
        <Field label="SAC/HSN"><input value={form.sac} onChange={set('sac')} maxLength={8} className={inputCls} /></Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Quantity *"><input required type="number" step="0.01" min="0.01" value={form.quantity} onChange={set('quantity')} className={inputCls} /></Field>
          <Field label="Unit"><input value={form.unit} onChange={set('unit')} maxLength={10} className={`${inputCls} uppercase`} placeholder="NOS / HRS" /></Field>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Rate (₹) *"><input required type="number" step="0.01" min="0" value={form.rate} onChange={set('rate')} className={inputCls} /></Field>
          <Field label="GST">
            <select value={form.gst_rate} onChange={set('gst_rate')} className={inputCls}>
              {GST_RATES.map(r => <option key={r} value={r}>{r}%</option>)}
            </select>
          </Field>
        </div>
      </div>
      <div className="flex items-center justify-between gap-2">
        <span className="text-sm text-neutral-500">Amount before tax: <span className="font-medium text-neutral-800">{money(Number(form.quantity || 0) * Number(form.rate || 0))}</span></span>
        <div className="flex gap-2">
          <button type="button" onClick={onCancel} className={btnSecondary}>Cancel</button>
          <button type="submit" disabled={saving} className={btnPrimary}>{saving ? 'Saving…' : item ? 'Save work' : 'Add work'}</button>
        </div>
      </div>
    </form>
  );
}

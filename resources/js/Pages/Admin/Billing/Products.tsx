import { useEffect, useState } from 'react';
import { api, btnPrimary, btnSecondary, inputCls, type Product } from '@/lib/billing';
import { ErrorBanner, Field, Modal } from '@/components/billing/ui';
import ConnectProduct from '@/components/billing/ConnectProduct';

const KINDS = { services: 'Services', saas: 'SaaS', ecommerce: 'E-commerce' } as const;
const GST_RATES = [0, 5, 12, 18, 28];

const emptyForm = { name: '', kind: 'saas', website: '', invoice_prefix: '', credit_note_prefix: '', default_sac: '', default_gst_rate: 18, is_active: true };

/** Business lines under the one GSTIN, each with its own invoice series. */
export default function ProductsPage() {
  const [products, setProducts] = useState<Product[]>([]);
  const [editing, setEditing] = useState<Product | 'new' | null>(null);
  const [connecting, setConnecting] = useState<Product | null>(null);
  const [form, setForm] = useState<typeof emptyForm>(emptyForm);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const load = () => api<{ products: Product[] }>('/api/billing/products').then(d => setProducts(d.products)).catch(e => setError(e.message));
  useEffect(() => { load(); }, []);

  const open = (p: Product | 'new') => {
    setError(null);
    setEditing(p);
    setForm(p === 'new' ? emptyForm : {
      name: p.name, kind: p.kind, website: p.website ?? '', invoice_prefix: p.invoicePrefix ?? '',
      credit_note_prefix: p.creditNotePrefix ?? '', default_sac: p.defaultSac ?? '',
      default_gst_rate: p.defaultGstRate, is_active: p.isActive ?? true,
    });
  };

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true); setError(null);
    try {
      if (editing === 'new') await api('/api/billing/products', 'POST', form);
      else if (editing) await api(`/api/billing/products/${editing.id}`, 'PUT', form);
      setEditing(null);
      await load();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  const remove = async (p: Product) => {
    if (!confirm(`Delete ${p.name}?`)) return;
    try { await api(`/api/billing/products/${p.id}`, 'DELETE'); await load(); } catch (err) { setError((err as Error).message); }
  };

  const set = (key: keyof typeof emptyForm) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setForm(f => ({ ...f, [key]: key === 'invoice_prefix' || key === 'credit_note_prefix' ? e.target.value.toUpperCase() : e.target.value }));

  const fy = (() => { const d = new Date(); const y = d.getMonth() >= 3 ? d.getFullYear() : d.getFullYear() - 1; return `${String(y).slice(2)}-${String(y + 1).slice(2)}`; })();

  return (
    <div className="space-y-4">
      {!editing && <ErrorBanner message={error} onClose={() => setError(null)} />}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="max-w-2xl text-sm text-neutral-500">
          Every product bills under your one GSTIN with its own invoice series, e.g. <span className="font-mono">LAL/{fy}/0001</span>.
          GST allows multiple series, as long as each is unique and continuous within the financial year.
        </p>
        <button onClick={() => open('new')} className={btnPrimary}>+ Add product</button>
      </div>

      <div className="overflow-x-auto rounded-xl border border-neutral-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-neutral-200 bg-neutral-50 text-left">
              {['Product', 'Type', 'Invoice series', 'Credit note series', 'Default SAC / GST', 'Invoices', 'Connection', 'Status', ''].map(h => (
                <th key={h} className="px-4 py-3 font-medium text-neutral-600">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {products.map(p => (
              <tr key={p.id} className="border-b border-neutral-100">
                <td className="px-4 py-3">
                  <div className="font-medium text-neutral-900">{p.name}</div>
                  {p.website && <div className="text-xs text-neutral-400">{p.website}</div>}
                </td>
                <td className="px-4 py-3 text-neutral-600">{KINDS[p.kind]}</td>
                <td className="px-4 py-3 font-mono text-neutral-700">{p.invoicePrefix ? `${p.invoicePrefix}/${fy}/0001` : <span className="font-sans text-xs text-orange-600">Not set</span>}</td>
                <td className="px-4 py-3 font-mono text-neutral-700">{p.creditNotePrefix ? `${p.creditNotePrefix}/${fy}/0001` : <span className="font-sans text-xs text-neutral-400">Not set</span>}</td>
                <td className="px-4 py-3 text-neutral-600">{p.defaultSac || '—'} · {p.defaultGstRate}%</td>
                <td className="px-4 py-3 text-neutral-600">{p._count?.invoices ?? 0}</td>
                <td className="px-4 py-3">
                  <button onClick={() => setConnecting(p)} className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${p.isConnected ? 'bg-blue-100 text-blue-700' : 'bg-neutral-100 text-neutral-500 hover:bg-neutral-200'}`}>
                    {p.isConnected ? 'API connected' : 'Connect'}
                  </button>
                  {p.isConnected && p.lastSyncedAt && <div className="mt-1 text-xs text-neutral-400">synced {new Date(p.lastSyncedAt).toLocaleDateString('en-IN')}</div>}
                </td>
                <td className="px-4 py-3">
                  <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${p.isActive ? 'bg-green-100 text-green-700' : 'bg-neutral-100 text-neutral-500'}`}>{p.isActive ? 'Active' : 'Inactive'}</span>
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-right">
                  <button onClick={() => open(p)} className="text-sm text-brand-600 hover:underline">Edit</button>
                  {!p._count?.invoices && <button onClick={() => remove(p)} className="ml-3 text-sm text-red-500 hover:underline">Delete</button>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {connecting && <ConnectProduct product={connecting} onClose={() => setConnecting(null)} onChanged={load} />}

      {editing && (
        <Modal title={editing === 'new' ? 'Add product' : `Edit ${editing.name}`} onClose={() => setEditing(null)}>
          <form onSubmit={save} className="space-y-4">
            <ErrorBanner message={error} />
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="Name *"><input required value={form.name} onChange={set('name')} className={inputCls} /></Field>
              <Field label="Type">
                <select value={form.kind} onChange={set('kind')} className={inputCls}>
                  {Object.entries(KINDS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </select>
              </Field>
              <Field label="Website" className="sm:col-span-2"><input value={form.website} onChange={set('website')} className={inputCls} placeholder="https://" /></Field>
              <Field label="Invoice prefix" hint="1-5 letters/digits. Can't change once used.">
                <input value={form.invoice_prefix} onChange={set('invoice_prefix')} maxLength={5} className={`${inputCls} font-mono`} placeholder="VIVK" />
              </Field>
              <Field label="Credit note prefix" hint="Must differ from every invoice prefix.">
                <input value={form.credit_note_prefix} onChange={set('credit_note_prefix')} maxLength={5} className={`${inputCls} font-mono`} placeholder="VIVCN" />
              </Field>
              <Field label="Default SAC/HSN" hint="998313 consulting · 998314 software dev · 998315 hosting">
                <input value={form.default_sac} onChange={set('default_sac')} maxLength={8} className={inputCls} />
              </Field>
              <Field label="Default GST rate">
                <select value={form.default_gst_rate} onChange={e => setForm({ ...form, default_gst_rate: Number(e.target.value) })} className={inputCls}>
                  {GST_RATES.map(r => <option key={r} value={r}>{r}%</option>)}
                </select>
              </Field>
              <label className="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" checked={form.is_active} onChange={e => setForm({ ...form, is_active: e.target.checked })} /> Active
              </label>
            </div>
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setEditing(null)} className={btnSecondary}>Cancel</button>
              <button type="submit" disabled={saving} className={btnPrimary}>{saving ? 'Saving…' : 'Save product'}</button>
            </div>
          </form>
        </Modal>
      )}
    </div>
  );
}

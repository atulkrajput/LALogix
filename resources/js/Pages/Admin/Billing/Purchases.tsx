import { useEffect, useMemo, useState } from 'react';
import { ErrorBanner, Field, Modal, StatCard } from '@/components/billing/ui';
import apiFetch from '@/lib/api';
import { api, btnDanger, btnPrimary, btnSecondary, filterCls, formatDate, inputCls, money, today, type Product } from '@/lib/billing';

interface Vendor { id: number; name: string; gstin: string | null; country: string; defaultCategory: string | null }
interface Purchase {
  id: number; vendorId: number | null; productId: number | null; category: string; billNumber: string | null; billDate: string;
  description: string | null; vendorGstin: string | null; supplyType: string; reverseCharge: boolean; taxableValue: number;
  gstRate: number; cgst: number; sgst: number; igst: number; total: number; taxTotal: number; itcEligible: boolean; itcAmount: number;
  itcNote: string | null; paymentStatus: 'paid' | 'unpaid'; paidOn: string | null; paymentMethod: string | null;
  hasAttachment: boolean; attachmentName: string | null; gstr2bStatus: string | null;
  vendor?: { id: number; name: string; gstin: string | null } | null; product?: { id: number; name: string } | null;
}
interface Summary { count: number; taxable: number; gst: number; itc: number; total: number; unpaid: number }

const RATES = [0, 5, 12, 18, 28];
const pad = (n: number) => String(n).padStart(2, '0');
const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
function periods() {
  const t = new Date();
  const out = [{ label: 'All dates', from: '', to: '' }];
  for (let i = 0; i < 12; i++) {
    const s = new Date(t.getFullYear(), t.getMonth() - i, 1);
    out.push({ label: s.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' }), from: iso(s), to: iso(new Date(s.getFullYear(), s.getMonth() + 1, 0)) });
  }
  return out;
}

export default function PurchasesPage() {
  const opts = useMemo(periods, []);
  const [rows, setRows] = useState<Purchase[]>([]);
  const [summary, setSummary] = useState<Summary | null>(null);
  const [categories, setCategories] = useState<Record<string, string>>({});
  const [vendors, setVendors] = useState<Vendor[]>([]);
  const [products, setProducts] = useState<Product[]>([]);
  const [filters, setFilters] = useState({ period: 1, category: '', payment_status: '', q: '' });
  const [editing, setEditing] = useState<Purchase | 'new' | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = () => {
    const p = new URLSearchParams();
    const r = opts[filters.period];
    if (r.from) { p.set('from', r.from); p.set('to', r.to); }
    if (filters.category) p.set('category', filters.category);
    if (filters.payment_status) p.set('payment_status', filters.payment_status);
    if (filters.q) p.set('q', filters.q);
    return api(`/api/billing/purchases?${p}`).then(d => { setRows(d.purchases); setSummary(d.summary); setCategories(d.categories); }).catch(e => setError(e.message));
  };
  useEffect(() => { const t = setTimeout(load, filters.q ? 250 : 0); return () => clearTimeout(t); }, [filters]);
  useEffect(() => {
    api('/api/billing/vendors').then(d => setVendors(d.vendors)).catch(() => {});
    api('/api/billing/invoices/meta').then(d => setProducts(d.products)).catch(() => {});
  }, [editing]);

  const set = (k: keyof typeof filters) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setFilters(f => ({ ...f, [k]: k === 'period' ? Number(e.target.value) : e.target.value }));

  return (
    <div className="space-y-4">
      <ErrorBanner message={error} onClose={() => setError(null)} />
      {summary && (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard label={`Spent (${summary.count} bills, before GST)`} value={money(summary.taxable)} />
          <StatCard label="GST paid" value={money(summary.gst)} />
          <StatCard label="Input tax credit you can claim" value={money(summary.itc)} tone="green" />
          <StatCard label="Unpaid bills" value={money(summary.unpaid)} tone={summary.unpaid > 0 ? 'orange' : 'neutral'} />
        </div>
      )}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap gap-2">
          <input type="search" value={filters.q} onChange={set('q')} placeholder="Supplier, bill no., note" className={`${filterCls} w-52`} aria-label="Search" />
          <select value={filters.period} onChange={set('period')} className={filterCls} aria-label="Period">
            {opts.map((o, i) => <option key={o.label} value={i}>{o.label}</option>)}
          </select>
          <select value={filters.category} onChange={set('category')} className={filterCls} aria-label="Category">
            <option value="">All categories</option>
            {Object.entries(categories).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
          <select value={filters.payment_status} onChange={set('payment_status')} className={filterCls} aria-label="Payment">
            <option value="">Paid & unpaid</option><option value="paid">Paid</option><option value="unpaid">Unpaid</option>
          </select>
        </div>
        <button onClick={() => setEditing('new')} className={btnPrimary}>+ Add purchase / expense</button>
      </div>

      <div className="overflow-x-auto rounded-xl border border-neutral-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-neutral-200 bg-neutral-50 text-left text-xs text-neutral-600">
              {['Date', 'Supplier', 'Bill', 'Category', 'Before GST', 'GST', 'ITC', 'Total', 'Status', ''].map(h => (
                <th key={h} className={`px-4 py-3 font-medium ${['Before GST', 'GST', 'Total'].includes(h) ? 'text-right' : ''}`}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr><td colSpan={10} className="px-4 py-8 text-center text-neutral-400">No purchases recorded for this period.</td></tr>
            ) : rows.map(p => (
              <tr key={p.id} className="border-b border-neutral-100 hover:bg-neutral-50">
                <td className="whitespace-nowrap px-4 py-3 text-neutral-500">{formatDate(p.billDate)}</td>
                <td className="px-4 py-3">
                  <div className="text-neutral-900">{p.vendor?.name ?? '—'}</div>
                  <div className="font-mono text-xs text-neutral-400">{p.vendorGstin || (p.supplyType === 'import' ? 'Import' : 'No GSTIN')}</div>
                </td>
                <td className="px-4 py-3 text-neutral-600">{p.billNumber || '—'}{p.description && <div className="max-w-[16rem] truncate text-xs text-neutral-400">{p.description}</div>}</td>
                <td className="px-4 py-3 text-neutral-600">{categories[p.category] ?? p.category}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums">{money(p.taxableValue)}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums text-neutral-600">{p.taxTotal ? money(p.taxTotal) : '—'}{p.reverseCharge && <div className="text-xs text-orange-600">reverse charge</div>}</td>
                <td className="px-4 py-3 text-xs">
                  {p.itcEligible && p.itcAmount > 0 ? <span className="rounded-full bg-green-100 px-2 py-0.5 font-medium text-green-700">✓ {money(p.itcAmount)}</span>
                    : p.taxTotal > 0 ? <span className="text-neutral-400" title={p.itcNote ?? undefined}>not claimable</span> : <span className="text-neutral-300">—</span>}
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums">{money(p.total)}</td>
                <td className="px-4 py-3 text-xs">
                  <span className={`rounded-full px-2 py-0.5 font-medium ${p.paymentStatus === 'paid' ? 'bg-neutral-100 text-neutral-600' : 'bg-orange-100 text-orange-700'}`}>{p.paymentStatus}</span>
                  {p.gstr2bStatus && <div className={`mt-1 ${p.gstr2bStatus === 'matched' ? 'text-green-700' : 'text-red-600'}`}>2B {p.gstr2bStatus}</div>}
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-right text-xs">
                  {p.hasAttachment && <a href={`/admin/billing/purchases/${p.id}/attachment`} target="_blank" rel="noreferrer" className="mr-3 text-brand-600 hover:underline">📎 Bill</a>}
                  <button onClick={() => setEditing(p)} className="text-brand-600 hover:underline">Edit</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {editing && (
        <PurchaseForm purchase={editing === 'new' ? null : editing} vendors={vendors} products={products} categories={categories}
          onClose={() => setEditing(null)} onSaved={() => { setEditing(null); load(); }} />
      )}
    </div>
  );
}

function PurchaseForm({ purchase, vendors, products, categories, onClose, onSaved }: {
  purchase: Purchase | null; vendors: Vendor[]; products: Product[]; categories: Record<string, string>; onClose: () => void; onSaved: () => void;
}) {
  const [f, setF] = useState({
    vendor_name: purchase?.vendor?.name ?? '', vendor_gstin: purchase?.vendorGstin ?? '', vendor_country: 'India',
    category: purchase?.category ?? 'software', product_id: purchase?.productId ? String(purchase.productId) : '',
    bill_number: purchase?.billNumber ?? '', bill_date: purchase?.billDate ?? today(), description: purchase?.description ?? '',
    taxable_value: purchase ? String(purchase.taxableValue) : '', gst_rate: purchase ? String(purchase.gstRate) : '18',
    tax_mode: 'auto' as 'auto' | 'manual', cgst: purchase ? String(purchase.cgst) : '', sgst: purchase ? String(purchase.sgst) : '', igst: purchase ? String(purchase.igst) : '',
    reverse_charge: purchase?.reverseCharge ?? false, itc_eligible: purchase?.itcEligible ?? true, itc_note: purchase?.itcNote ?? '',
    payment_status: purchase?.paymentStatus ?? 'paid', paid_on: purchase?.paidOn ?? '', payment_method: purchase?.paymentMethod ?? 'bank',
  });
  const [file, setFile] = useState<File | null>(null);
  const [removeFile, setRemoveFile] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const foreign = f.vendor_country.trim().toLowerCase() !== 'india' && f.vendor_country.trim() !== '';

  const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
    const value = e.target.type === 'checkbox' ? (e.target as HTMLInputElement).checked : e.target.value;
    setF(prev => {
      const next = { ...prev, [k]: value };
      if (k === 'vendor_name') {
        const v = vendors.find(x => x.name.toLowerCase() === String(value).toLowerCase());
        if (v) Object.assign(next, { vendor_gstin: v.gstin ?? '', vendor_country: v.country || 'India', category: v.defaultCategory || prev.category });
      }
      if (k === 'vendor_gstin') next.vendor_gstin = String(value).toUpperCase().replace(/\s/g, '');
      if (k === 'vendor_country') next.reverse_charge = String(value).trim().toLowerCase() !== 'india' && String(value).trim() !== '';
      return next;
    });
  };

  // Live GST preview (server recalculates; same rule: other state or import → IGST).
  const taxable = Number(f.taxable_value) || 0;
  const rate = Number(f.gst_rate) || 0;
  const inter = foreign;
  const autoTax = Math.round(taxable * rate) / 100;
  const tax = f.tax_mode === 'manual' ? (Number(f.cgst) || 0) + (Number(f.sgst) || 0) + (Number(f.igst) || 0) : autoTax;
  const total = taxable + (f.reverse_charge ? 0 : tax);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true); setError(null);
    const body = new FormData();
    Object.entries(f).forEach(([k, v]) => body.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : String(v)));
    if (file) body.append('attachment', file);
    if (removeFile) body.append('remove_attachment', '1');
    try {
      const res = await apiFetch(purchase ? `/api/billing/purchases/${purchase.id}` : '/api/billing/purchases', { method: 'POST', body });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || `Could not save (${res.status})`);
      onSaved();
    } catch (err) { setError((err as Error).message); } finally { setSaving(false); }
  };

  const remove = async () => {
    if (!purchase || !confirm('Delete this purchase?')) return;
    await api(`/api/billing/purchases/${purchase.id}`, 'DELETE').catch(err => setError(err.message));
    onSaved();
  };

  return (
    <Modal title={purchase ? 'Edit purchase' : 'Add purchase / expense'} onClose={onClose}>
      <form onSubmit={submit} className="space-y-4">
        <ErrorBanner message={error} />
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Supplier *">
            <input required list="vendor-list" value={f.vendor_name} onChange={set('vendor_name')} className={inputCls} placeholder="e.g. Hostinger, Meta, landlord" />
            <datalist id="vendor-list">{vendors.map(v => <option key={v.id} value={v.name} />)}</datalist>
          </Field>
          <Field label="Supplier GSTIN" hint="Printed on their GST invoice. Needed to claim ITC.">
            <input value={f.vendor_gstin} onChange={set('vendor_gstin')} maxLength={15} disabled={foreign} className={`${inputCls} font-mono uppercase`} />
          </Field>
          <Field label="Supplier country" hint={foreign ? 'Import of services: GST under reverse charge.' : undefined}><input value={f.vendor_country} onChange={set('vendor_country')} className={inputCls} /></Field>
          <Field label="Category *">
            <select value={f.category} onChange={set('category')} className={inputCls}>
              {Object.entries(categories).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </Field>
          <Field label="Bill number"><input value={f.bill_number} onChange={set('bill_number')} className={inputCls} /></Field>
          <Field label="Bill date *"><input type="date" required value={f.bill_date} onChange={set('bill_date')} className={inputCls} /></Field>
          <Field label="For business line">
            <select value={f.product_id} onChange={set('product_id')} className={inputCls}>
              <option value="">Whole company</option>
              {products.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          </Field>
          <Field label="Note"><input value={f.description} onChange={set('description')} className={inputCls} placeholder="What it was for" /></Field>
          <Field label="Amount before GST (₹) *"><input required type="number" step="0.01" min="0" value={f.taxable_value} onChange={set('taxable_value')} className={inputCls} /></Field>
          <Field label="GST rate">
            <select value={f.gst_rate} onChange={set('gst_rate')} className={inputCls}>{RATES.map(r => <option key={r} value={r}>{r}%</option>)}</select>
          </Field>
        </div>

        <div className="rounded-lg bg-neutral-50 p-3 text-sm">
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={f.tax_mode === 'manual'} onChange={e => setF({ ...f, tax_mode: e.target.checked ? 'manual' : 'auto' })} />
            Enter CGST / SGST / IGST exactly as on the bill
          </label>
          {f.tax_mode === 'manual' && (
            <div className="mt-2 grid grid-cols-3 gap-2">
              {(['cgst', 'sgst', 'igst'] as const).map(k => (
                <Field key={k} label={k.toUpperCase()}><input type="number" step="0.01" min="0" value={f[k]} onChange={set(k)} className={inputCls} /></Field>
              ))}
            </div>
          )}
          <div className="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-neutral-600">
            <span>GST: <strong className="text-neutral-900">{money(tax)}</strong>{f.tax_mode === 'auto' && !inter ? ' (CGST+SGST, or IGST if the supplier is in another state)' : ''}</span>
            <span>Payable to supplier: <strong className="text-neutral-900">{money(total)}</strong></span>
          </div>
          <div className="mt-2 flex flex-wrap gap-x-6 gap-y-2">
            <label className="flex items-center gap-2"><input type="checkbox" checked={f.reverse_charge} onChange={set('reverse_charge')} /> Reverse charge (I pay this GST to the government)</label>
            <label className="flex items-center gap-2"><input type="checkbox" checked={f.itc_eligible} onChange={set('itc_eligible')} /> Claim this GST as input tax credit</label>
          </div>
          {!f.itc_eligible && <Field label="Why not claimable" className="mt-2"><input value={f.itc_note} onChange={set('itc_note')} className={inputCls} placeholder="e.g. food & beverages (blocked u/s 17(5)), personal use" /></Field>}
        </div>

        <div className="grid gap-3 sm:grid-cols-3">
          <Field label="Payment">
            <select value={f.payment_status} onChange={set('payment_status')} className={inputCls}><option value="paid">Paid</option><option value="unpaid">Not paid yet</option></select>
          </Field>
          {f.payment_status === 'paid' && <Field label="Paid on"><input type="date" value={f.paid_on} onChange={set('paid_on')} className={inputCls} /></Field>}
          {f.payment_status === 'paid' && (
            <Field label="Method">
              <select value={f.payment_method} onChange={set('payment_method')} className={inputCls}>
                {['bank', 'upi', 'card', 'cash', 'cheque', 'other'].map(m => <option key={m} value={m}>{m.toUpperCase()}</option>)}
              </select>
            </Field>
          )}
        </div>

        <Field label="Bill / receipt (PDF or image, max 5 MB)" hint={purchase?.hasAttachment && !removeFile ? <>Current: {purchase.attachmentName} · <button type="button" onClick={() => setRemoveFile(true)} className="text-red-500 hover:underline">remove</button></> : undefined}>
          <input type="file" accept=".pdf,image/png,image/jpeg,image/webp" onChange={e => setFile(e.target.files?.[0] ?? null)} className={`${inputCls} py-1.5`} />
        </Field>

        <div className="flex items-center justify-between gap-2">
          {purchase ? <button type="button" onClick={remove} className={btnDanger}>Delete</button> : <span />}
          <div className="flex gap-2">
            <button type="button" onClick={onClose} className={btnSecondary}>Cancel</button>
            <button type="submit" disabled={saving} className={btnPrimary}>{saving ? 'Saving…' : 'Save'}</button>
          </div>
        </div>
      </form>
    </Modal>
  );
}

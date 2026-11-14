import { useEffect, useState } from 'react';
import Link from '@/components/ui/Link';
import { Card, ErrorBanner, Field } from '@/components/billing/ui';
import apiFetch from '@/lib/api';
import { api, btnPrimary, btnSecondary, inputCls, type Product } from '@/lib/billing';

interface Result { number: string; outcome: string; id?: number; message?: string }
interface Summary { created: number; unchanged: number; rejected: number; results: Result[] }

const OUTCOME: Record<string, string> = { created: 'bg-green-100 text-green-700', unchanged: 'bg-neutral-100 text-neutral-600', rejected: 'bg-red-100 text-red-700' };

/** CSV import of invoices a product already issued (back-fill, or products without the API yet). */
export default function ImportPage({ ingestUrl }: { ingestUrl: string }) {
  const [products, setProducts] = useState<Product[]>([]);
  const [productId, setProductId] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [summary, setSummary] = useState<Summary | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api<{ products: Product[] }>('/api/billing/products').then(d => {
      setProducts(d.products);
      const first = d.products.find(p => p.kind !== 'services') ?? d.products[0];
      if (first) setProductId(String(first.id));
    }).catch(e => setError(e.message));
  }, []);

  const product = products.find(p => String(p.id) === productId);

  const upload = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!file || !productId) return;
    setBusy(true); setError(null); setSummary(null);
    const body = new FormData();
    body.append('product_id', productId);
    body.append('file', file);
    try {
      const res = await apiFetch('/api/billing/import', { method: 'POST', body });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || `Import failed (${res.status})`);
      setSummary(data);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mx-auto max-w-5xl space-y-5">
      <ErrorBanner message={error} onClose={() => setError(null)} />

      <Card title="Import invoices from CSV">
        <form onSubmit={upload} className="space-y-4">
          <p className="text-sm text-neutral-600">
            For invoices a product has <strong>already issued</strong> to its customers, e.g. last months' VIVK.in invoices, or a product that can't call the API yet.
            One row per invoice line; rows with the same <code>invoice_number</code> become one invoice. Importing the same file again is safe: existing invoices are skipped.
          </p>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Product that issued these invoices *">
              <select required value={productId} onChange={e => setProductId(e.target.value)} className={inputCls}>
                {products.map(p => <option key={p.id} value={p.id}>{p.name}{p.invoicePrefix ? ` (${p.invoicePrefix})` : ''}</option>)}
              </select>
            </Field>
            <Field label="CSV file *" hint="Max 5 MB. Excel: File → Save as → CSV UTF-8.">
              <input required type="file" accept=".csv,text/csv" onChange={e => setFile(e.target.files?.[0] ?? null)} className={`${inputCls} py-1.5`} />
            </Field>
          </div>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <a href="/admin/billing/import/template" className="text-sm text-brand-600 hover:underline">Download CSV template</a>
            <button type="submit" disabled={busy || !file || !productId} className={btnPrimary}>{busy ? 'Importing…' : 'Import'}</button>
          </div>
        </form>
      </Card>

      {summary && (
        <Card title="Import result" actions={<Link href="/admin/billing/invoices" className={btnSecondary}>View invoices</Link>}>
          <div className="mb-4 flex flex-wrap gap-3 text-sm">
            <span className="rounded-full bg-green-100 px-3 py-1 font-medium text-green-700">{summary.created} imported</span>
            <span className="rounded-full bg-neutral-100 px-3 py-1 font-medium text-neutral-600">{summary.unchanged} already there</span>
            <span className={`rounded-full px-3 py-1 font-medium ${summary.rejected ? 'bg-red-100 text-red-700' : 'bg-neutral-100 text-neutral-500'}`}>{summary.rejected} rejected</span>
          </div>
          <div className="max-h-96 overflow-auto">
            <table className="w-full text-sm">
              <tbody>
                {summary.results.map((r, i) => (
                  <tr key={i} className="border-b border-neutral-100 align-top">
                    <td className="py-2 pr-4 font-mono text-xs">{r.id ? <Link href={`/admin/billing/invoices/${r.id}`} className="text-brand-600 hover:underline">{r.number}</Link> : r.number}</td>
                    <td className="py-2 pr-4"><span className={`rounded-full px-2 py-0.5 text-xs font-medium ${OUTCOME[r.outcome] ?? ''}`}>{r.outcome}</span></td>
                    <td className="py-2 text-xs text-red-600">{r.message}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      <Card title="Columns">
        <div className="grid gap-x-6 gap-y-1 text-xs text-neutral-600 sm:grid-cols-2">
          {[
            ['invoice_number *', 'The product\'s own number, e.g. VIVK/26-27/0042 (max 16 characters)'],
            ['invoice_date *', 'YYYY-MM-DD or DD-MM-YYYY'],
            ['customer_name *', 'Customer / contact name'],
            ['customer_company, customer_gstin', 'GSTIN makes it a B2B invoice (state is taken from it)'],
            ['customer_email, customer_phone, customer_address, customer_city, customer_pincode', 'Optional'],
            ['customer_state', '"29", "29-Karnataka" or "Karnataka". Without GSTIN or state, your own state is used (intra-state)'],
            ['customer_country', 'Defaults to India; any other country = export'],
            ['place_of_supply', 'Optional override (state code, 96 = outside India)'],
            ['description *, sac, quantity, unit', 'One line of the invoice'],
            ['rate *, discount, gst_rate *', 'Before tax, in ₹; GST rate in % (e.g. 18)'],
            ['invoice_total', 'Optional check: must match the lines within ₹1'],
            ['paid_amount, paid_date, payment_reference', 'Optional: records the payment'],
          ].map(([col, desc]) => (
            <div key={col} className="py-1"><code className="text-neutral-900">{col}</code>: {desc}</div>
          ))}
        </div>
      </Card>

      <Card title="Automatic sync (recommended)">
        <p className="text-sm text-neutral-600">
          Instead of uploading files, each product can send every invoice the moment it's issued.
          Open <Link href="/admin/billing/products" className="text-brand-600 hover:underline">Billing → Products</Link>, click <strong>Connect</strong> on a product to create its API key, and add a call to
          {' '}<code className="rounded bg-neutral-100 px-1">{ingestUrl}/invoices</code> in that product's code{product ? ` (${product.name})` : ''}.
        </p>
      </Card>
    </div>
  );
}

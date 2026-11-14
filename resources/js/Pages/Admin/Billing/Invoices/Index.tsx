import { useEffect, useState } from 'react';
import Link from '@/components/ui/Link';
import { ErrorBanner, StatCard, StatusBadge } from '@/components/billing/ui';
import { api, btnPrimary, btnSecondary, formatDate, filterCls, inputCls, money, type Client, type Invoice, type Product } from '@/lib/billing';

interface Summary { count: number; taxable: number; tax: number; total: number; received: number; outstanding: number }

const STATUSES = {
  invoice: [['all', 'All'], ['draft', 'Draft'], ['outstanding', 'Outstanding'], ['overdue', 'Overdue'], ['partial', 'Part paid'], ['paid', 'Paid'], ['cancelled', 'Cancelled']],
  credit_note: [['all', 'All'], ['draft', 'Draft'], ['issued', 'Issued'], ['cancelled', 'Cancelled']],
} as const;

/** Month presets for the date filter (current FY months + quick ranges). */
function periodOptions() {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');
  const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const opts: { label: string; from: string; to: string }[] = [{ label: 'All dates', from: '', to: '' }];
  for (let i = 0; i < 12; i++) {
    const start = new Date(now.getFullYear(), now.getMonth() - i, 1);
    const end = new Date(start.getFullYear(), start.getMonth() + 1, 0);
    opts.push({ label: start.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' }), from: iso(start), to: iso(end) });
  }
  const fyStart = now.getMonth() >= 3 ? now.getFullYear() : now.getFullYear() - 1;
  opts.splice(1, 0, { label: `FY ${fyStart}-${String(fyStart + 1).slice(2)}`, from: `${fyStart}-04-01`, to: `${fyStart + 1}-03-31` });
  return opts;
}

export default function InvoicesIndex({ type }: { type: 'invoice' | 'credit_note' }) {
  const isCn = type === 'credit_note';
  const periods = periodOptions();
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [summary, setSummary] = useState<Summary | null>(null);
  const [pagination, setPagination] = useState({ page: 1, pages: 1, total: 0 });
  const [products, setProducts] = useState<Product[]>([]);
  const [clients, setClients] = useState<Client[]>([]);
  const [filters, setFilters] = useState({ status: 'all', product_id: '', client_id: '', period: 0, q: '' });
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api('/api/billing/invoices/meta').then(d => { setProducts(d.products); setClients(d.clients); }).catch(() => {});
  }, []);

  useEffect(() => {
    const t = setTimeout(() => {
      const p = new URLSearchParams({ type, page: String(page) });
      if (filters.status !== 'all') p.set('status', filters.status);
      if (filters.product_id) p.set('product_id', filters.product_id);
      if (filters.client_id) p.set('client_id', filters.client_id);
      if (filters.q) p.set('q', filters.q);
      const period = periods[filters.period];
      if (period.from) { p.set('from', period.from); p.set('to', period.to); }
      setLoading(true);
      api(`/api/billing/invoices?${p}`)
        .then(d => { setInvoices(d.invoices); setSummary(d.summary); setPagination(d.pagination); })
        .catch(e => setError(e.message))
        .finally(() => setLoading(false));
    }, filters.q ? 250 : 0);
    return () => clearTimeout(t);
  }, [filters, page, type]);

  const setFilter = (key: keyof typeof filters) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    setPage(1);
    setFilters(f => ({ ...f, [key]: key === 'period' ? Number(e.target.value) : e.target.value }));
  };

  return (
    <div className="space-y-4">
      <ErrorBanner message={error} onClose={() => setError(null)} />

      {summary && (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <StatCard label={`Issued ${isCn ? 'credit notes' : 'invoices'}`} value={String(summary.count)} />
          <StatCard label="Taxable value" value={money(summary.taxable)} />
          <StatCard label="GST" value={money(summary.tax)} tone="blue" />
          <StatCard label="Total" value={money(summary.total)} />
          {!isCn && <StatCard label="Outstanding" value={money(summary.outstanding)} tone={summary.outstanding > 0 ? 'orange' : 'green'} />}
        </div>
      )}

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap gap-2">
          <input type="search" value={filters.q} onChange={setFilter('q')} placeholder="Number or client" className={`${filterCls} w-48`} aria-label="Search" />
          <select value={filters.period} onChange={setFilter('period')} className={filterCls} aria-label="Period">
            {periods.map((p, i) => <option key={p.label} value={i}>{p.label}</option>)}
          </select>
          <select value={filters.status} onChange={setFilter('status')} className={filterCls} aria-label="Status">
            {STATUSES[type].map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
          <select value={filters.product_id} onChange={setFilter('product_id')} className={filterCls} aria-label="Product">
            <option value="">All products</option>
            {products.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
          <select value={filters.client_id} onChange={setFilter('client_id')} className={filterCls} aria-label="Client">
            <option value="">All clients</option>
            {clients.map(c => <option key={c.id} value={c.id}>{c.company || c.name}</option>)}
          </select>
        </div>
        {!isCn && <Link href="/admin/billing/invoices/new" className={btnPrimary}>+ New invoice</Link>}
      </div>

      <div className="overflow-x-auto rounded-xl border border-neutral-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-neutral-200 bg-neutral-50 text-left">
              <th className="px-4 py-3 font-medium text-neutral-600">Number</th>
              <th className="px-4 py-3 font-medium text-neutral-600">Date</th>
              <th className="px-4 py-3 font-medium text-neutral-600">Client</th>
              <th className="px-4 py-3 font-medium text-neutral-600">Product</th>
              <th className="px-4 py-3 text-right font-medium text-neutral-600">Taxable</th>
              <th className="px-4 py-3 text-right font-medium text-neutral-600">GST</th>
              <th className="px-4 py-3 text-right font-medium text-neutral-600">Total</th>
              {!isCn && <th className="px-4 py-3 text-right font-medium text-neutral-600">Balance</th>}
              <th className="px-4 py-3 font-medium text-neutral-600">Status</th>
            </tr>
          </thead>
          <tbody>
            {loading && invoices.length === 0 ? (
              <tr><td colSpan={9} className="px-4 py-8 text-center text-neutral-400">Loading…</td></tr>
            ) : invoices.length === 0 ? (
              <tr><td colSpan={9} className="px-4 py-8 text-center text-neutral-400">
                {isCn ? 'No credit notes. Raise one from an issued invoice.' : 'No invoices found.'}
              </td></tr>
            ) : invoices.map(inv => (
              <tr key={inv.id} className="border-b border-neutral-100 hover:bg-neutral-50">
                <td className="px-4 py-3">
                  <Link href={`/admin/billing/invoices/${inv.id}`} className="font-medium text-brand-600 hover:underline">{inv.number ?? 'Draft'}</Link>
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-neutral-500">{formatDate(inv.invoiceDate)}</td>
                <td className="px-4 py-3">
                  <div className="text-neutral-900">{inv.billName}</div>
                  <div className="font-mono text-xs text-neutral-400">{inv.billGstin || 'B2C'}</div>
                </td>
                <td className="px-4 py-3 text-neutral-600">
                  {inv.product?.name}
                  {inv.source !== 'manual' && <div className="text-xs text-blue-600">{inv.source === 'api' ? 'via API' : 'imported'}</div>}
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-right">{money(inv.taxableTotal)}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right text-neutral-600">{money(inv.taxTotal)}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right font-medium">{money(inv.grandTotal)}</td>
                {!isCn && <td className="whitespace-nowrap px-4 py-3 text-right">{inv.balanceDue > 0 ? money(inv.balanceDue) : '—'}</td>}
                <td className="px-4 py-3"><StatusBadge status={inv.paymentStatus} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {pagination.pages > 1 && (
        <div className="flex items-center justify-end gap-2 text-sm">
          <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} className={btnSecondary}>Previous</button>
          <span className="text-neutral-500">Page {pagination.page} of {pagination.pages}</span>
          <button disabled={page >= pagination.pages} onClick={() => setPage(p => p + 1)} className={btnSecondary}>Next</button>
        </div>
      )}
    </div>
  );
}

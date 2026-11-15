import { useEffect, useState } from 'react';
import Link from '@/components/ui/Link';
import { ErrorBanner, StatCard } from '@/components/billing/ui';
import { api, btnPrimary, filterCls, formatDate, money } from '@/lib/billing';

export interface QuotationRow {
  id: number; number: string; status: string; displayStatus: string; quoteDate: string; validUntil: string | null;
  billName: string; billGstin: string | null; taxableTotal: number; taxTotal: number; grandTotal: number;
  product?: { id: number; name: string }; invoice?: { id: number; number: string | null } | null;
}

export const QUOTE_STATUS: Record<string, string> = {
  draft: 'bg-neutral-100 text-neutral-600',
  sent: 'bg-blue-100 text-blue-700',
  accepted: 'bg-green-100 text-green-700',
  declined: 'bg-red-100 text-red-700',
  expired: 'bg-yellow-100 text-yellow-800',
  converted: 'bg-purple-100 text-purple-700',
};

const STATUSES = [['all', 'All'], ['open', 'Open'], ['draft', 'Draft'], ['sent', 'Sent'], ['accepted', 'Accepted'], ['declined', 'Declined'], ['expired', 'Expired'], ['converted', 'Converted']];

export default function QuotationsIndex() {
  const [rows, setRows] = useState<QuotationRow[]>([]);
  const [summary, setSummary] = useState<{ open_value: number; open_count: number; won_value: number; win_rate: number | null } | null>(null);
  const [status, setStatus] = useState('all');
  const [q, setQ] = useState('');
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const t = setTimeout(() => {
      const p = new URLSearchParams({ status });
      if (q) p.set('q', q);
      api(`/api/billing/quotations?${p}`).then(d => { setRows(d.quotations); setSummary(d.summary); }).catch(e => setError(e.message));
    }, q ? 250 : 0);
    return () => clearTimeout(t);
  }, [status, q]);

  return (
    <div className="space-y-4">
      <ErrorBanner message={error} onClose={() => setError(null)} />
      {summary && (
        <div className="grid gap-3 sm:grid-cols-3">
          <StatCard label={`Open quotations (${summary.open_count})`} value={money(summary.open_value)} tone="blue" />
          <StatCard label="Won (accepted or converted)" value={money(summary.won_value)} tone="green" />
          <StatCard label="Win rate" value={summary.win_rate !== null ? `${summary.win_rate}%` : '—'} />
        </div>
      )}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap gap-2">
          <input type="search" value={q} onChange={e => setQ(e.target.value)} placeholder="Number or client" className={`${filterCls} w-48`} aria-label="Search" />
          <select value={status} onChange={e => setStatus(e.target.value)} className={filterCls} aria-label="Status">
            {STATUSES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </div>
        <Link href="/admin/billing/quotations/new" className={btnPrimary}>+ New quotation</Link>
      </div>
      <div className="overflow-x-auto rounded-xl border border-neutral-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-neutral-200 bg-neutral-50 text-left text-xs text-neutral-600">
              {['Number', 'Date', 'Client', 'Product', 'Before GST', 'Total', 'Valid until', 'Status'].map(h => (
                <th key={h} className={`px-4 py-3 font-medium ${['Before GST', 'Total'].includes(h) ? 'text-right' : ''}`}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr><td colSpan={8} className="px-4 py-8 text-center text-neutral-400">No quotations yet.</td></tr>
            ) : rows.map(r => (
              <tr key={r.id} className="border-b border-neutral-100 hover:bg-neutral-50">
                <td className="px-4 py-3"><Link href={`/admin/billing/quotations/${r.id}`} className="font-medium text-brand-600 hover:underline">{r.number}</Link></td>
                <td className="whitespace-nowrap px-4 py-3 text-neutral-500">{formatDate(r.quoteDate)}</td>
                <td className="px-4 py-3">{r.billName}</td>
                <td className="px-4 py-3 text-neutral-600">{r.product?.name}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums">{money(r.taxableTotal)}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums">{money(r.grandTotal)}</td>
                <td className="whitespace-nowrap px-4 py-3 text-neutral-500">{formatDate(r.validUntil)}</td>
                <td className="px-4 py-3">
                  <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ${QUOTE_STATUS[r.displayStatus] ?? ''}`}>{r.displayStatus}</span>
                  {r.invoice && <div className="mt-1 text-xs"><Link href={`/admin/billing/invoices/${r.invoice.id}`} className="text-brand-600 hover:underline">→ {r.invoice.number ?? 'draft invoice'}</Link></div>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

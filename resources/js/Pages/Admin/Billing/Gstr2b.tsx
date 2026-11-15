import { useEffect, useState } from 'react';
import { Card, ErrorBanner, Notice, StatCard } from '@/components/billing/ui';
import apiFetch from '@/lib/api';
import { api, btnPrimary, btnSecondary, filterCls, formatDate, money } from '@/lib/billing';

interface Entry {
  id: number; returnPeriod: string; supplierGstin: string; supplierName: string | null; docType: string; docNumber: string; docDate: string | null;
  docValue: number; taxableValue: number; igst: number; cgst: number; sgst: number; taxTotal: number; reverseCharge: boolean; itcAvailable: boolean;
  purchaseId: number | null; matchStatus: 'matched' | 'mismatch' | 'unmatched';
  purchase?: { id: number; billNumber: string; taxableValue: number; cgst: number; sgst: number; igst: number } | null;
}
interface BooksOnly { id: number; billNumber: string | null; billDate: string; vendorGstin: string; taxableValue: number; taxTotal: number; vendor?: { name: string } | null }
interface Report {
  period: string | null; label?: string; entries: Entry[]; books_only: BooksOnly[]; periods: string[];
  summary: { documents: number; matched: number; mismatch: number; unmatched: number; books_only: number; itc_in_2b: number; itc_matched: number; itc_at_risk: number } | null;
}

const STATUS: Record<string, [string, string]> = {
  matched: ['✓ Matched', 'bg-green-100 text-green-700'],
  mismatch: ['≠ Amounts differ', 'bg-red-100 text-red-700'],
  unmatched: ['Not in your books', 'bg-yellow-100 text-yellow-800'],
};
const periodLabel = (p: string) => new Date(Number(p.slice(2)), Number(p.slice(0, 2)) - 1, 1).toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });

export default function Gstr2bPage() {
  const [report, setReport] = useState<Report | null>(null);
  const [period, setPeriod] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = (p = period) => api<Report>(`/api/billing/gstr2b${p ? `?period=${p}` : ''}`).then(d => { setReport(d); if (d.period) setPeriod(d.period); }).catch(e => setError(e.message));
  useEffect(() => { load(''); }, []);

  const upload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    setBusy(true); setError(null); setNotice(null);
    const body = new FormData();
    body.append('file', file);
    try {
      const res = await apiFetch('/api/billing/gstr2b/import', { method: 'POST', body });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || `Import failed (${res.status})`);
      setReport(data); setPeriod(data.period);
      setNotice(`Imported ${data.result.imported} documents for ${periodLabel(data.period)} and matched them with your purchases.`);
    } catch (err) { setError((err as Error).message); } finally { setBusy(false); }
  };

  const addToBooks = async (entry: Entry) => {
    setBusy(true); setError(null);
    try {
      const d = await api<Report>(`/api/billing/gstr2b/entries/${entry.id}/purchase`, 'POST', {});
      setReport(d);
      setNotice(`Added ${entry.supplierName ?? entry.supplierGstin} bill ${entry.docNumber} to Purchases (marked unpaid). Set its category there.`);
    } catch (err) { setError((err as Error).message); } finally { setBusy(false); }
  };

  const rematch = async () => {
    if (!period) return;
    setBusy(true);
    try { setReport(await api<Report>(`/api/billing/gstr2b/${period}/reconcile`, 'POST')); setNotice('Matched again.'); }
    catch (err) { setError((err as Error).message); } finally { setBusy(false); }
  };

  const s = report?.summary;

  return (
    <div className="space-y-4">
      <ErrorBanner message={error} onClose={() => setError(null)} />
      <Notice message={notice} />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="max-w-3xl text-sm text-neutral-500">
          GSTR-2B is what your suppliers reported on the GST portal. You can only claim ITC for bills that appear there.
          Download it from <em>GST portal → Returns → GSTR-2B → Download → JSON</em> and upload it here; it is matched with your Purchases by supplier GSTIN and bill number.
        </p>
        <div className="flex flex-wrap items-center gap-2">
          {report && report.periods.length > 0 && (
            <select value={period} onChange={e => { setPeriod(e.target.value); load(e.target.value); }} className={filterCls} aria-label="Return period">
              {report.periods.map(p => <option key={p} value={p}>{periodLabel(p)}</option>)}
            </select>
          )}
          {period && <button onClick={rematch} disabled={busy} className={btnSecondary}>Match again</button>}
          <label className={`${btnPrimary} relative cursor-pointer`}>
            {busy ? 'Working…' : 'Upload GSTR-2B JSON'}
            <input type="file" accept=".json,application/json" onChange={upload} className="sr-only" disabled={busy} />
          </label>
        </div>
      </div>

      {!report ? <div className="text-sm text-neutral-400">Loading…</div> : !report.period ? (
        <Card><p className="py-8 text-center text-sm text-neutral-400">No GSTR-2B imported yet.</p></Card>
      ) : (
        <>
          {s && (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <StatCard label={`ITC available in 2B · ${report.label}`} value={money(s.itc_in_2b)} tone="blue" />
              <StatCard label={`Matched with your bills (${s.matched}/${s.documents})`} value={money(s.itc_matched)} tone="green" />
              <StatCard label={`Differences / not in your books`} value={`${s.mismatch} / ${s.unmatched}`} tone={s.mismatch + s.unmatched > 0 ? 'orange' : 'neutral'} />
              <StatCard label={`In your books, not in 2B (${s.books_only})`} value={money(s.itc_at_risk)} tone={s.itc_at_risk > 0 ? 'red' : 'neutral'} />
            </div>
          )}

          <Card title={`Documents in GSTR-2B · ${report.label}`}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-neutral-200 text-left text-xs text-neutral-500">
                    {['Supplier', 'Document', 'Date', 'Before GST', 'GST', 'ITC', 'Status', ''].map(h => (
                      <th key={h} className={`py-2 pr-4 font-medium ${['Before GST', 'GST'].includes(h) ? 'text-right' : ''}`}>{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {report.entries.map(e => (
                    <tr key={e.id} className="border-b border-neutral-100 align-top">
                      <td className="py-2 pr-4"><div>{e.supplierName ?? '—'}</div><div className="font-mono text-xs text-neutral-400">{e.supplierGstin}</div></td>
                      <td className="py-2 pr-4">{e.docNumber}{e.docType !== 'invoice' && <div className="text-xs text-neutral-400">{e.docType.replace('_', ' ')}</div>}</td>
                      <td className="whitespace-nowrap py-2 pr-4 text-neutral-500">{formatDate(e.docDate)}</td>
                      <td className="py-2 pr-4 text-right tabular-nums">{money(e.taxableValue)}</td>
                      <td className="py-2 pr-4 text-right tabular-nums">{money(e.taxTotal)}{e.reverseCharge && <div className="text-xs text-orange-600">reverse charge</div>}</td>
                      <td className="py-2 pr-4 text-xs">{e.itcAvailable ? 'available' : <span className="text-red-600">not available</span>}</td>
                      <td className="py-2 pr-4">
                        <span className={`whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ${STATUS[e.matchStatus][1]}`}>{STATUS[e.matchStatus][0]}</span>
                        {e.matchStatus === 'mismatch' && e.purchase && (
                          <div className="mt-1 text-xs text-neutral-500">Your bill: {money(e.purchase.taxableValue)} + {money(e.purchase.cgst + e.purchase.sgst + e.purchase.igst)} GST</div>
                        )}
                      </td>
                      <td className="py-2 text-right">
                        {e.matchStatus === 'unmatched' && e.docType === 'invoice' && <button onClick={() => addToBooks(e)} disabled={busy} className="whitespace-nowrap text-xs text-brand-600 hover:underline">+ Add to purchases</button>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>

          <Card title="In your books but not in GSTR-2B">
            {report.books_only.length === 0 ? <p className="text-sm text-neutral-400">Every claimable bill from this month appears in GSTR-2B.</p> : (
              <>
                <p className="mb-3 text-sm text-neutral-600">These suppliers haven't reported the bill yet (or used a different number). ITC can't be claimed until it appears in 2B: ask the supplier to file it.</p>
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-neutral-200 text-left text-xs text-neutral-500">
                      {['Supplier', 'Bill', 'Date', 'Before GST', 'GST at risk'].map(h => <th key={h} className={`py-2 pr-4 font-medium ${h.includes('GST') && h !== 'Supplier' ? 'text-right' : ''}`}>{h}</th>)}
                    </tr>
                  </thead>
                  <tbody>
                    {report.books_only.map(p => (
                      <tr key={p.id} className="border-b border-neutral-100">
                        <td className="py-2 pr-4">{p.vendor?.name}<div className="font-mono text-xs text-neutral-400">{p.vendorGstin}</div></td>
                        <td className="py-2 pr-4">{p.billNumber || '—'}</td>
                        <td className="py-2 pr-4 text-neutral-500">{formatDate(p.billDate)}</td>
                        <td className="py-2 pr-4 text-right tabular-nums">{money(p.taxableValue)}</td>
                        <td className="py-2 text-right font-medium tabular-nums text-red-600">{money(p.taxTotal)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </>
            )}
          </Card>
        </>
      )}
    </div>
  );
}

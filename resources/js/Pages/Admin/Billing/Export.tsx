import { useEffect, useMemo, useState } from 'react';
import { Card, ErrorBanner, Field, Notice } from '@/components/billing/ui';
import { api, btnPrimary, btnSecondary, filterCls, inputCls } from '@/lib/billing';
import { periods } from './Reports';

interface Counts { invoices: number; credit_notes: number; cancelled: number; receipts: number; purchases: number; vendor_payments: number; pdfs: number; max_pdfs: number }

const PARTS = [
  { key: 'books', label: 'Excel books', hint: 'Sales register, credit notes, sales lines by SAC, receipts with TDS, purchase register, expenses by category, debtors on the last day.' },
  { key: 'gst', label: 'GST report (Excel)', hint: 'GSTR-3B summary and the GSTR-1 tables in the GST offline-tool layout.' },
  { key: 'tally', label: 'Tally import files (XML)', hint: 'Ledgers and vouchers (Sales, Credit Note, Receipt, Purchase, Payment) for Tally Prime / ERP 9.' },
  { key: 'pdfs', label: 'Invoice PDFs', hint: 'A copy of every invoice and credit note in the period.' },
] as const;

export default function ExportPage() {
  const options = useMemo(periods, []);
  const lastMonth = options[1] ?? options[0];
  const [from, setFrom] = useState(lastMonth.from);
  const [to, setTo] = useState(lastMonth.to);
  const [parts, setParts] = useState<string[]>(['books', 'gst', 'tally']);
  const [counts, setCounts] = useState<Counts | null>(null);
  const [filename, setFilename] = useState('');
  const [company, setCompany] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    if (!from || !to || from > to) return;
    setError(null);
    api<{ counts: Counts; filename: string; tally_company: string | null }>(`/api/billing/export?from=${from}&to=${to}`)
      .then(d => { setCounts(d.counts); setFilename(d.filename); setCompany(c => c || d.tally_company || ''); })
      .catch(e => setError(e.message));
  }, [from, to]);

  const pick = (value: string) => {
    const p = options.find(o => `${o.from}|${o.to}` === value);
    if (p) { setFrom(p.from); setTo(p.to); }
  };
  const toggle = (k: string) => setParts(ps => (ps.includes(k) ? ps.filter(p => p !== k) : [...ps, k]));
  const tooManyPdfs = parts.includes('pdfs') && !!counts && counts.pdfs > counts.max_pdfs;
  const empty = !!counts && counts.invoices + counts.credit_notes + counts.cancelled + counts.receipts + counts.purchases + counts.vendor_payments === 0;
  const href = `/admin/billing/export/download?from=${from}&to=${to}${parts.map(p => `&parts[]=${p}`).join('')}`;

  const saveCompany = async () => {
    try { await api('/api/billing/export/settings', 'PUT', { tally_company: company }); setNotice('Saved. Tally files will be addressed to this company.'); }
    catch (e) { setError((e as Error).message); }
  };

  return (
    <div className="max-w-4xl space-y-4">
      <ErrorBanner message={error} onClose={() => setError(null)} />
      <Notice message={notice} />
      <p className="text-sm text-neutral-500">
        Everything your CA needs for a month, quarter or year in one zip: books in Excel, the GST working, Tally import files and the invoices themselves. Drafts are never included.
      </p>

      <Card title="1. Period">
        <div className="flex flex-wrap items-end gap-3">
          <Field label="Quick pick">
            <select value={`${from}|${to}`} onChange={e => pick(e.target.value)} className={filterCls}>
              {!options.some(o => o.from === from && o.to === to) && <option value={`${from}|${to}`}>Custom</option>}
              {['Month', 'Quarter', 'Year'].map(g => (
                <optgroup key={g} label={g}>{options.filter(o => o.group === g).map(o => <option key={`${o.from}|${o.to}`} value={`${o.from}|${o.to}`}>{o.label}</option>)}</optgroup>
              ))}
            </select>
          </Field>
          <Field label="From"><input type="date" value={from} onChange={e => setFrom(e.target.value)} className={filterCls} /></Field>
          <Field label="To"><input type="date" value={to} min={from} onChange={e => setTo(e.target.value)} className={filterCls} /></Field>
        </div>
        {counts && (
          <div className="mt-4 grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
            {[
              ['Invoices', counts.invoices], ['Credit notes', counts.credit_notes], ['Cancelled', counts.cancelled],
              ['Receipts', counts.receipts], ['Purchase bills', counts.purchases], ['Supplier payments', counts.vendor_payments],
            ].map(([label, n]) => (
              <div key={label as string} className="rounded-lg bg-neutral-50 px-3 py-2"><span className="font-semibold tabular-nums text-neutral-900">{n}</span> <span className="text-neutral-500">{label}</span></div>
            ))}
          </div>
        )}
      </Card>

      <Card title="2. What to include">
        <div className="space-y-3">
          {PARTS.map(p => (
            <label key={p.key} className="flex items-start gap-3 text-sm">
              <input type="checkbox" checked={parts.includes(p.key)} onChange={() => toggle(p.key)} className="mt-1" />
              <span><span className="font-medium text-neutral-900">{p.label}</span><span className="block text-neutral-500">{p.hint}</span></span>
            </label>
          ))}
        </div>
        {tooManyPdfs && <p className="mt-3 text-sm text-red-600">{counts?.pdfs} documents is more than {counts?.max_pdfs} PDFs per download. Choose a shorter period or leave the PDFs out.</p>}
      </Card>

      {parts.includes('tally') && (
        <Card title="Tally">
          <div className="flex flex-wrap items-end gap-2">
            <Field label="Company name in Tally (optional)" hint="Leave blank to import into whichever company is open." className="min-w-[260px] flex-1">
              <input value={company} onChange={e => setCompany(e.target.value)} className={inputCls} placeholder="e.g. LALogix Enterprises" />
            </Field>
            <button onClick={saveCompany} className={btnSecondary}>Save</button>
          </div>
          <ol className="mt-3 list-decimal space-y-1 pl-5 text-sm text-neutral-600">
            <li>In Tally: <em>Gateway of Tally → Import → Masters</em>, choose <code>Tally/1-Masters.xml</code> (creates party, sales, GST, bank and expense ledgers; existing ones are left alone).</li>
            <li>Then <em>Import → Transactions</em>, choose <code>Tally/2-Vouchers.xml</code>. Receipts and payments are linked bill-wise to their invoices and bills.</li>
            <li>If your bank ledger isn't called "Bank Account", rename it in both files first. Steps are also in README.txt inside the zip.</li>
          </ol>
        </Card>
      )}

      <div className="flex flex-wrap items-center gap-3">
        {parts.length === 0 || tooManyPdfs || from > to
          ? <button disabled className={btnPrimary}>Download {filename}</button>
          : <a href={href} className={btnPrimary}>Download {filename}</a>}
        {empty && <span className="text-sm text-neutral-500">Nothing was recorded in this period; the files will be empty.</span>}
      </div>
    </div>
  );
}

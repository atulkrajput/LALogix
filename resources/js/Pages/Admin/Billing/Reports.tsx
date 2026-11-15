import { useEffect, useMemo, useState } from 'react';
import Link from '@/components/ui/Link';
import { Card, ErrorBanner, StatCard } from '@/components/billing/ui';
import { api, btnPrimary, btnSecondary, filterCls, formatDate, money, OUTSIDE_INDIA } from '@/lib/billing';

type Amounts = { taxable: number; igst: number; cgst: number; sgst: number; cess: number };
interface Line { id: number; number: string; date: string; gstin: string | null; name: string; value: number; place_of_supply: string; reverse_charge: boolean; product: string | null; rate: number; taxable: number; igst: number; cgst: number; sgst: number; export_type?: string; ur_type?: string; original_number?: string | null }
interface Report {
  period: { from: string; to: string; label: string };
  seller: { gstin: string | null; legal_name: string };
  b2b: Line[]; b2cl: Line[]; exp: Line[]; cdnr: Line[]; cdnur: Line[];
  b2cs: (Amounts & { place_of_supply: string; supply_type: string; rate: number })[];
  hsn_b2b: (Amounts & { hsn: string; description: string; value: number; rate: number })[];
  hsn_b2c: (Amounts & { hsn: string; description: string; value: number; rate: number })[];
  docs: { nature: string; series: string; from: string; to: string; total: number; cancelled: number; net_issued: number }[];
  gstr3b: {
    taxable_outward: Amounts; zero_rated: Amounts; nil_exempt: Amounts; reverse_charge_outward: Amounts;
    interstate_unregistered: { place_of_supply: string; taxable: number; igst: number }[];
    tax_payable: { igst: number; cgst: number; sgst: number; total: number };
    inward_reverse_charge: Amounts; itc_reverse_charge: Amounts; itc_other: Amounts;
    itc_total: { igst: number; cgst: number; sgst: number; total: number };
    itc_not_in_2b: number;
    net_payable: { igst: number; cgst: number; sgst: number; total: number };
    purchases_count: number;
  };
  by_product: { product: string; invoices: number; credit_notes: number; taxable: number; tax: number; total: number }[];
  counts: { invoices: number; credit_notes: number; cancelled: number };
  warnings: { level: 'error' | 'warning' | 'info'; message: string }[];
  states: Record<string, string>;
}

const pad = (n: number) => String(n).padStart(2, '0');
const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

/** Monthly periods for the last 15 months plus the quarters (QRMP filers) of the current and previous FY. */
export function periods() {
  const now = new Date();
  const out: { label: string; from: string; to: string; group: string }[] = [];
  for (let i = 0; i < 15; i++) {
    const s = new Date(now.getFullYear(), now.getMonth() - i, 1);
    out.push({ group: 'Month', label: s.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' }), from: iso(s), to: iso(new Date(s.getFullYear(), s.getMonth() + 1, 0)) });
  }
  const fy = now.getMonth() >= 3 ? now.getFullYear() : now.getFullYear() - 1;
  for (const y of [fy, fy - 1]) {
    [[4, 'Apr–Jun'], [7, 'Jul–Sep'], [10, 'Oct–Dec'], [13, 'Jan–Mar']].forEach(([m, name], q) => {
      const s = new Date(y, (m as number) - 1, 1);
      if (s > now) return;
      out.push({ group: 'Quarter', label: `Q${q + 1} ${name} ${s.getFullYear()}`, from: iso(s), to: iso(new Date(s.getFullYear(), s.getMonth() + 3, 0)) });
    });
    out.push({ group: 'Year', label: `FY ${y}-${String(y + 1).slice(2)}`, from: `${y}-04-01`, to: `${y + 1}-03-31` });
  }
  return out;
}

const TABS = [
  ['summary', 'Summary & 3B'], ['b2b', 'B2B'], ['b2cl', 'B2CL'], ['b2cs', 'B2CS'], ['exp', 'Exports'],
  ['cdnr', 'CDNR'], ['cdnur', 'CDNUR'], ['hsn', 'HSN'], ['docs', 'Documents'],
] as const;
type Tab = typeof TABS[number][0];


/** Columns whose header names an amount are shown as ₹; other numbers (rates, counts) as plain numbers. */
const isMoney = (h: string) => /value|igst|cgst|sgst|^gst$|^total$/i.test(h);

function Table({ head, rows, empty = 'Nothing to report for this period.' }: { head: string[]; rows: (string | number | null | undefined | JSX.Element)[][]; empty?: string }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-neutral-200 text-left text-xs text-neutral-500">
            {head.map((h, j) => <th key={h} className={`whitespace-nowrap py-2 pr-4 font-medium ${rows.some(r => typeof r[j] === 'number') ? 'text-right' : ''}`}>{h}</th>)}
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr><td colSpan={head.length} className="py-6 text-center text-neutral-400">{empty}</td></tr>
          ) : rows.map((r, i) => (
            <tr key={i} className="border-b border-neutral-100">
              {r.map((c, j) => (
                <td key={j} className={`py-2 pr-4 ${typeof c === 'number' ? 'whitespace-nowrap text-right tabular-nums' : ''}`}>
                  {typeof c === 'number' ? (isMoney(head[j]) ? money(c) : c) : c ?? '—'}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

const invLink = (l: Line) => <Link href={`/admin/billing/invoices/${l.id}`} className="text-brand-600 hover:underline">{l.number}</Link>;

export default function GstReports() {
  // "29 - Karnataka"; filled in once the report (with its state names) has loaded.
  let posLabel = (code: string) => code;
  const options = useMemo(periods, []);
  const [choice, setChoice] = useState(1); // previous month: the one you usually file
  const [custom, setCustom] = useState({ from: options[1].from, to: options[1].to });
  const [useCustom, setUseCustom] = useState(false);
  const [report, setReport] = useState<Report | null>(null);
  const [tab, setTab] = useState<Tab>('summary');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const range = useCustom ? custom : options[choice];

  useEffect(() => {
    if (!range.from || !range.to) return;
    setLoading(true);
    setError(null);
    api<Report>(`/api/billing/reports/gst?from=${range.from}&to=${range.to}`)
      .then(setReport).catch(e => setError(e.message)).finally(() => setLoading(false));
  }, [range.from, range.to]);

  const download = (format: 'gstr1' | 'full') => `/admin/billing/reports/download?from=${range.from}&to=${range.to}&format=${format}`;
  const counts: Partial<Record<Tab, number>> = report ? {
    b2b: new Set(report.b2b.map(l => l.number)).size, b2cl: new Set(report.b2cl.map(l => l.number)).size,
    b2cs: report.b2cs.length, exp: new Set(report.exp.map(l => l.number)).size,
    cdnr: new Set(report.cdnr.map(l => l.number)).size, cdnur: new Set(report.cdnur.map(l => l.number)).size,
    hsn: report.hsn_b2b.length + report.hsn_b2c.length, docs: report.docs.length,
  } : {};

  const g = report?.gstr3b;
  if (report) posLabel = (code: string) => `${code} - ${report.states[code] ?? (code === OUTSIDE_INDIA ? 'Outside India' : '')}`;
  const amountRow = (label: string, a: Amounts) => [label, a.taxable, a.igst, a.cgst, a.sgst];

  return (
    <div className="space-y-4">
      <ErrorBanner message={error} onClose={() => setError(null)} />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-2">
          <select value={useCustom ? 'custom' : choice} onChange={e => { if (e.target.value === 'custom') setUseCustom(true); else { setUseCustom(false); setChoice(Number(e.target.value)); } }} className={filterCls} aria-label="Return period">
            {['Month', 'Quarter', 'Year'].map(group => (
              <optgroup key={group} label={group}>
                {options.map((o, i) => o.group === group && <option key={o.label} value={i}>{o.label}</option>)}
              </optgroup>
            ))}
            <option value="custom">Custom dates…</option>
          </select>
          {useCustom && (
            <>
              <input type="date" value={custom.from} onChange={e => setCustom({ ...custom, from: e.target.value })} className={filterCls} aria-label="From" />
              <span className="text-neutral-400">to</span>
              <input type="date" value={custom.to} onChange={e => setCustom({ ...custom, to: e.target.value })} className={filterCls} aria-label="To" />
            </>
          )}
          {loading && <span className="text-sm text-neutral-400">Loading…</span>}
        </div>
        <div className="flex flex-wrap gap-2">
          <a href={download('full')} className={btnSecondary}>Full report (.xlsx)</a>
          <a href={download('gstr1')} className={btnPrimary}>GSTR-1 for offline tool (.xlsx)</a>
        </div>
      </div>

      {report && (
        <>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <StatCard label="Invoices / credit notes" value={`${report.counts.invoices} / ${report.counts.credit_notes}`} />
            <StatCard label="Taxable value (net)" value={money(g!.taxable_outward.taxable + g!.zero_rated.taxable + g!.nil_exempt.taxable + g!.reverse_charge_outward.taxable)} />
            <StatCard label="IGST" value={money(g!.tax_payable.igst)} tone="blue" />
            <StatCard label="CGST + SGST" value={money(g!.tax_payable.cgst + g!.tax_payable.sgst)} tone="blue" />
            <StatCard label={`Net GST payable (after ${money(g!.itc_total.total)} ITC)`} value={money(g!.net_payable.total)} tone="orange" />
          </div>

          {report.warnings.length > 0 && (
            <ul className="space-y-1 rounded-xl border border-yellow-200 bg-yellow-50 p-4 text-sm">
              {report.warnings.map((w, i) => (
                <li key={i} className={w.level === 'error' ? 'text-red-700' : w.level === 'warning' ? 'text-yellow-800' : 'text-neutral-600'}>
                  {w.level === 'error' ? '⛔' : w.level === 'warning' ? '⚠️' : 'ℹ️'} {w.message}
                </li>
              ))}
            </ul>
          )}

          <div className="flex flex-wrap gap-1 border-b border-neutral-200">
            {TABS.map(([key, label]) => (
              <button key={key} onClick={() => setTab(key)} className={`-mb-px border-b-2 px-3 py-2 text-sm font-medium ${tab === key ? 'border-brand-600 text-brand-700' : 'border-transparent text-neutral-500 hover:text-neutral-800'}`}>
                {label}{counts[key] !== undefined && <span className="ml-1 rounded-full bg-neutral-100 px-1.5 text-xs text-neutral-500">{counts[key]}</span>}
              </button>
            ))}
          </div>

          {tab === 'summary' && g && (
            <div className="space-y-4">
              <Card title={`GSTR-3B · 3.1 Outward supplies · ${report.period.label}`}>
                <Table head={['Nature of supplies', 'Taxable value', 'IGST', 'CGST', 'SGST/UTGST']} rows={[
                  amountRow('(a) Outward taxable supplies (other than zero rated, nil rated and exempted)', g.taxable_outward),
                  amountRow('(b) Outward taxable supplies (zero rated)', g.zero_rated),
                  amountRow('(c) Other outward supplies (nil rated, exempted)', g.nil_exempt),
                  amountRow('Outward supplies on reverse charge (tax paid by the recipient)', g.reverse_charge_outward),
                ]} />
                <p className="mt-3 text-xs text-neutral-500">Net of credit notes.</p>
              </Card>
              <Card title="GSTR-3B · purchases: reverse charge (3.1 d) and input tax credit (Table 4)">
                <Table head={['', 'Taxable value', 'IGST', 'CGST', 'SGST/UTGST']} rows={[
                  amountRow('3.1(d) Inward supplies liable to reverse charge (tax you pay)', g.inward_reverse_charge),
                  amountRow('4A(3) ITC on inward supplies liable to reverse charge', g.itc_reverse_charge),
                  amountRow('4A(5) All other ITC', g.itc_other),
                ]} />
                <div className="mt-4 grid gap-3 sm:grid-cols-4">
                  {(['igst', 'cgst', 'sgst'] as const).map(h => (
                    <div key={h} className="rounded-lg bg-neutral-50 p-3 text-sm">
                      <div className="text-xs uppercase text-neutral-500">{h} {g.net_payable[h] < 0 ? 'credit carried forward' : 'to pay'}</div>
                      <div className={`text-lg font-semibold tabular-nums ${g.net_payable[h] < 0 ? 'text-green-700' : 'text-neutral-900'}`}>{money(g.net_payable[h])}</div>
                      <div className="text-xs text-neutral-400">{money(g.tax_payable[h] + g.inward_reverse_charge[h])} − {money(g.itc_total[h])} ITC</div>
                    </div>
                  ))}
                  <div className="rounded-lg bg-brand-50 p-3 text-sm">
                    <div className="text-xs uppercase text-neutral-500">Net total</div>
                    <div className="text-lg font-semibold tabular-nums text-neutral-900">{money(g.net_payable.total)}</div>
                    <div className="text-xs text-neutral-400">{g.purchases_count} purchase bills in the period</div>
                  </div>
                </div>
                <p className="mt-3 text-xs text-neutral-500">
                  From bills recorded in <Link href="/admin/billing/purchases" className="text-brand-600 hover:underline">Purchases</Link> dated in the period.
                  A negative amount is credit carried forward; the portal applies IGST credit before CGST/SGST, so its split can differ.
                  {g.itc_not_in_2b > 0 && <span className="text-orange-600"> {money(g.itc_not_in_2b)} of this ITC isn't matched with GSTR-2B yet: check <Link href="/admin/billing/gstr2b" className="underline">GSTR-2B Match</Link> before claiming.</span>}
                </p>
              </Card>
              <Card title="GSTR-3B · 3.2 Inter-state supplies to unregistered persons">
                <Table head={['Place of supply', 'Taxable value', 'IGST']} rows={g.interstate_unregistered.map(u => [posLabel(u.place_of_supply), u.taxable, u.igst])} empty="No inter-state supplies to unregistered persons." />
              </Card>
              <Card title="By product / business line">
                <Table head={['Product', 'Invoices', 'Credit notes', 'Taxable value', 'GST', 'Total']} rows={report.by_product.map(p => [p.product, p.invoices, p.credit_notes, p.taxable, p.tax, p.total])} />
              </Card>
            </div>
          )}

          {tab === 'b2b' && <Card title="4A · B2B invoices (one row per invoice and rate)"><Table head={['GSTIN', 'Receiver', 'Invoice', 'Date', 'Invoice value', 'POS', 'RC', 'Rate %', 'Taxable value', 'IGST', 'CGST', 'SGST']} rows={report.b2b.map(l => [l.gstin, l.name, invLink(l), formatDate(l.date), l.value, posLabel(l.place_of_supply), l.reverse_charge ? 'Y' : 'N', l.rate, l.taxable, l.igst, l.cgst, l.sgst])} /></Card>}
          {tab === 'b2cl' && <Card title="5 · B2C large (inter-state, unregistered, invoice value above ₹1 lakh)"><Table head={['Invoice', 'Date', 'Invoice value', 'POS', 'Rate %', 'Taxable value', 'IGST']} rows={report.b2cl.map(l => [invLink(l), formatDate(l.date), l.value, posLabel(l.place_of_supply), l.rate, l.taxable, l.igst])} /></Card>}
          {tab === 'b2cs' && <Card title="7 · B2C small (summary by place of supply and rate, net of credit notes)"><Table head={['Type', 'POS', 'Supply', 'Rate %', 'Taxable value', 'IGST', 'CGST', 'SGST']} rows={report.b2cs.map(l => ['OE', posLabel(l.place_of_supply), l.supply_type, l.rate, l.taxable, l.igst, l.cgst, l.sgst])} /></Card>}
          {tab === 'exp' && <Card title="6A · Exports (WPAY with IGST, WOPAY under LUT)"><Table head={['Type', 'Invoice', 'Date', 'Invoice value', 'Rate %', 'Taxable value', 'IGST']} rows={report.exp.map(l => [l.export_type, invLink(l), formatDate(l.date), l.value, l.rate, l.taxable, l.igst])} /></Card>}
          {tab === 'cdnr' && <Card title="9B · Credit notes to registered persons"><Table head={['GSTIN', 'Receiver', 'Note', 'Date', 'Against invoice', 'Note value', 'Rate %', 'Taxable value', 'IGST', 'CGST', 'SGST']} rows={report.cdnr.map(l => [l.gstin, l.name, invLink(l), formatDate(l.date), l.original_number, l.value, l.rate, l.taxable, l.igst, l.cgst, l.sgst])} /></Card>}
          {tab === 'cdnur' && <Card title="9B · Credit notes to unregistered persons (against B2CL or exports)"><Table head={['UR type', 'Note', 'Date', 'Against invoice', 'Note value', 'Rate %', 'Taxable value', 'IGST']} rows={report.cdnur.map(l => [l.ur_type, invLink(l), formatDate(l.date), l.original_number, l.value, l.rate, l.taxable, l.igst])} /></Card>}
          {tab === 'hsn' && (
            <div className="space-y-4">
              {([['12 · HSN/SAC summary: B2B supplies', report.hsn_b2b], ['12 · HSN/SAC summary: B2C supplies', report.hsn_b2c]] as const).map(([title, rows]) => (
                <Card key={title} title={title}>
                  <Table head={['HSN/SAC', 'Description', 'UQC', 'Rate %', 'Total value', 'Taxable value', 'IGST', 'CGST', 'SGST']} rows={rows.map(h => [h.hsn || '—', h.description, 'NA', h.rate, h.value, h.taxable, h.igst, h.cgst, h.sgst])} />
                </Card>
              ))}
            </div>
          )}
          {tab === 'docs' && <Card title="13 · Documents issued"><Table head={['Nature of document', 'Series', 'From', 'To', 'Total number', 'Cancelled', 'Net issued']} rows={report.docs.map(d => [d.nature, d.series, d.from, d.to, d.total, d.cancelled, d.net_issued])} /></Card>}

          <p className="text-xs text-neutral-400">
            Includes every product's issued invoices and credit notes dated in the period, under GSTIN {report.seller.gstin ?? '(not set)'}.
            Check the figures and the import file against the GST portal before filing.
          </p>
        </>
      )}
    </div>
  );
}

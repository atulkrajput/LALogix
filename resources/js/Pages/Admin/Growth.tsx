import { useEffect, useMemo, useState } from 'react';
import Link from '@/components/ui/Link';
import { Card, ErrorBanner } from '@/components/billing/ui';
import { api, filterCls, money } from '@/lib/billing';

interface ProductInfo { id: number; name: string; kind: string; slot: number }
interface Month { month: string; label: string; by_product: Record<string, number>; revenue: number; tax: number; collected: number; invoices: number; new_customers: number; saas_revenue: number; saas_customers: number }
interface Growth {
  range: { key: string; from: string; to: string; label: string; compare_from: string; compare_to: string };
  products: ProductInfo[];
  months: Month[];
  kpis: {
    month_revenue: number; last_month_revenue_to_date: number; month_change_pct: number | null;
    range_revenue: number; prev_range_revenue: number; range_change_pct: number | null; range_tax: number; month_tax: number;
    month_collected: number; outstanding: number; overdue: number; customers: number; new_customers_month: number;
    leads_month: number; leads_last_month: number;
  };
  by_product: { product_id: number; name: string; kind: string; revenue: number; share_pct: number; invoices: number; customers: number; prev_revenue: number; change_pct: number | null }[];
  top_clients: { client_id: number; name: string; revenue: number; invoices: number; outstanding: number }[];
  aging: { not_due: number; d1_30: number; d31_60: number; d61_90: number; d90_plus: number };
}

// Categorical slots in fixed order (reference palette, light mode). Colour follows the product's
// position in the product list, never its rank, so filters don't repaint products.
const SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
const OTHER = '#8a8984';
const colorFor = (slot: number) => (slot >= 1 && slot <= SERIES.length ? SERIES[slot - 1] : OTHER);

const RANGES = [['12m', 'Last 12 months'], ['6m', 'Last 6 months'], ['fy', 'This financial year'], ['prev_fy', 'Last financial year']] as const;

/** ₹ in Indian short form: K (thousand), L (lakh), Cr (crore). */
function compact(v: number) {
  const a = Math.abs(v);
  const s = v < 0 ? '−' : '';
  if (a >= 1e7) return `${s}₹${(a / 1e7).toFixed(a >= 1e8 ? 0 : 1)}Cr`;
  if (a >= 1e5) return `${s}₹${(a / 1e5).toFixed(a >= 1e6 ? 0 : 1)}L`;
  if (a >= 1e3) return `${s}₹${(a / 1e3).toFixed(a >= 1e4 ? 0 : 1)}K`;
  return `${s}₹${Math.round(a)}`;
}

/** "Nice" axis maximum and ticks. */
function niceTicks(max: number, count = 4) {
  if (max <= 0) return [0];
  const raw = max / count;
  const mag = 10 ** Math.floor(Math.log10(raw));
  const step = [1, 2, 2.5, 5, 10].map(m => m * mag).find(s => s >= raw) ?? raw;
  const ticks: number[] = [];
  for (let t = 0; t <= max + step * 0.001; t += step) ticks.push(t);
  if (ticks[ticks.length - 1] < max) ticks.push(ticks[ticks.length - 1] + step);
  return ticks;
}

function Change({ pct, suffix = 'vs before' }: { pct: number | null; suffix?: string }) {
  if (pct === null) return <span className="text-neutral-400">no earlier data</span>;
  const up = pct >= 0;
  return (
    <span className={up ? 'text-green-700' : 'text-red-600'}>
      {up ? '▲' : '▼'} {Math.abs(pct)}% <span className="text-neutral-400">{suffix}</span>
    </span>
  );
}

function Tile({ label, value, sub, href }: { label: string; value: string; sub?: React.ReactNode; href?: string }) {
  const body = (
    <div className="h-full rounded-xl border border-neutral-200 bg-white p-4 transition-colors hover:border-neutral-300">
      <div className="text-xs font-medium text-neutral-500">{label}</div>
      <div className="mt-1 text-xl font-semibold tabular-nums text-neutral-900">{value}</div>
      {sub && <div className="mt-1 text-xs">{sub}</div>}
    </div>
  );
  return href ? <Link href={href} className="block h-full">{body}</Link> : body;
}

/** Stacked monthly revenue bars, one segment per product, with hover tooltip. */
function RevenueChart({ months, products }: { months: Month[]; products: ProductInfo[] }) {
  const [hover, setHover] = useState<number | null>(null);
  const W = 760, H = 260, L = 56, R = 8, T = 12, B = 28;
  const ticks = niceTicks(Math.max(0, ...months.map(m => Object.values(m.by_product).filter(v => v > 0).reduce((s, v) => s + v, 0))));
  const max = ticks[ticks.length - 1] || 1;
  const band = (W - L - R) / Math.max(1, months.length);
  const barW = Math.min(36, band * 0.62);
  const y = (v: number) => T + (H - T - B) * (1 - v / max);
  const order = products.map(p => p.id);
  const labelEvery = months.length > 8 ? 2 : 1;

  return (
    <div className="relative">
      <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" role="img" aria-label="Monthly revenue by product">
        {ticks.map(t => (
          <g key={t}>
            <line x1={L} x2={W - R} y1={y(t)} y2={y(t)} stroke="#e7e6e2" strokeWidth={1} />
            <text x={L - 8} y={y(t)} dy="0.32em" textAnchor="end" fontSize={11} fill="#8a8984">{compact(t)}</text>
          </g>
        ))}
        {months.map((m, i) => {
          const cx = L + band * i + band / 2;
          let acc = 0;
          const segs = order.map(id => ({ id, v: Math.max(0, m.by_product[id] ?? 0) })).filter(s => s.v > 0);
          return (
            <g key={m.month}>
              {segs.map((s, j) => {
                const top = y(acc + s.v), bottom = y(acc);
                acc += s.v;
                const h = Math.max(0, bottom - top - (j > 0 ? 2 : 0)); // 2px surface gap between segments
                const isTop = j === segs.length - 1;
                const r = isTop ? Math.min(4, h / 2, barW / 2) : 0;
                const x = cx - barW / 2, yy = top;
                const path = r > 0
                  ? `M${x},${yy + h} V${yy + r} Q${x},${yy} ${x + r},${yy} H${x + barW - r} Q${x + barW},${yy} ${x + barW},${yy + r} V${yy + h} Z`
                  : `M${x},${yy + h} V${yy} H${x + barW} V${yy + h} Z`;
                const p = products.find(pp => pp.id === s.id);
                return <path key={s.id} d={path} fill={colorFor(p?.slot ?? 0)} opacity={hover === null || hover === i ? 1 : 0.45} />;
              })}
              {i % labelEvery === 0 && <text x={cx} y={H - 8} textAnchor="middle" fontSize={11} fill="#8a8984">{m.label}</text>}
              {/* Hit target: the whole column, bigger than the bars */}
              <rect x={L + band * i} y={T} width={band} height={H - T - B} fill="transparent"
                onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)} onFocus={() => setHover(i)} onBlur={() => setHover(null)}
                tabIndex={0} aria-label={`${m.label}: ${money(m.revenue)}`} />
            </g>
          );
        })}
        <line x1={L} x2={W - R} y1={y(0)} y2={y(0)} stroke="#c9c8c2" strokeWidth={1} />
      </svg>
      {hover !== null && months[hover] && (
        <div className="pointer-events-none absolute top-2 z-10 w-56 rounded-lg border border-neutral-200 bg-white p-3 text-xs shadow-lg"
          style={{ left: `clamp(0px, calc(${((L + (W - L - R) / months.length * (hover + 0.5)) / W) * 100}% - 7rem), calc(100% - 14rem))` }}>
          <div className="mb-1 font-semibold text-neutral-900">{months[hover].label}</div>
          {products.filter(p => months[hover].by_product[p.id]).map(p => (
            <div key={p.id} className="flex items-center justify-between gap-3">
              <span className="flex items-center gap-1.5 text-neutral-600"><span className="inline-block h-2 w-2 rounded-sm" style={{ background: colorFor(p.slot) }} />{p.name}</span>
              <span className="tabular-nums text-neutral-900">{money(months[hover].by_product[p.id])}</span>
            </div>
          ))}
          <div className="mt-1 flex justify-between border-t border-neutral-100 pt-1 font-medium"><span>Revenue</span><span className="tabular-nums">{money(months[hover].revenue)}</span></div>
          <div className="flex justify-between text-neutral-500"><span>Collected</span><span className="tabular-nums">{money(months[hover].collected)}</span></div>
          <div className="flex justify-between text-neutral-500"><span>New customers</span><span className="tabular-nums">{months[hover].new_customers}</span></div>
        </div>
      )}
    </div>
  );
}

/** Single-series bars (no legend: the card title names it). */
function SimpleBars({ months, value, format, label }: { months: Month[]; value: (m: Month) => number; format: (v: number) => string; label: string }) {
  const [hover, setHover] = useState<number | null>(null);
  const W = 360, H = 150, L = 8, T = 16, B = 22;
  const max = Math.max(1, ...months.map(value));
  const band = (W - L * 2) / Math.max(1, months.length);
  const barW = Math.min(20, band * 0.6);
  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" role="img" aria-label={label}>
      {months.map((m, i) => {
        const v = value(m);
        const h = (H - T - B) * (v / max);
        const x = L + band * i + (band - barW) / 2;
        const r = Math.min(4, h / 2);
        const yy = H - B - h;
        return (
          <g key={m.month} onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)}>
            <rect x={L + band * i} y={T} width={band} height={H - T - B} fill="transparent" />
            {h > 0 && <path d={`M${x},${H - B} V${yy + r} Q${x},${yy} ${x + r},${yy} H${x + barW - r} Q${x + barW},${yy} ${x + barW},${yy + r} V${H - B} Z`} fill={SERIES[0]} opacity={hover === null || hover === i ? 1 : 0.45} />}
            {(hover === i || i === months.length - 1) && <text x={x + barW / 2} y={yy - 4} textAnchor="middle" fontSize={11} fill="#52514e">{format(v)}</text>}
            {(i === 0 || i === months.length - 1 || hover === i) && <text x={x + barW / 2} y={H - 6} textAnchor="middle" fontSize={10} fill="#8a8984">{m.label}</text>}
          </g>
        );
      })}
      <line x1={L} x2={W - L} y1={H - B} y2={H - B} stroke="#c9c8c2" strokeWidth={1} />
    </svg>
  );
}

export default function GrowthPage() {
  const [range, setRange] = useState('12m');
  const [data, setData] = useState<Growth | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [showTable, setShowTable] = useState(false);

  useEffect(() => {
    setLoading(true);
    api<Growth>(`/api/billing/growth?range=${range}`).then(setData).catch(e => setError(e.message)).finally(() => setLoading(false));
  }, [range]);

  const productsWithData = useMemo(() => {
    if (!data) return [];
    const ids = new Set(data.months.flatMap(m => Object.keys(m.by_product).map(Number)));
    return data.products.filter(p => ids.has(p.id));
  }, [data]);
  const hasSaas = !!data?.products.some(p => p.kind === 'saas') && !!data?.months.some(m => m.saas_customers > 0);

  if (!data) return <div className="text-sm text-neutral-400">{error ?? 'Loading…'}</div>;
  const k = data.kpis;
  const agingRows = [
    ['Not yet due', data.aging.not_due], ['1–30 days late', data.aging.d1_30], ['31–60 days late', data.aging.d31_60],
    ['61–90 days late', data.aging.d61_90], ['Over 90 days late', data.aging.d90_plus],
  ] as const;
  const agingMax = Math.max(1, ...agingRows.map(([, v]) => v));
  const empty = data.months.every(m => m.revenue === 0);

  return (
    <div className="space-y-5">
      <ErrorBanner message={error} onClose={() => setError(null)} />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-neutral-500">All business lines under one GSTIN: invoices issued here and synced from your products. Revenue is before GST, net of credit notes.</p>
        <div className="flex items-center gap-2">
          {loading && <span className="text-xs text-neutral-400">Updating…</span>}
          <select value={range} onChange={e => setRange(e.target.value)} className={filterCls} aria-label="Period">
            {RANGES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </div>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Tile label="Revenue this month" value={money(k.month_revenue)} sub={<Change pct={k.month_change_pct} suffix="vs same days last month" />} />
        <Tile label={`Revenue · ${data.range.label}`} value={money(k.range_revenue)} sub={<Change pct={k.range_change_pct} suffix={data.range.key.includes('fy') ? 'vs a year earlier' : 'vs previous period'} />} />
        <Tile label="Collected this month" value={money(k.month_collected)} sub={<span className="text-neutral-500">incl. TDS deducted by clients</span>} href="/admin/billing/invoices" />
        <Tile label="Money owed to you" value={money(k.outstanding)} href="/admin/billing/invoices"
          sub={k.overdue > 0 ? <span className="text-red-600">⚠ {money(k.overdue)} overdue</span> : <span className="text-green-700">✓ nothing overdue</span>} />
        <Tile label="Output GST this month" value={money(k.month_tax)} sub={<span className="text-neutral-500">{money(k.range_tax)} in the period, before ITC</span>} href="/admin/billing/reports" />
        <Tile label={`Paying customers · ${data.range.label}`} value={String(k.customers)} sub={<span className="text-neutral-500">{k.new_customers_month} new this month</span>} href="/admin/billing/clients" />
        <Tile label="New leads this month" value={String(k.leads_month)} sub={<span className="text-neutral-500">{k.leads_last_month} last month</span>} href="/admin/leads" />
        <Tile label="Invoices this month" value={String(data.months[data.months.length - 1]?.invoices ?? 0)} sub={<span className="text-neutral-500">across all products</span>} href="/admin/billing/invoices" />
      </div>

      <Card title="Monthly revenue by product" actions={
        <button onClick={() => setShowTable(s => !s)} className="text-xs text-brand-600 hover:underline">{showTable ? 'Show chart' : 'Show as table'}</button>
      }>
        {empty ? (
          <p className="py-10 text-center text-sm text-neutral-400">No issued invoices in this period yet.</p>
        ) : showTable ? (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-neutral-200 text-left text-xs text-neutral-500">
                  <th className="py-2 pr-4 font-medium">Month</th>
                  {productsWithData.map(p => <th key={p.id} className="py-2 pr-4 text-right font-medium">{p.name}</th>)}
                  <th className="py-2 pr-4 text-right font-medium">Revenue</th>
                  <th className="py-2 pr-4 text-right font-medium">Collected</th>
                  <th className="py-2 text-right font-medium">New customers</th>
                </tr>
              </thead>
              <tbody>
                {data.months.map(m => (
                  <tr key={m.month} className="border-b border-neutral-100">
                    <td className="py-2 pr-4">{m.label}</td>
                    {productsWithData.map(p => <td key={p.id} className="py-2 pr-4 text-right tabular-nums">{m.by_product[p.id] ? money(m.by_product[p.id]) : '—'}</td>)}
                    <td className="py-2 pr-4 text-right font-medium tabular-nums">{money(m.revenue)}</td>
                    <td className="py-2 pr-4 text-right tabular-nums text-neutral-600">{money(m.collected)}</td>
                    <td className="py-2 text-right tabular-nums text-neutral-600">{m.new_customers}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <>
            <ul className="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-neutral-600" aria-label="Legend">
              {productsWithData.map(p => (
                <li key={p.id} className="flex items-center gap-1.5"><span className="inline-block h-2.5 w-2.5 rounded-sm" style={{ background: colorFor(p.slot) }} />{p.name}</li>
              ))}
            </ul>
            <RevenueChart months={data.months} products={productsWithData} />
          </>
        )}
      </Card>

      <div className="grid gap-5 lg:grid-cols-3">
        <Card title={`By product · ${data.range.label}`} className="lg:col-span-2">
          {data.by_product.length === 0 ? <p className="text-sm text-neutral-400">No revenue in this period.</p> : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-neutral-200 text-left text-xs text-neutral-500">
                    {['Product', 'Revenue', 'Share', 'Change', 'Invoices', 'Customers'].map(h => <th key={h} className={`py-2 pr-4 font-medium ${h === 'Product' ? '' : 'text-right'}`}>{h}</th>)}
                  </tr>
                </thead>
                <tbody>
                  {data.by_product.map(r => {
                    const p = data.products.find(pp => pp.id === r.product_id);
                    return (
                      <tr key={r.product_id} className="border-b border-neutral-100">
                        <td className="py-2 pr-4"><span className="mr-2 inline-block h-2.5 w-2.5 rounded-sm align-middle" style={{ background: colorFor(p?.slot ?? 0) }} />{r.name}</td>
                        <td className="py-2 pr-4 text-right font-medium tabular-nums">{money(r.revenue)}</td>
                        <td className="py-2 pr-4 text-right tabular-nums text-neutral-600">{r.share_pct}%</td>
                        <td className="py-2 pr-4 text-right text-xs"><Change pct={r.change_pct} suffix="" /></td>
                        <td className="py-2 pr-4 text-right tabular-nums text-neutral-600">{r.invoices}</td>
                        <td className="py-2 text-right tabular-nums text-neutral-600">{r.customers}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </Card>

        <Card title="Money owed, by how late">
          <ul className="space-y-2.5 text-sm">
            {agingRows.map(([label, v]) => (
              <li key={label}>
                <div className="flex justify-between text-xs"><span className="text-neutral-600">{label}</span><span className="tabular-nums text-neutral-900">{money(v)}</span></div>
                <div className="mt-1 h-2 rounded-full bg-neutral-100">
                  {v > 0 && <div className="h-2 rounded-full" style={{ width: `${Math.max(2, (v / agingMax) * 100)}%`, background: SERIES[0] }} />}
                </div>
              </li>
            ))}
          </ul>
          <p className="mt-3 text-xs text-neutral-400">Unpaid balances of issued invoices, by days past the due date.</p>
        </Card>
      </div>

      <div className="grid gap-5 lg:grid-cols-3">
        <Card title={`Top clients · ${data.range.label}`} className="lg:col-span-2">
          {data.top_clients.length === 0 ? <p className="text-sm text-neutral-400">No clients billed in this period.</p> : (
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-neutral-200 text-left text-xs text-neutral-500">
                  {['Client', 'Revenue', 'Invoices', 'Outstanding'].map(h => <th key={h} className={`py-2 pr-4 font-medium ${h === 'Client' ? '' : 'text-right'}`}>{h}</th>)}
                </tr>
              </thead>
              <tbody>
                {data.top_clients.map(c => (
                  <tr key={c.client_id} className="border-b border-neutral-100">
                    <td className="py-2 pr-4"><Link href={`/admin/billing/clients/${c.client_id}`} className="text-brand-600 hover:underline">{c.name}</Link></td>
                    <td className="py-2 pr-4 text-right font-medium tabular-nums">{money(c.revenue)}</td>
                    <td className="py-2 pr-4 text-right tabular-nums text-neutral-600">{c.invoices}</td>
                    <td className={`py-2 text-right tabular-nums ${c.outstanding > 0 ? 'text-orange-600' : 'text-neutral-400'}`}>{c.outstanding > 0 ? money(c.outstanding) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>

        <Card title={hasSaas ? 'Paying SaaS customers per month' : 'New customers per month'}>
          <SimpleBars months={data.months} value={m => (hasSaas ? m.saas_customers : m.new_customers)} format={v => String(v)}
            label={hasSaas ? 'Paying SaaS customers per month' : 'New customers per month'} />
          <p className="mt-2 text-xs text-neutral-400">
            {hasSaas ? 'Distinct customers invoiced by your SaaS products each month.' : 'Clients billed for the first time each month.'}
          </p>
        </Card>
      </div>
    </div>
  );
}

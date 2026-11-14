import { useEffect, useMemo, useState } from 'react';
import { Card, ErrorBanner, Field, Notice } from '@/components/billing/ui';
import apiFetch from '@/lib/api';
import { api, btnPrimary, btnSecondary, filterCls, inputCls, money } from '@/lib/billing';

interface CampaignRow { key: string; name: string; platform: string | null; spend: number; impressions: number; clicks: number; platform_leads: number; leads: number; customers: number; revenue: number; ctr: number | null; cost_per_lead: number | null; roas: number | null }
interface Report {
  range: { from: string; to: string };
  totals: { spend: number; impressions: number; clicks: number; leads: number; campaign_leads: number; customers: number; revenue: number; cost_per_lead: number | null; roas: number | null };
  campaigns: CampaignRow[];
  sources: { source: string; leads: number; customers: number; revenue: number }[];
  meta: { ad_account_id: string | null; has_token: boolean; synced_at: string | null };
}

const pad = (n: number) => String(n).padStart(2, '0');
const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
function presets() {
  const t = new Date();
  const fy = t.getMonth() >= 3 ? t.getFullYear() : t.getFullYear() - 1;
  const daysAgo = (n: number) => { const d = new Date(t); d.setDate(d.getDate() - n); return d; };
  return [
    { label: 'Last 30 days', from: iso(daysAgo(29)), to: iso(t) },
    { label: 'This month', from: iso(new Date(t.getFullYear(), t.getMonth(), 1)), to: iso(t) },
    { label: 'Last month', from: iso(new Date(t.getFullYear(), t.getMonth() - 1, 1)), to: iso(new Date(t.getFullYear(), t.getMonth(), 0)) },
    { label: 'Last 90 days', from: iso(daysAgo(89)), to: iso(t) },
    { label: `FY ${fy}-${String(fy + 1).slice(2)}`, from: `${fy}-04-01`, to: iso(t) },
  ];
}

const PLATFORM: Record<string, string> = { meta: 'Facebook / Instagram', google: 'Google Ads', other: 'Other' };
const num = new Intl.NumberFormat('en-IN');

function Tile({ label, value, sub }: { label: string; value: string; sub?: React.ReactNode }) {
  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-4">
      <div className="text-xs font-medium text-neutral-500">{label}</div>
      <div className="mt-1 text-xl font-semibold tabular-nums text-neutral-900">{value}</div>
      {sub && <div className="mt-1 text-xs text-neutral-500">{sub}</div>}
    </div>
  );
}

function Roas({ v }: { v: number | null }) {
  if (v === null) return <span className="text-neutral-300">—</span>;
  return <span className={v >= 1 ? 'font-medium text-green-700' : 'text-red-600'}>{v.toFixed(2)}×</span>;
}

export default function MarketingPage({ siteUrl }: { siteUrl: string }) {
  const options = useMemo(presets, []);
  const [choice, setChoice] = useState(0);
  const [report, setReport] = useState<Report | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const load = () => {
    const r = options[choice];
    setLoading(true);
    return api<Report>(`/api/marketing?from=${r.from}&to=${r.to}`).then(setReport).catch(e => setError(e.message)).finally(() => setLoading(false));
  };
  useEffect(() => { load(); }, [choice]);

  if (!report) return <div className="text-sm text-neutral-400">{error ?? 'Loading…'}</div>;
  const t = report.totals;
  const noSpend = t.spend === 0;

  return (
    <div className="space-y-5">
      <ErrorBanner message={error} onClose={() => setError(null)} />
      <Notice message={notice} />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="max-w-3xl text-sm text-neutral-500">
          Ad spend → website leads → customers → revenue. Leads are matched to campaigns by the <code>utm_campaign</code> tag on your ad links;
          revenue counts invoices (before GST) of customers created from those leads.
        </p>
        <div className="flex items-center gap-2">
          {loading && <span className="text-xs text-neutral-400">Updating…</span>}
          <select value={choice} onChange={e => setChoice(Number(e.target.value))} className={filterCls} aria-label="Period">
            {options.map((o, i) => <option key={o.label} value={i}>{o.label}</option>)}
          </select>
        </div>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
        <Tile label="Ad spend" value={money(t.spend)} sub={t.clicks ? `${num.format(t.clicks)} clicks · ${num.format(t.impressions)} impressions` : 'no spend recorded'} />
        <Tile label="Leads" value={String(t.leads)} sub={`${t.campaign_leads} from tagged ads / links`} />
        <Tile label="Cost per lead" value={t.cost_per_lead !== null ? money(t.cost_per_lead) : '—'} sub="spend ÷ tagged leads" />
        <Tile label="Customers from campaigns" value={String(t.customers)} sub="billing clients from tagged leads" />
        <Tile label="Revenue from campaigns" value={money(t.revenue)} sub="their invoices, before GST" />
        <Tile label="Return on ad spend" value={t.roas !== null ? `${t.roas.toFixed(2)}×` : '—'} sub={t.roas !== null ? (t.roas >= 1 ? '✓ campaigns paid for themselves' : '⚠ revenue below spend so far') : 'needs spend data'} />
      </div>

      <Card title="Campaigns">
        {report.campaigns.length === 0 ? (
          <p className="py-6 text-center text-sm text-neutral-400">
            No campaign data for this period. Connect Meta or import ad spend below, and tag your ad links with <code>utm_campaign</code>.
          </p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-neutral-200 text-left text-xs text-neutral-500">
                  {['Campaign', 'Spend', 'Clicks', 'CTR', 'Leads', 'Cost / lead', 'Customers', 'Revenue', 'ROAS'].map(h => (
                    <th key={h} className={`whitespace-nowrap py-2 pr-4 font-medium ${h === 'Campaign' ? '' : 'text-right'}`}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {report.campaigns.map(c => (
                  <tr key={c.key} className="border-b border-neutral-100">
                    <td className="py-2 pr-4">
                      <div className="font-medium text-neutral-900">{c.name}</div>
                      <div className="text-xs text-neutral-400">{c.platform ? PLATFORM[c.platform] ?? c.platform : 'tagged link (no spend)'}{c.platform_leads ? ` · ${c.platform_leads} leads per platform` : ''}</div>
                    </td>
                    <td className="py-2 pr-4 text-right tabular-nums">{c.spend ? money(c.spend) : '—'}</td>
                    <td className="py-2 pr-4 text-right tabular-nums text-neutral-600">{c.clicks ? num.format(c.clicks) : '—'}</td>
                    <td className="py-2 pr-4 text-right tabular-nums text-neutral-600">{c.ctr !== null ? `${c.ctr}%` : '—'}</td>
                    <td className="py-2 pr-4 text-right tabular-nums">{c.leads}</td>
                    <td className="py-2 pr-4 text-right tabular-nums text-neutral-600">{c.cost_per_lead !== null ? money(c.cost_per_lead) : '—'}</td>
                    <td className="py-2 pr-4 text-right tabular-nums">{c.customers}</td>
                    <td className="py-2 pr-4 text-right tabular-nums">{c.revenue ? money(c.revenue) : '—'}</td>
                    <td className="py-2 text-right tabular-nums"><Roas v={c.roas} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <p className="mt-3 text-xs text-neutral-400">
          "Leads per platform" is what the ad platform counted; "Leads" are website enquiries that arrived with that campaign's tag.
          A lead becomes a customer when you click <strong>Billing client</strong> on it (Leads → open a lead).
        </p>
      </Card>

      <div className="grid gap-5 lg:grid-cols-2">
        <Card title="Where leads came from">
          {report.sources.length === 0 ? <p className="text-sm text-neutral-400">No leads in this period.</p> : (
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-neutral-200 text-left text-xs text-neutral-500">
                  {['Source', 'Leads', 'Customers', 'Revenue'].map(h => <th key={h} className={`py-2 pr-4 font-medium ${h === 'Source' ? '' : 'text-right'}`}>{h}</th>)}
                </tr>
              </thead>
              <tbody>
                {report.sources.map(s => (
                  <tr key={s.source} className="border-b border-neutral-100">
                    <td className="py-2 pr-4 capitalize">{s.source}</td>
                    <td className="py-2 pr-4 text-right tabular-nums">{s.leads}</td>
                    <td className="py-2 pr-4 text-right tabular-nums">{s.customers}</td>
                    <td className="py-2 text-right tabular-nums">{s.revenue ? money(s.revenue) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
        <LinkBuilder siteUrl={siteUrl} />
      </div>

      <div className="grid gap-5 lg:grid-cols-2">
        <MetaConnection meta={report.meta} onChanged={load} setNotice={setNotice} />
        <SpendImport onImported={(msg) => { setNotice(msg); load(); }} />
      </div>
      {noSpend && report.campaigns.length > 0 && <p className="text-xs text-neutral-400">Spend columns are empty until ad spend is synced or imported.</p>}
    </div>
  );
}

/** Builds a tagged landing URL, and the URL-parameter string to paste into Meta Ads. */
function LinkBuilder({ siteUrl }: { siteUrl: string }) {
  const [f, setF] = useState({ page: `${siteUrl}/book-demo`, source: 'facebook', medium: 'paid', campaign: '', content: '' });
  const [copied, setCopied] = useState('');
  const params = new URLSearchParams();
  if (f.source) params.set('utm_source', f.source);
  if (f.medium) params.set('utm_medium', f.medium);
  if (f.campaign) params.set('utm_campaign', f.campaign);
  if (f.content) params.set('utm_content', f.content);
  const url = `${f.page}${f.page.includes('?') ? '&' : '?'}${params.toString()}`;
  const metaParams = 'utm_source=facebook&utm_medium=paid&utm_campaign={{campaign.name}}&utm_content={{ad.name}}';
  const copy = (text: string, what: string) => navigator.clipboard?.writeText(text).then(() => { setCopied(what); setTimeout(() => setCopied(''), 1500); }).catch(() => {});
  const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement>) => setF({ ...f, [k]: e.target.value });

  return (
    <Card title="Tag your ad links">
      <div className="space-y-3 text-sm">
        <div>
          <div className="mb-1 text-xs font-medium text-neutral-600">Facebook / Instagram ads (recommended)</div>
          <p className="mb-2 text-xs text-neutral-500">In Ads Manager, open each ad → <em>Tracking</em> → <em>URL parameters</em>, and paste this once. Meta fills in the campaign and ad names automatically.</p>
          <div className="flex items-center gap-2">
            <code className="flex-1 break-all rounded bg-neutral-100 px-2 py-1 font-mono text-xs">{metaParams}</code>
            <button onClick={() => copy(metaParams, 'meta')} className={btnSecondary}>{copied === 'meta' ? 'Copied' : 'Copy'}</button>
          </div>
        </div>
        <div className="border-t border-neutral-100 pt-3">
          <div className="mb-2 text-xs font-medium text-neutral-600">Any other link (Google Ads, WhatsApp, email, posters…)</div>
          <div className="grid gap-2 sm:grid-cols-2">
            <Field label="Page" className="sm:col-span-2"><input value={f.page} onChange={set('page')} className={inputCls} /></Field>
            <Field label="Source"><input value={f.source} onChange={set('source')} className={inputCls} placeholder="google, whatsapp, newsletter" /></Field>
            <Field label="Medium"><input value={f.medium} onChange={set('medium')} className={inputCls} placeholder="paid, social, email" /></Field>
            <Field label="Campaign *" hint="Use the exact campaign name from the ad platform"><input value={f.campaign} onChange={set('campaign')} className={inputCls} placeholder="School ERP - Nov" /></Field>
            <Field label="Ad / content"><input value={f.content} onChange={set('content')} className={inputCls} /></Field>
          </div>
          <div className="mt-2 flex items-center gap-2">
            <code className="flex-1 break-all rounded bg-neutral-100 px-2 py-1 font-mono text-xs">{url}</code>
            <button onClick={() => copy(url, 'url')} disabled={!f.campaign} className={btnSecondary}>{copied === 'url' ? 'Copied' : 'Copy'}</button>
          </div>
        </div>
      </div>
    </Card>
  );
}

function MetaConnection({ meta, onChanged, setNotice }: { meta: Report['meta']; onChanged: () => void; setNotice: (m: string) => void }) {
  const [account, setAccount] = useState(meta.ad_account_id ?? '');
  const [token, setToken] = useState('');
  const [days, setDays] = useState(30);
  const [busy, setBusy] = useState<'' | 'save' | 'sync'>('');
  const [error, setError] = useState<string | null>(null);
  const ready = !!meta.ad_account_id && meta.has_token;
  const dirty = account !== (meta.ad_account_id ?? '') || token !== '';

  const save = async () => {
    setBusy('save'); setError(null);
    try {
      await api('/api/marketing/meta', 'PUT', { ad_account_id: account || null, access_token: token || null });
      setToken('');
      onChanged();
    } catch (e) { setError((e as Error).message); } finally { setBusy(''); }
  };
  const sync = async () => {
    setBusy('sync'); setError(null);
    try {
      const d = await api<{ result: { days: number; rows: number; spend: number } }>('/api/marketing/meta/sync', 'POST', { days });
      setNotice(`Meta synced: ${d.result.rows} campaign-days, ${money(d.result.spend)} spend.`);
      onChanged();
    } catch (e) { setError((e as Error).message); } finally { setBusy(''); }
  };

  return (
    <Card title="Facebook / Instagram ads (Meta)">
      <div className="space-y-3 text-sm">
        <p className="text-xs text-neutral-500">
          Pulls daily spend, clicks and leads per campaign from the Meta Marketing API.
          Use a <strong>system user token</strong> with <code>ads_read</code> (Business settings → Users → System users → Generate token), and your ad account id from Ads Manager.
        </p>
        <div className="grid gap-2 sm:grid-cols-2">
          <Field label="Ad account id"><input value={account} onChange={e => setAccount(e.target.value.trim())} placeholder="act_1234567890" className={`${inputCls} font-mono`} /></Field>
          <Field label="Access token"><input type="password" value={token} onChange={e => setToken(e.target.value)} autoComplete="new-password"
            placeholder={meta.has_token ? 'Token saved (type to replace)' : 'EAAG…'} className={inputCls} /></Field>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <button onClick={save} disabled={!!busy || !dirty} className={btnSecondary}>{busy === 'save' ? 'Saving…' : 'Save'}</button>
          <select value={days} onChange={e => setDays(Number(e.target.value))} className={filterCls} aria-label="Days to sync">
            {[7, 30, 90, 365].map(d => <option key={d} value={d}>Last {d} days</option>)}
          </select>
          <button onClick={sync} disabled={!!busy || !ready || dirty} className={btnPrimary}>{busy === 'sync' ? 'Syncing…' : 'Sync spend now'}</button>
          <span className="text-xs text-neutral-400">{meta.synced_at ? `Last synced ${new Date(meta.synced_at).toLocaleString('en-IN')}` : 'Never synced'}</span>
        </div>
        {error && <div className="text-sm text-red-600">{error}</div>}
      </div>
    </Card>
  );
}

function SpendImport({ onImported }: { onImported: (msg: string) => void }) {
  const [platform, setPlatform] = useState('google');
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const upload = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!file) return;
    setBusy(true); setError(null);
    const body = new FormData();
    body.append('platform', platform);
    body.append('file', file);
    try {
      const res = await apiFetch('/api/marketing/spend/import', { method: 'POST', body });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || `Import failed (${res.status})`);
      onImported(`Imported ${data.saved} campaign-days of ${PLATFORM[platform]} spend${data.skipped?.length ? ` (${data.skipped.length} rows skipped)` : ''}.`);
    } catch (err) { setError((err as Error).message); } finally { setBusy(false); }
  };

  return (
    <Card title="Import ad spend (CSV)">
      <form onSubmit={upload} className="space-y-3 text-sm">
        <p className="text-xs text-neutral-500">
          For Google Ads or any platform without a connection: export a report with <strong>one row per campaign per day</strong>
          (columns like Day, Campaign, Cost / Amount spent, Impressions, Clicks, Conversions). Re-importing the same days replaces them.
        </p>
        <div className="grid gap-2 sm:grid-cols-2">
          <Field label="Platform">
            <select value={platform} onChange={e => setPlatform(e.target.value)} className={inputCls}>
              {Object.entries(PLATFORM).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </Field>
          <Field label="CSV file"><input type="file" accept=".csv,text/csv" onChange={e => setFile(e.target.files?.[0] ?? null)} className={`${inputCls} py-1.5`} /></Field>
        </div>
        <button type="submit" disabled={busy || !file} className={btnPrimary}>{busy ? 'Importing…' : 'Import spend'}</button>
        {error && <div className="text-sm text-red-600">{error}</div>}
      </form>
    </Card>
  );
}

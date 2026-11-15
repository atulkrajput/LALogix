import { useEffect, useState } from 'react';
import Link from '@/components/ui/Link';
import { Card, ErrorBanner, Field, Modal, Notice, StatCard } from '@/components/billing/ui';
import { api, btnDanger, btnPrimary, btnSecondary, formatDate, inputCls, money, today, type Client, type Product } from '@/lib/billing';

interface Line { description: string; sac: string | null; quantity: number; unit: string; rate: number; discount: number; gstRate?: number; gst_rate?: number }
interface Schedule {
  id: number; name: string; frequency: string; startDate: string; nextRunDate: string; endDate: string | null; status: string;
  autoIssue: boolean; autoEmail: boolean; items: Line[]; notes: string | null; terms: string | null; invoicesCreated: number;
  lastRunAt: string | null; lastError: string | null; amount: number; nextPeriodLabel?: string; clientId: number; productId: number;
  client?: { id: number; name: string; company: string | null; email: string | null }; product?: { id: number; name: string };
}
interface RunResult { created: number; issued: number; emailed: number; errors: string[] }

const FREQ: Record<string, string> = { monthly: 'Monthly', quarterly: 'Quarterly', half_yearly: 'Every 6 months', yearly: 'Yearly' };
const STATUS_STYLE: Record<string, string> = { active: 'bg-green-100 text-green-700', paused: 'bg-yellow-100 text-yellow-800', ended: 'bg-neutral-100 text-neutral-500' };

export default function RecurringPage() {
  const [schedules, setSchedules] = useState<Schedule[]>([]);
  const [summary, setSummary] = useState<{ active: number; monthly_value: number; yearly_value: number; due_next_30_days: number } | null>(null);
  const [clients, setClients] = useState<Client[]>([]);
  const [products, setProducts] = useState<Product[]>([]);
  const [editing, setEditing] = useState<Schedule | 'new' | null>(null);
  const [cronUrl, setCronUrl] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = () => api('/api/billing/recurring').then(d => { setSchedules(d.schedules); setSummary(d.summary); }).catch(e => setError(e.message));
  useEffect(() => {
    load();
    api('/api/billing/invoices/meta').then(d => { setClients(d.clients); setProducts(d.products); }).catch(() => {});
  }, []);

  const report = (r: RunResult) => setNotice(
    r.created ? `Created ${r.created} invoice(s): ${r.issued} issued, ${r.emailed} e-mailed.${r.errors.length ? ` ${r.errors.join(' ')}` : ''}`
      : r.errors.length ? r.errors.join(' ') : 'Nothing was due.');
  const run = async () => {
    setBusy(true); setError(null);
    try { const d = await api<{ result: RunResult }>('/api/billing/recurring/run', 'POST'); report(d.result); load(); }
    catch (e) { setError((e as Error).message); } finally { setBusy(false); }
  };
  const setStatus = async (s: Schedule, status: string) => {
    try { await api(`/api/billing/recurring/${s.id}/status`, 'POST', { status }); load(); } catch (e) { setError((e as Error).message); }
  };
  const remove = async (s: Schedule) => {
    if (!confirm(`Delete "${s.name}"? Invoices already created are kept.`)) return;
    try { await api(`/api/billing/recurring/${s.id}`, 'DELETE'); load(); } catch (e) { setError((e as Error).message); }
  };
  const showCron = async (regenerate = false) => {
    if (regenerate && !confirm('Create a new URL? The old one stops working.')) return;
    try { const d = await api<{ url: string }>(`/api/billing/recurring/cron-url${regenerate ? '?regenerate=1' : ''}`); setCronUrl(d.url); }
    catch (e) { setError((e as Error).message); }
  };

  return (
    <div className="space-y-4">
      <ErrorBanner message={error} onClose={() => setError(null)} />
      <Notice message={notice} />
      {summary && (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard label="Active schedules" value={String(summary.active)} />
          <StatCard label="Recurring revenue / month" value={money(summary.monthly_value)} tone="green" />
          <StatCard label="Per year" value={money(summary.yearly_value)} />
          <StatCard label="Invoices due in the next 30 days" value={String(summary.due_next_30_days)} tone="blue" />
        </div>
      )}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="max-w-2xl text-sm text-neutral-500">
          Retainers, AMCs and subscriptions billed to your clients. Due invoices are created automatically when you open the admin, through the cron URL below, or with <em>Run now</em>.
        </p>
        <div className="flex gap-2">
          <button onClick={run} disabled={busy} className={btnSecondary}>{busy ? 'Running…' : 'Run now'}</button>
          <button onClick={() => setEditing('new')} disabled={clients.length === 0} className={btnPrimary}>+ New schedule</button>
        </div>
      </div>

      <div className="overflow-x-auto rounded-xl border border-neutral-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-neutral-200 bg-neutral-50 text-left text-xs text-neutral-600">
              {['Schedule', 'Client', 'Every', 'Amount (before GST)', 'Next invoice', 'Automatic', 'Created', 'Status', ''].map(h => (
                <th key={h} className={`px-4 py-3 font-medium ${h.startsWith('Amount') ? 'text-right' : ''}`}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {schedules.length === 0 ? (
              <tr><td colSpan={9} className="px-4 py-8 text-center text-neutral-400">No recurring invoices yet.</td></tr>
            ) : schedules.map(s => (
              <tr key={s.id} className="border-b border-neutral-100 align-top">
                <td className="px-4 py-3">
                  <div className="font-medium text-neutral-900">{s.name}</div>
                  <div className="text-xs text-neutral-400">{s.product?.name}</div>
                  {s.lastError && <div className="mt-1 max-w-xs text-xs text-red-600">⚠ {s.lastError}</div>}
                </td>
                <td className="px-4 py-3"><Link href={`/admin/billing/clients/${s.clientId}`} className="text-brand-600 hover:underline">{s.client?.company || s.client?.name}</Link></td>
                <td className="px-4 py-3 text-neutral-600">{FREQ[s.frequency]}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums">{money(s.amount)}</td>
                <td className="whitespace-nowrap px-4 py-3">
                  {s.status === 'active' ? <>{formatDate(s.nextRunDate)}<div className="text-xs text-neutral-400">for {s.nextPeriodLabel}</div></> : <span className="text-neutral-400">—</span>}
                </td>
                <td className="px-4 py-3 text-xs text-neutral-600">{s.autoIssue ? 'issue' : 'draft only'}{s.autoEmail ? ' + e-mail' : ''}</td>
                <td className="px-4 py-3 text-neutral-600">
                  {s.invoicesCreated > 0 ? <Link href={`/admin/billing/invoices?client_id=${s.clientId}`} className="text-brand-600 hover:underline">{s.invoicesCreated}</Link> : 0}
                </td>
                <td className="px-4 py-3"><span className={`rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ${STATUS_STYLE[s.status] ?? ''}`}>{s.status}</span></td>
                <td className="whitespace-nowrap px-4 py-3 text-right text-xs">
                  <button onClick={() => setEditing(s)} className="text-brand-600 hover:underline">Edit</button>
                  {s.status === 'active' && <button onClick={() => setStatus(s, 'paused')} className="ml-3 text-neutral-600 hover:underline">Pause</button>}
                  {s.status === 'paused' && <button onClick={() => setStatus(s, 'active')} className="ml-3 text-green-700 hover:underline">Resume</button>}
                  <button onClick={() => remove(s)} className="ml-3 text-red-500 hover:underline">Delete</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <Card title="Run on a schedule (optional)">
        <p className="text-sm text-neutral-600">
          Invoices are created whenever you open the admin. To have them created (and e-mailed) on the due date even when you don't log in,
          point a free cron service such as cron-job.org at this secret URL, hourly or daily. If your host has cron, <code>php artisan schedule:run</code> every minute does the same.
        </p>
        {cronUrl ? (
          <div className="mt-3 flex flex-wrap items-center gap-2">
            <code className="flex-1 break-all rounded bg-neutral-100 px-2 py-1 font-mono text-xs">{cronUrl}</code>
            <button onClick={() => navigator.clipboard?.writeText(cronUrl)} className={btnSecondary}>Copy</button>
            <button onClick={() => showCron(true)} className={btnDanger}>New URL</button>
          </div>
        ) : <button onClick={() => showCron(false)} className={`${btnSecondary} mt-3`}>Show cron URL</button>}
      </Card>

      {editing && <ScheduleForm schedule={editing === 'new' ? null : editing} clients={clients} products={products}
        onClose={() => setEditing(null)} onSaved={() => { setEditing(null); load(); }} />}
    </div>
  );
}

function ScheduleForm({ schedule, clients, products, onClose, onSaved }: { schedule: Schedule | null; clients: Client[]; products: Product[]; onClose: () => void; onSaved: () => void }) {
  const services = products.find(p => p.kind === 'services') ?? products[0];
  const [f, setF] = useState({
    name: schedule?.name ?? '', client_id: schedule ? String(schedule.clientId) : '', product_id: String(schedule?.productId ?? services?.id ?? ''),
    frequency: schedule?.frequency ?? 'monthly', start_date: schedule?.startDate ?? today(), end_date: schedule?.endDate ?? '',
    auto_issue: schedule?.autoIssue ?? true, auto_email: schedule?.autoEmail ?? false, notes: schedule?.notes ?? '', terms: schedule?.terms ?? '',
  });
  const [lines, setLines] = useState<Line[]>(schedule?.items?.map(i => ({ ...i, gstRate: i.gstRate ?? i.gst_rate })) ?? [
    { description: 'Maintenance & support for {period}', sac: services?.defaultSac ?? '998313', quantity: 1, unit: 'NOS', rate: 0, discount: 0, gstRate: services?.defaultGstRate ?? 18 },
  ]);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const started = (schedule?.invoicesCreated ?? 0) > 0;
  const client = clients.find(c => String(c.id) === f.client_id);

  const setLine = (i: number, k: keyof Line, v: string) => setLines(ls => ls.map((l, n) => (n === i ? { ...l, [k]: ['quantity', 'rate', 'discount', 'gstRate'].includes(k) ? Number(v) : v } : l)));

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true); setError(null);
    const body = {
      ...f, client_id: Number(f.client_id), product_id: Number(f.product_id), end_date: f.end_date || null,
      items: lines.map(l => ({ description: l.description, sac: l.sac || null, quantity: l.quantity, unit: l.unit || 'NOS', rate: l.rate, discount: l.discount || 0, gst_rate: l.gstRate ?? 18 })),
    };
    try {
      await api(schedule ? `/api/billing/recurring/${schedule.id}` : '/api/billing/recurring', schedule ? 'PUT' : 'POST', body);
      onSaved();
    } catch (err) { setError((err as Error).message); } finally { setSaving(false); }
  };

  return (
    <Modal title={schedule ? `Edit "${schedule.name}"` : 'New recurring invoice'} onClose={onClose}>
      <form onSubmit={submit} className="space-y-4">
        <ErrorBanner message={error} />
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Name *" className="sm:col-span-2"><input required value={f.name} onChange={e => setF({ ...f, name: e.target.value })} className={inputCls} placeholder="e.g. Acme – website AMC" /></Field>
          <Field label="Client *">
            <select required value={f.client_id} onChange={e => setF({ ...f, client_id: e.target.value })} className={inputCls}>
              <option value="">Select client</option>
              {clients.map(c => <option key={c.id} value={c.id}>{c.company || c.name}</option>)}
            </select>
          </Field>
          <Field label="Product / series">
            <select value={f.product_id} onChange={e => setF({ ...f, product_id: e.target.value })} className={inputCls}>
              {products.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          </Field>
          <Field label="Every">
            <select value={f.frequency} onChange={e => setF({ ...f, frequency: e.target.value })} className={inputCls}>
              {Object.entries(FREQ).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </Field>
          <Field label="First invoice on *" hint={started ? 'Already started: changing this has no effect.' : 'Today or earlier creates the first invoice right away.'}>
            <input type="date" required value={f.start_date} onChange={e => setF({ ...f, start_date: e.target.value })} disabled={started} className={inputCls} />
          </Field>
          <Field label="End after (optional)"><input type="date" value={f.end_date} onChange={e => setF({ ...f, end_date: e.target.value })} className={inputCls} /></Field>
          <div className="flex flex-col justify-end gap-2 text-sm text-neutral-700">
            <label className="flex items-center gap-2"><input type="checkbox" checked={f.auto_issue} onChange={e => setF({ ...f, auto_issue: e.target.checked, auto_email: e.target.checked && f.auto_email })} /> Issue automatically (otherwise a draft)</label>
            <label className="flex items-center gap-2"><input type="checkbox" checked={f.auto_email} disabled={!client?.email} onChange={e => setF({ ...f, auto_email: e.target.checked, auto_issue: e.target.checked || f.auto_issue })} />
              E-mail the PDF to {client?.email ?? 'the client (no e-mail on file)'}</label>
          </div>
        </div>

        <div>
          <div className="mb-1 flex items-center justify-between">
            <span className="text-xs font-medium text-neutral-600">Lines (use {'{month}'}, {'{year}'} or {'{period}'} for the billing period)</span>
            <button type="button" onClick={() => setLines(ls => [...ls, { description: '', sac: services?.defaultSac ?? '', quantity: 1, unit: 'NOS', rate: 0, discount: 0, gstRate: 18 }])} className="text-xs text-brand-600 hover:underline">+ Add line</button>
          </div>
          {lines.map((l, i) => (
            <div key={i} className="mb-2 grid grid-cols-12 gap-2">
              <input value={l.description} onChange={e => setLine(i, 'description', e.target.value)} required placeholder="Description" className={`${inputCls} col-span-12 sm:col-span-6`} aria-label="Description" />
              <input value={l.sac ?? ''} onChange={e => setLine(i, 'sac', e.target.value)} placeholder="SAC" className={`${inputCls} col-span-3 sm:col-span-1`} aria-label="SAC" />
              <input type="number" min="0.01" step="0.01" value={l.quantity} onChange={e => setLine(i, 'quantity', e.target.value)} className={`${inputCls} col-span-3 sm:col-span-1`} aria-label="Quantity" />
              <input type="number" min="0" step="0.01" value={l.rate} onChange={e => setLine(i, 'rate', e.target.value)} className={`${inputCls} col-span-3 sm:col-span-2`} aria-label="Rate" />
              <select value={l.gstRate ?? 18} onChange={e => setLine(i, 'gstRate', e.target.value)} className={`${inputCls} col-span-2 sm:col-span-1`} aria-label="GST">
                {[0, 5, 12, 18, 28].map(r => <option key={r} value={r}>{r}%</option>)}
              </select>
              <button type="button" onClick={() => setLines(ls => ls.filter((_, n) => n !== i))} disabled={lines.length === 1} className="col-span-1 text-neutral-400 hover:text-red-500 disabled:opacity-30" aria-label="Remove line">✕</button>
            </div>
          ))}
          <div className="text-right text-sm text-neutral-600">Per invoice: <strong>{money(lines.reduce((s, l) => s + l.quantity * l.rate - (l.discount || 0), 0))}</strong> + GST</div>
        </div>
        <Field label="Notes (printed; placeholders work here too)"><textarea rows={2} value={f.notes} onChange={e => setF({ ...f, notes: e.target.value })} className={inputCls} /></Field>

        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className={btnSecondary}>Cancel</button>
          <button type="submit" disabled={saving} className={btnPrimary}>{saving ? 'Saving…' : 'Save schedule'}</button>
        </div>
      </form>
    </Modal>
  );
}

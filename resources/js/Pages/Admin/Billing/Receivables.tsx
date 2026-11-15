import { useEffect, useState } from 'react';
import Link from '@/components/ui/Link';
import { Card, ErrorBanner, Field, Modal, Notice, StatCard } from '@/components/billing/ui';
import { api, btnPrimary, btnSecondary, formatDate, inputCls, money } from '@/lib/billing';

type Bucket = 'not_due' | 'd1_30' | 'd31_60' | 'd61_90' | 'd90_plus';
interface Row {
  id: number; number: string; client_id: number; client: string; product: string | null; invoice_date: string; due_date: string | null;
  total: number; balance: number; days_overdue: number; bucket: Bucket; email: string | null; source: string; reminders_paused: boolean;
  client_opted_out: boolean; reminders_count: number; last_reminder_at: string | null; next_reminder: string | null; blocked: string | null;
}
type ClientRow = Record<Bucket, number> & { client_id: number; name: string; total: number; invoices: number; oldest_days: number };
interface Settings {
  reminders_enabled: boolean; reminder_days: string; reminder_subject: string | null; reminder_message: string | null;
  default_subject: string; default_message: string; started_on: string | null; last_run_at: string | null; last_error: string | null; pending_now: number;
}
interface Data {
  summary: Record<Bucket, number> & { total: number; count: number; overdue: number; overdue_count: number };
  buckets: Record<Bucket, string>; clients: ClientRow[]; invoices: Row[]; settings: Settings;
}

const BUCKET_TONE: Record<Bucket, string> = {
  not_due: 'text-neutral-600', d1_30: 'text-amber-600', d31_60: 'text-orange-600', d61_90: 'text-red-600', d90_plus: 'text-red-700 font-semibold',
};
const ORDER: Bucket[] = ['not_due', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'];

export default function ReceivablesPage() {
  const [data, setData] = useState<Data | null>(null);
  const [view, setView] = useState<'clients' | 'invoices'>('invoices');
  const [filter, setFilter] = useState<Bucket | 'overdue' | 'all'>('all');
  const [reminding, setReminding] = useState<Row | null>(null);
  const [editing, setEditing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = () => api<Data>('/api/billing/receivables').then(setData).catch(e => setError(e.message));
  useEffect(() => { load(); }, []);

  const run = async () => {
    setBusy(true); setError(null);
    try {
      const d = await api<{ result: { sent: number; errors: string[] } }>('/api/billing/reminders/run', 'POST');
      setNotice(d.result.sent ? `Sent ${d.result.sent} reminder(s).${d.result.errors.length ? ' ' + d.result.errors.join(' ') : ''}` : d.result.errors.join(' ') || 'No reminders were due.');
      load();
    } catch (e) { setError((e as Error).message); } finally { setBusy(false); }
  };
  const pause = async (r: Row) => {
    try { await api(`/api/billing/invoices/${r.id}/reminders-paused`, 'POST', { paused: !r.reminders_paused }); load(); } catch (e) { setError((e as Error).message); }
  };

  if (!data) return <ErrorBanner message={error} />;
  const s = data.settings;
  const rows = data.invoices.filter(r => filter === 'all' || (filter === 'overdue' ? r.days_overdue > 0 : r.bucket === filter));

  return (
    <div className="space-y-4">
      <ErrorBanner message={error} onClose={() => setError(null)} />
      <Notice message={notice} />

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label={`Outstanding (${data.summary.count} invoices)`} value={money(data.summary.total)} />
        <StatCard label={`Overdue (${data.summary.overdue_count})`} value={money(data.summary.overdue)} tone={data.summary.overdue > 0 ? 'red' : 'green'} />
        <StatCard label="Over 60 days" value={money(data.summary.d61_90 + data.summary.d90_plus)} tone="orange" />
        <StatCard label="Reminders" value={s.reminders_enabled ? `On · ${s.pending_now} due now` : 'Off'} tone={s.reminders_enabled ? 'blue' : 'neutral'} />
      </div>

      {/* Ageing bar */}
      <div className="rounded-xl border border-neutral-200 bg-white p-4">
        <div className="mb-2 flex flex-wrap gap-4 text-xs">
          {ORDER.map(b => (
            <button key={b} onClick={() => { setFilter(filter === b ? 'all' : b); setView('invoices'); }}
              className={`rounded px-2 py-1 ${filter === b ? 'bg-brand-50 ring-1 ring-brand-200' : 'hover:bg-neutral-50'}`}>
              <span className="text-neutral-500">{data.buckets[b]}</span> <span className={`tabular-nums ${BUCKET_TONE[b]}`}>{money(data.summary[b])}</span>
            </button>
          ))}
        </div>
        {data.summary.total > 0 && (
          <div className="flex h-2.5 overflow-hidden rounded-full bg-neutral-100" aria-hidden>
            {ORDER.map(b => data.summary[b] > 0 && (
              <div key={b} style={{ width: `${(data.summary[b] / data.summary.total) * 100}%` }}
                className={{ not_due: 'bg-neutral-300', d1_30: 'bg-amber-400', d31_60: 'bg-orange-500', d61_90: 'bg-red-500', d90_plus: 'bg-red-700' }[b]} />
            ))}
          </div>
        )}
      </div>

      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex rounded-lg border border-neutral-200 bg-white p-0.5 text-sm">
          {(['invoices', 'clients'] as const).map(v => (
            <button key={v} onClick={() => setView(v)} className={`rounded-md px-3 py-1.5 capitalize ${view === v ? 'bg-brand-600 text-white' : 'text-neutral-600'}`}>By {v === 'invoices' ? 'invoice' : 'client'}</button>
          ))}
        </div>
        <div className="flex gap-2">
          {filter !== 'all' && <button onClick={() => setFilter('all')} className={btnSecondary}>Show all</button>}
          {filter === 'all' && <button onClick={() => setFilter('overdue')} className={btnSecondary}>Overdue only</button>}
          <button onClick={() => setEditing(true)} className={btnSecondary}>Reminder settings</button>
          {s.reminders_enabled && <button onClick={run} disabled={busy} className={btnPrimary}>{busy ? 'Sending…' : `Send due reminders (${s.pending_now})`}</button>}
        </div>
      </div>

      {view === 'clients' ? (
        <div className="overflow-x-auto rounded-xl border border-neutral-200 bg-white">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-neutral-200 bg-neutral-50 text-left text-xs text-neutral-600">
                <th className="px-4 py-3 font-medium">Client</th>
                {ORDER.map(b => <th key={b} className="px-4 py-3 text-right font-medium">{data.buckets[b]}</th>)}
                <th className="px-4 py-3 text-right font-medium">Total due</th>
              </tr>
            </thead>
            <tbody>
              {data.clients.length === 0 ? <tr><td colSpan={7} className="px-4 py-8 text-center text-neutral-400">Nothing outstanding. 🎉</td></tr>
                : data.clients.map(c => (
                  <tr key={c.client_id} className="border-b border-neutral-100">
                    <td className="px-4 py-3">
                      <Link href={`/admin/billing/clients/${c.client_id}`} className="font-medium text-brand-600 hover:underline">{c.name}</Link>
                      <div className="text-xs text-neutral-400">{c.invoices} invoice{c.invoices === 1 ? '' : 's'}{c.oldest_days > 0 ? ` · oldest ${c.oldest_days} days late` : ''}</div>
                    </td>
                    {ORDER.map(b => <td key={b} className={`whitespace-nowrap px-4 py-3 text-right tabular-nums ${c[b] ? BUCKET_TONE[b] : 'text-neutral-300'}`}>{c[b] ? money(c[b]) : '—'}</td>)}
                    <td className="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums">{money(c.total)}</td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-neutral-200 bg-white">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-neutral-200 bg-neutral-50 text-left text-xs text-neutral-600">
                {['Invoice', 'Client', 'Due', 'Balance', 'Reminders', ''].map(h => <th key={h} className={`px-4 py-3 font-medium ${h === 'Balance' ? 'text-right' : ''}`}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 ? <tr><td colSpan={6} className="px-4 py-8 text-center text-neutral-400">{data.invoices.length ? 'No invoices in this bucket.' : 'Nothing outstanding. 🎉'}</td></tr>
                : rows.map(r => (
                  <tr key={r.id} className="border-b border-neutral-100 align-top">
                    <td className="px-4 py-3">
                      <Link href={`/admin/billing/invoices/${r.id}`} className="font-medium text-brand-600 hover:underline">{r.number}</Link>
                      <div className="text-xs text-neutral-400">{formatDate(r.invoice_date)}{r.product ? ` · ${r.product}` : ''}</div>
                    </td>
                    <td className="px-4 py-3"><Link href={`/admin/billing/clients/${r.client_id}`} className="hover:underline">{r.client}</Link></td>
                    <td className="whitespace-nowrap px-4 py-3">
                      {formatDate(r.due_date ?? r.invoice_date)}
                      <div className={`text-xs ${BUCKET_TONE[r.bucket]}`}>{r.days_overdue > 0 ? `${r.days_overdue} days late` : 'not due yet'}</div>
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                      <span className="font-medium">{money(r.balance)}</span>
                      {r.balance < r.total && <div className="text-xs text-neutral-400">of {money(r.total)}</div>}
                    </td>
                    <td className="px-4 py-3 text-xs text-neutral-600">
                      {r.reminders_count > 0 ? <div>{r.reminders_count} sent · last {formatDate(r.last_reminder_at)}</div> : <div className="text-neutral-400">None sent</div>}
                      {r.blocked ? <div className="text-neutral-400">{r.blocked}</div>
                        : r.next_reminder ? <div className="text-brand-700">Next: {r.next_reminder === new Date().toISOString().slice(0, 10) ? 'today' : formatDate(r.next_reminder)}</div> : null}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 text-right text-xs">
                      <button onClick={() => setReminding(r)} disabled={!r.email} title={r.email ? undefined : 'No e-mail address on the invoice'} className="text-brand-600 hover:underline disabled:text-neutral-300 disabled:no-underline">Remind</button>
                      {r.source === 'manual' && <button onClick={() => pause(r)} className="ml-3 text-neutral-500 hover:underline">{r.reminders_paused ? 'Resume auto' : 'Pause auto'}</button>}
                    </td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      )}

      {!s.reminders_enabled && data.summary.overdue_count > 0 && (
        <Card>
          <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
            <span className="text-neutral-600">Automatic payment reminders are off. Switch them on to e-mail clients before and after the due date, with the invoice attached.</span>
            <button onClick={() => setEditing(true)} className={btnPrimary}>Set up reminders</button>
          </div>
        </Card>
      )}

      {reminding && <RemindModal row={reminding} onClose={() => setReminding(null)} onSent={stub => { setReminding(null); setNotice(stub ? 'Reminder logged (e-mail is not configured, so nothing was actually sent).' : 'Reminder sent.'); load(); }} />}
      {editing && <SettingsModal settings={s} onClose={() => setEditing(false)} onSaved={() => { setEditing(false); setNotice('Reminder settings saved.'); load(); }} />}
    </div>
  );
}

function RemindModal({ row, onClose, onSent }: { row: Row; onClose: () => void; onSent: (stub: boolean) => void }) {
  const [f, setF] = useState<{ to: string; subject: string; message: string } | null>(null);
  const [history, setHistory] = useState<{ sent_at: string; to: string; stage: number | null }[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);

  useEffect(() => {
    api<{ to: string; subject: string; message: string; history: typeof history }>(`/api/billing/invoices/${row.id}/reminder`)
      .then(d => { setF({ to: d.to, subject: d.subject, message: d.message }); setHistory(d.history); })
      .catch(e => setError(e.message));
  }, [row.id]);

  const send = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!f) return;
    setSending(true); setError(null);
    try { const d = await api<{ stub: boolean }>(`/api/billing/invoices/${row.id}/reminder`, 'POST', f); onSent(d.stub); }
    catch (err) { setError((err as Error).message); } finally { setSending(false); }
  };

  return (
    <Modal title={`Payment reminder for ${row.number}`} onClose={onClose}>
      <ErrorBanner message={error} />
      {f && (
        <form onSubmit={send} className="space-y-3">
          <Field label="To"><input type="email" required value={f.to} onChange={e => setF({ ...f, to: e.target.value })} className={inputCls} /></Field>
          <Field label="Subject"><input required value={f.subject} onChange={e => setF({ ...f, subject: e.target.value })} className={inputCls} /></Field>
          <Field label="Message" hint="The invoice PDF is attached."><textarea required rows={11} value={f.message} onChange={e => setF({ ...f, message: e.target.value })} className={inputCls} /></Field>
          {history.length > 0 && (
            <div className="rounded-lg bg-neutral-50 p-3 text-xs text-neutral-600">
              <div className="mb-1 font-medium">Sent before</div>
              {history.map((h, i) => <div key={i}>{formatDate(h.sent_at)} to {h.to} · {h.stage === null ? 'by hand' : h.stage < 0 ? `${-h.stage} days before due` : h.stage === 0 ? 'on the due date' : `${h.stage} days late`}</div>)}
            </div>
          )}
          <div className="flex justify-end gap-2">
            <button type="button" onClick={onClose} className={btnSecondary}>Cancel</button>
            <button type="submit" disabled={sending} className={btnPrimary}>{sending ? 'Sending…' : 'Send reminder'}</button>
          </div>
        </form>
      )}
    </Modal>
  );
}

function describe(days: string) {
  const list = days.split(/[\s,]+/).filter(Boolean).map(Number).filter(n => Number.isInteger(n)).sort((a, b) => a - b);
  return list.map(n => (n < 0 ? `${-n} day${n === -1 ? '' : 's'} before` : n === 0 ? 'on the due date' : `${n} day${n === 1 ? '' : 's'} after`)).join(', ');
}

function SettingsModal({ settings, onClose, onSaved }: { settings: Settings; onClose: () => void; onSaved: () => void }) {
  const [f, setF] = useState({
    reminders_enabled: settings.reminders_enabled, reminder_days: settings.reminder_days,
    reminder_subject: settings.reminder_subject ?? settings.default_subject, reminder_message: settings.reminder_message ?? settings.default_message,
  });
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true); setError(null);
    try { await api('/api/billing/reminders/settings', 'PUT', f); onSaved(); }
    catch (err) { setError((err as Error).message); } finally { setSaving(false); }
  };

  return (
    <Modal title="Payment reminders" onClose={onClose}>
      <form onSubmit={save} className="space-y-4">
        <ErrorBanner message={error} />
        <label className="flex items-start gap-3 text-sm">
          <input type="checkbox" checked={f.reminders_enabled} onChange={e => setF({ ...f, reminders_enabled: e.target.checked })} className="mt-1" />
          <span>
            <span className="font-medium text-neutral-900">E-mail reminders automatically</span>
            <span className="block text-neutral-500">
              {settings.reminders_enabled && settings.started_on ? `On since ${formatDate(settings.started_on)}.` : 'Starts from today: reminders that would have gone out earlier are not sent in a burst.'}
              {' '}Invoices from connected products, clients with reminders turned off and paused invoices are skipped.
            </span>
          </span>
        </label>
        <Field label="Send on these days (relative to the due date)" hint={describe(f.reminder_days) || 'e.g. -3, 1, 7, 15, 30'}>
          <input value={f.reminder_days} onChange={e => setF({ ...f, reminder_days: e.target.value })} className={inputCls} placeholder="-3, 1, 7, 15, 30" />
        </Field>
        <Field label="Subject"><input value={f.reminder_subject} onChange={e => setF({ ...f, reminder_subject: e.target.value })} className={inputCls} /></Field>
        <Field label="Message" hint="Placeholders: {client} {company} {number} {amount} (balance due) {total} {invoice_date} {due_date} {due_text} {days_overdue} {payment_details} {business}">
          <textarea rows={10} value={f.reminder_message} onChange={e => setF({ ...f, reminder_message: e.target.value })} className={inputCls} />
        </Field>
        <p className="text-xs text-neutral-500">
          Only the latest due step is sent (never several at once), and never within 2 days of another reminder. Reminders go out when you open the admin, or on time through the cron URL on the Recurring page.
          {settings.last_run_at && ` Last checked ${new Date(settings.last_run_at).toLocaleString('en-IN')}.`}
        </p>
        {settings.last_error && <p className="text-xs text-red-600">Last problem: {settings.last_error}</p>}
        <div className="flex justify-between gap-2">
          <button type="button" onClick={() => setF({ ...f, reminder_subject: settings.default_subject, reminder_message: settings.default_message })} className="text-sm text-neutral-500 hover:underline">Reset wording</button>
          <div className="flex gap-2">
            <button type="button" onClick={onClose} className={btnSecondary}>Cancel</button>
            <button type="submit" disabled={saving} className={btnPrimary}>{saving ? 'Saving…' : 'Save'}</button>
          </div>
        </div>
      </form>
    </Modal>
  );
}

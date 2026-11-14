import { useEffect, useState } from 'react';
import Link from '@/components/ui/Link';
import ClientForm from '@/components/billing/ClientForm';
import { ErrorBanner, Modal } from '@/components/billing/ui';
import { api, btnPrimary, filterCls, inputCls, money, stateName, type Client, type StateOption } from '@/lib/billing';

export default function ClientsPage() {
  const [clients, setClients] = useState<Client[]>([]);
  const [states, setStates] = useState<StateOption[]>([]);
  const [q, setQ] = useState('');
  const [adding, setAdding] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  const load = (search = q) =>
    api<{ clients: Client[] }>(`/api/billing/clients?q=${encodeURIComponent(search)}`)
      .then(d => setClients(d.clients)).catch(e => setError(e.message)).finally(() => setLoading(false));

  useEffect(() => {
    api('/api/billing/settings').then(d => setStates(d.states)).catch(() => {});
  }, []);
  useEffect(() => { const t = setTimeout(() => load(q), 250); return () => clearTimeout(t); }, [q]);

  return (
    <div className="space-y-4">
      <ErrorBanner message={error} onClose={() => setError(null)} />
      <div className="flex flex-wrap items-center justify-between gap-3">
        <input type="search" value={q} onChange={e => setQ(e.target.value)} placeholder="Search name, company, GSTIN, email" className={`${filterCls} w-full max-w-sm`} aria-label="Search clients" />
        <button onClick={() => setAdding(true)} className={btnPrimary}>+ Add client</button>
      </div>

      <div className="overflow-x-auto rounded-xl border border-neutral-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-neutral-200 bg-neutral-50 text-left">
              {['Client', 'GSTIN', 'State', 'Contact', 'Invoices', 'Work entries', 'Outstanding'].map(h => (
                <th key={h} className={`px-4 py-3 font-medium text-neutral-600 ${h === 'Outstanding' ? 'text-right' : ''}`}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan={7} className="px-4 py-8 text-center text-neutral-400">Loading…</td></tr>
            ) : clients.length === 0 ? (
              <tr><td colSpan={7} className="px-4 py-8 text-center text-neutral-400">{q ? 'No clients match.' : 'No clients yet. Add your first client to start billing.'}</td></tr>
            ) : clients.map(c => (
              <tr key={c.id} className="border-b border-neutral-100 hover:bg-neutral-50">
                <td className="px-4 py-3">
                  <Link href={`/admin/billing/clients/${c.id}`} className="font-medium text-brand-600 hover:underline">{c.company || c.name}</Link>
                  {c.company && <div className="text-xs text-neutral-400">{c.name}</div>}
                </td>
                <td className="px-4 py-3 font-mono text-xs text-neutral-600">{c.gstin || <span className="font-sans text-neutral-400">Unregistered</span>}</td>
                <td className="px-4 py-3 text-neutral-600">{c.country.toLowerCase() === 'india' ? stateName(states, c.stateCode) : c.country}</td>
                <td className="px-4 py-3 text-neutral-600">{c.email || c.phone || '—'}</td>
                <td className="px-4 py-3 text-neutral-600">{c._count?.invoices ?? 0}</td>
                <td className="px-4 py-3 text-neutral-600">{c._count?.workItems ?? 0}</td>
                <td className={`px-4 py-3 text-right font-medium ${c.outstanding ? 'text-orange-600' : 'text-neutral-400'}`}>{money(c.outstanding)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {adding && (
        <Modal title="Add client" onClose={() => setAdding(false)}>
          <ClientForm states={states} onCancel={() => setAdding(false)} onSaved={() => { setAdding(false); load(); }} />
        </Modal>
      )}
    </div>
  );
}

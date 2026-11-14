import { useEffect, useState } from 'react';
import Link from '@/components/ui/Link';
import ClientForm from '@/components/billing/ClientForm';
import WorkForm from '@/components/billing/WorkForm';
import WorkTable from '@/components/billing/WorkTable';
import { Card, ErrorBanner, Modal, StatCard, StatusBadge } from '@/components/billing/ui';
import { useRouter } from '@/lib/navigation';
import { api, btnDanger, btnPrimary, btnSecondary, formatDate, money, stateName, type Client, type Invoice, type Product, type StateOption, type WorkItem } from '@/lib/billing';

interface Data {
  client: Client;
  invoices: Invoice[];
  workItems: WorkItem[];
  summary: { invoiced: number; credited: number; received: number; outstanding: number };
}

export default function ClientShow({ id }: { id: number }) {
  const router = useRouter();
  const [data, setData] = useState<Data | null>(null);
  const [states, setStates] = useState<StateOption[]>([]);
  const [products, setProducts] = useState<Product[]>([]);
  const [editing, setEditing] = useState(false);
  const [work, setWork] = useState<WorkItem | 'new' | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = () => api<Data>(`/api/billing/clients/${id}`).then(setData).catch(e => setError(e.message));

  useEffect(() => {
    load();
    api('/api/billing/invoices/meta').then(d => { setStates(d.states); setProducts(d.products); }).catch(() => {});
  }, [id]);

  if (!data) return <div className="text-sm text-neutral-400">{error ?? 'Loading…'}</div>;
  const { client, invoices, workItems, summary } = data;
  const indian = client.country.toLowerCase() === 'india';

  const removeWork = async (item: WorkItem) => {
    if (!confirm(`Delete "${item.title}"?`)) return;
    try { await api(`/api/billing/work/${item.id}`, 'DELETE'); await load(); } catch (err) { setError((err as Error).message); }
  };

  const removeClient = async () => {
    if (!confirm(`Delete ${client.company || client.name}?`)) return;
    try { await api(`/api/billing/clients/${id}`, 'DELETE'); router.push('/admin/billing/clients'); } catch (err) { setError((err as Error).message); }
  };

  return (
    <div className="space-y-5">
      <ErrorBanner message={error} onClose={() => setError(null)} />
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <Link href="/admin/billing/clients" className="text-sm text-neutral-500 hover:underline">← Clients</Link>
          <h2 className="mt-1 text-xl font-semibold text-neutral-900">{client.company || client.name}</h2>
          {client.company && <div className="text-sm text-neutral-500">{client.name}</div>}
        </div>
        <div className="flex flex-wrap gap-2">
          <button onClick={() => setEditing(true)} className={btnSecondary}>Edit client</button>
          {invoices.length === 0 && <button onClick={removeClient} className={btnDanger}>Delete</button>}
          <button onClick={() => setWork('new')} className={btnSecondary}>+ Log work</button>
          <Link href={`/admin/billing/invoices/new?client=${client.id}`} className={btnPrimary}>+ New invoice</Link>
        </div>
      </div>

      <div className="grid gap-3 sm:grid-cols-4">
        <StatCard label="Invoiced" value={money(summary.invoiced)} />
        <StatCard label="Credit notes" value={money(summary.credited)} />
        <StatCard label="Received (incl. TDS)" value={money(summary.received)} tone="green" />
        <StatCard label="Outstanding" value={money(summary.outstanding)} tone={summary.outstanding > 0 ? 'orange' : 'neutral'} />
      </div>

      <Card title="Details">
        <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
          <div><dt className="text-xs text-neutral-500">GSTIN</dt><dd className="font-mono">{client.gstin || <span className="font-sans text-neutral-400">Unregistered (B2C)</span>}</dd></div>
          <div><dt className="text-xs text-neutral-500">{indian ? 'State' : 'Country'}</dt><dd>{indian ? `${client.stateCode} - ${stateName(states, client.stateCode)}` : client.country}</dd></div>
          <div><dt className="text-xs text-neutral-500">PAN</dt><dd className="font-mono">{client.pan || '—'}</dd></div>
          <div><dt className="text-xs text-neutral-500">Email</dt><dd>{client.email || '—'}</dd></div>
          <div><dt className="text-xs text-neutral-500">Phone</dt><dd>{client.phone || '—'}</dd></div>
          <div><dt className="text-xs text-neutral-500">Address</dt><dd className="whitespace-pre-line">{[client.addressLine1, client.addressLine2, [client.city, client.pincode].filter(Boolean).join(' ')].filter(Boolean).join('\n') || '—'}</dd></div>
          {client.notes && <div className="sm:col-span-3"><dt className="text-xs text-neutral-500">Notes</dt><dd className="whitespace-pre-line">{client.notes}</dd></div>}
        </dl>
      </Card>

      <Card title="Invoices & credit notes">
        {invoices.length === 0 ? <p className="text-sm text-neutral-400">No invoices yet.</p> : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-neutral-200 text-left">
                  {['Number', 'Date', 'Product', 'Total', 'Balance', 'Status'].map(h => <th key={h} className={`py-2 pr-4 font-medium text-neutral-600 ${['Total', 'Balance'].includes(h) ? 'text-right' : ''}`}>{h}</th>)}
                </tr>
              </thead>
              <tbody>
                {invoices.map(inv => (
                  <tr key={inv.id} className="border-b border-neutral-100">
                    <td className="py-2 pr-4">
                      <Link href={`/admin/billing/invoices/${inv.id}`} className="font-medium text-brand-600 hover:underline">{inv.number ?? 'Draft'}</Link>
                      {inv.type === 'credit_note' && <span className="ml-2 text-xs text-neutral-400">Credit note</span>}
                    </td>
                    <td className="py-2 pr-4 text-neutral-500">{formatDate(inv.invoiceDate)}</td>
                    <td className="py-2 pr-4 text-neutral-600">{inv.product?.name}</td>
                    <td className="py-2 pr-4 text-right">{inv.type === 'credit_note' ? '−' : ''}{money(inv.grandTotal)}</td>
                    <td className="py-2 pr-4 text-right">{inv.balanceDue > 0 ? money(inv.balanceDue) : '—'}</td>
                    <td className="py-2"><StatusBadge status={inv.paymentStatus} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <div>
        <h3 className="mb-2 text-sm font-semibold text-neutral-900">Work log</h3>
        <WorkTable items={workItems} showClient={false} onEdit={setWork} onDelete={removeWork} />
      </div>

      {editing && (
        <Modal title="Edit client" onClose={() => setEditing(false)}>
          <ClientForm client={client} states={states} onCancel={() => setEditing(false)} onSaved={() => { setEditing(false); load(); }} />
        </Modal>
      )}
      {work && (
        <Modal title={work === 'new' ? 'Log work' : 'Edit work'} onClose={() => setWork(null)}>
          <WorkForm item={work === 'new' ? null : work} clients={[client]} products={products} clientId={client.id}
            onCancel={() => setWork(null)} onSaved={() => { setWork(null); load(); }} />
        </Modal>
      )}
    </div>
  );
}

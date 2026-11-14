import { useEffect, useState } from 'react';
import WorkForm from '@/components/billing/WorkForm';
import WorkTable from '@/components/billing/WorkTable';
import { ErrorBanner, Modal } from '@/components/billing/ui';
import { api, btnPrimary, filterCls, inputCls, type Client, type Product, type WorkItem } from '@/lib/billing';

export default function WorkPage() {
  const [items, setItems] = useState<WorkItem[]>([]);
  const [clients, setClients] = useState<Client[]>([]);
  const [products, setProducts] = useState<Product[]>([]);
  const [status, setStatus] = useState('unbilled');
  const [clientId, setClientId] = useState('');
  const [editing, setEditing] = useState<WorkItem | 'new' | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = () => {
    const params = new URLSearchParams();
    if (status !== 'all') params.set('status', status);
    if (clientId) params.set('client_id', clientId);
    return api<{ workItems: WorkItem[] }>(`/api/billing/work?${params}`).then(d => setItems(d.workItems)).catch(e => setError(e.message));
  };

  useEffect(() => {
    api('/api/billing/invoices/meta').then(d => { setClients(d.clients); setProducts(d.products); }).catch(e => setError(e.message));
  }, []);
  useEffect(() => { load(); }, [status, clientId]);

  const remove = async (item: WorkItem) => {
    if (!confirm(`Delete "${item.title}"?`)) return;
    try { await api(`/api/billing/work/${item.id}`, 'DELETE'); await load(); } catch (err) { setError((err as Error).message); }
  };

  return (
    <div className="space-y-4">
      <ErrorBanner message={error} onClose={() => setError(null)} />
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap gap-2">
          <select value={status} onChange={e => setStatus(e.target.value)} className={filterCls} aria-label="Filter by status">
            <option value="unbilled">Unbilled</option>
            <option value="billed">Billed</option>
            <option value="all">All work</option>
          </select>
          <select value={clientId} onChange={e => setClientId(e.target.value)} className={filterCls} aria-label="Filter by client">
            <option value="">All clients</option>
            {clients.map(c => <option key={c.id} value={c.id}>{c.company || c.name}</option>)}
          </select>
        </div>
        <button onClick={() => setEditing('new')} disabled={clients.length === 0} className={btnPrimary} title={clients.length === 0 ? 'Add a client first' : undefined}>+ Log work</button>
      </div>

      <WorkTable items={items} onEdit={setEditing} onDelete={remove} />

      {editing && (
        <Modal title={editing === 'new' ? 'Log work' : 'Edit work'} onClose={() => setEditing(null)}>
          <WorkForm item={editing === 'new' ? null : editing} clients={clients} products={products}
            clientId={clientId ? Number(clientId) : null}
            onCancel={() => setEditing(null)} onSaved={() => { setEditing(null); load(); }} />
        </Modal>
      )}
    </div>
  );
}

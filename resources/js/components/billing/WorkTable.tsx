import { useState } from 'react';
import Link from '@/components/ui/Link';
import { useRouter } from '@/lib/navigation';
import { btnPrimary, formatDate, money, type WorkItem } from '@/lib/billing';

/**
 * Work entries with checkboxes on unbilled rows; "Create invoice" opens the builder
 * pre-filled with the selected rows (they must belong to one client).
 */
export default function WorkTable({ items, showClient = true, onEdit, onDelete }: {
  items: WorkItem[];
  showClient?: boolean;
  onEdit: (item: WorkItem) => void;
  onDelete: (item: WorkItem) => void;
}) {
  const router = useRouter();
  const [selected, setSelected] = useState<number[]>([]);
  const chosen = items.filter(i => selected.includes(i.id));
  const clientIds = [...new Set(chosen.map(i => i.clientId))];

  const toggle = (id: number) => setSelected(s => (s.includes(id) ? s.filter(x => x !== id) : [...s, id]));

  const createInvoice = () =>
    router.push(`/admin/billing/invoices/new?client=${clientIds[0]}&work=${chosen.map(i => i.id).join(',')}`);

  return (
    <div className="space-y-3">
      {chosen.length > 0 && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-brand-200 bg-brand-50 px-4 py-2 text-sm">
          <span>
            {chosen.length} selected · {money(chosen.reduce((s, i) => s + i.quantity * i.rate, 0))} before tax
            {clientIds.length > 1 && <span className="ml-2 text-red-600">Select work for one client at a time.</span>}
          </span>
          <button onClick={createInvoice} disabled={clientIds.length !== 1} className={btnPrimary}>Create invoice</button>
        </div>
      )}
      <div className="overflow-x-auto rounded-xl border border-neutral-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-neutral-200 bg-neutral-50 text-left">
              <th className="w-8 px-3 py-3"><span className="sr-only">Select</span></th>
              <th className="px-3 py-3 font-medium text-neutral-600">Date</th>
              {showClient && <th className="px-3 py-3 font-medium text-neutral-600">Client</th>}
              <th className="px-3 py-3 font-medium text-neutral-600">Work</th>
              <th className="px-3 py-3 font-medium text-neutral-600">Product</th>
              <th className="px-3 py-3 text-right font-medium text-neutral-600">Qty × Rate</th>
              <th className="px-3 py-3 text-right font-medium text-neutral-600">Amount</th>
              <th className="px-3 py-3 font-medium text-neutral-600">Invoice</th>
              <th className="px-3 py-3" />
            </tr>
          </thead>
          <tbody>
            {items.length === 0 ? (
              <tr><td colSpan={9} className="px-4 py-8 text-center text-neutral-400">No work logged.</td></tr>
            ) : items.map(i => (
              <tr key={i.id} className="border-b border-neutral-100">
                <td className="px-3 py-3">
                  {!i.invoiceId && <input type="checkbox" checked={selected.includes(i.id)} onChange={() => toggle(i.id)} aria-label={`Select ${i.title}`} />}
                </td>
                <td className="whitespace-nowrap px-3 py-3 text-neutral-500">{formatDate(i.workDate)}</td>
                {showClient && (
                  <td className="px-3 py-3">
                    <Link href={`/admin/billing/clients/${i.clientId}`} className="text-brand-600 hover:underline">{i.client?.company || i.client?.name}</Link>
                  </td>
                )}
                <td className="px-3 py-3">
                  <div className="font-medium text-neutral-900">{i.title}</div>
                  {i.description && <div className="line-clamp-1 text-xs text-neutral-400">{i.description}</div>}
                </td>
                <td className="px-3 py-3 text-neutral-600">{i.product?.name ?? '—'}</td>
                <td className="whitespace-nowrap px-3 py-3 text-right text-neutral-600">{i.quantity} {i.unit} × {money(i.rate)}</td>
                <td className="whitespace-nowrap px-3 py-3 text-right font-medium">{money(i.quantity * i.rate)}</td>
                <td className="px-3 py-3">
                  {i.invoiceId ? (
                    <Link href={`/admin/billing/invoices/${i.invoiceId}`} className="text-xs text-brand-600 hover:underline">{i.invoice?.number ?? 'Draft'}</Link>
                  ) : <span className="rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-800">Unbilled</span>}
                </td>
                <td className="whitespace-nowrap px-3 py-3 text-right">
                  {!i.invoiceId && (
                    <>
                      <button onClick={() => onEdit(i)} className="text-xs text-brand-600 hover:underline">Edit</button>
                      <button onClick={() => onDelete(i)} className="ml-3 text-xs text-red-500 hover:underline">Delete</button>
                    </>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

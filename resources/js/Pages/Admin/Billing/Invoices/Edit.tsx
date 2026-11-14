import { useEffect, useMemo, useState } from 'react';
import Link from '@/components/ui/Link';
import ClientForm from '@/components/billing/ClientForm';
import { Card, ErrorBanner, Field, Modal } from '@/components/billing/ui';
import { useRouter } from '@/lib/navigation';
import {
  api, btnPrimary, btnSecondary, calculate, inputCls, money, OUTSIDE_INDIA, stateName, supplyTypeFor, today,
  type Client, type Invoice, type InvoiceItem, type Product, type StateOption, type WorkItem,
} from '@/lib/billing';

interface Meta {
  profile: { stateCode: string | null; gstin: string | null; lutNumber: string | null; paymentTermsDays: number; roundOffTotal: boolean; invoiceNotes: string | null; invoiceTerms: string | null };
  products: Product[];
  clients: Client[];
  states: StateOption[];
  gstRates: number[];
}

type Line = InvoiceItem & { key: number };
let keySeq = 1;
const blankLine = (p?: Product): Line => ({ key: keySeq++, description: '', sac: p?.defaultSac ?? '', quantity: 1, unit: 'NOS', rate: 0, discount: 0, gstRate: p?.defaultGstRate ?? 18, workItemId: null });
const fromWork = (w: WorkItem): Line => ({
  key: keySeq++, workItemId: w.id, description: w.description ? `${w.title}\n${w.description}` : w.title,
  sac: w.sac ?? '', quantity: w.quantity, unit: w.unit, rate: w.rate, discount: 0, gstRate: w.gstRate,
});

const addDays = (iso: string, days: number) => {
  const d = new Date(`${iso}T00:00:00`);
  d.setDate(d.getDate() + days);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

export default function InvoiceEdit({ id, clientId, workIds }: { id: number | null; clientId: number | null; workIds: number[] }) {
  const router = useRouter();
  const [meta, setMeta] = useState<Meta | null>(null);
  const [invoice, setInvoice] = useState<Invoice | null>(null);
  const [form, setForm] = useState({
    product_id: '', client_id: clientId ? String(clientId) : '', invoice_date: today(), due_date: '',
    place_of_supply: '', export_with_lut: true, reverse_charge: false, notes: '', terms: '', reason: '',
  });
  const [lines, setLines] = useState<Line[]>([]);
  const [unbilled, setUnbilled] = useState<WorkItem[]>([]);
  const [addingClient, setAddingClient] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState<'' | 'draft' | 'issue'>('');
  const isCn = invoice?.type === 'credit_note';

  // Load lookups, then the draft being edited (or defaults for a new invoice).
  useEffect(() => {
    (async () => {
      try {
        const m = await api<Meta>('/api/billing/invoices/meta');
        setMeta(m);
        if (id) {
          const d = await api<{ invoice: Invoice; unbilledWork: WorkItem[] }>(`/api/billing/invoices/${id}`);
          const inv = d.invoice;
          if (inv.status !== 'draft') { router.replace(`/admin/billing/invoices/${id}`); return; }
          setInvoice(inv);
          setUnbilled(d.unbilledWork);
          setForm({
            product_id: String(inv.productId), client_id: String(inv.clientId), invoice_date: inv.invoiceDate,
            due_date: inv.dueDate ?? '', place_of_supply: inv.placeOfSupply, export_with_lut: inv.exportWithLut,
            reverse_charge: inv.reverseCharge, notes: inv.notes ?? '', terms: inv.terms ?? '', reason: inv.reason ?? '',
          });
          setLines((inv.items ?? []).map(i => ({ ...i, sac: i.sac ?? '', key: keySeq++ })));
        } else {
          const product = m.products.find(p => p.kind === 'services') ?? m.products[0];
          setForm(f => ({
            ...f,
            product_id: product ? String(product.id) : '',
            due_date: addDays(f.invoice_date, m.profile.paymentTermsDays ?? 15),
            notes: m.profile.invoiceNotes ?? '',
            terms: m.profile.invoiceTerms ?? '',
            export_with_lut: !!m.profile.lutNumber,
          }));
          setLines([blankLine(product)]);
        }
      } catch (e) {
        setError((e as Error).message);
      }
    })();
  }, [id]);

  const client = meta?.clients.find(c => String(c.id) === form.client_id);
  const product = meta?.products.find(p => String(p.id) === form.product_id);

  // New invoice: when the client changes, default the place of supply and load their unbilled work.
  useEffect(() => {
    if (!meta || id || !client) return;
    const pos = client.country.toLowerCase() !== 'india' ? OUTSIDE_INDIA : client.stateCode ?? '';
    setForm(f => ({ ...f, place_of_supply: pos }));
    api<{ workItems: WorkItem[] }>(`/api/billing/work?client_id=${client.id}&status=unbilled`).then(d => {
      setUnbilled(d.workItems);
      const preselected = d.workItems.filter(w => workIds.includes(w.id));
      if (preselected.length) setLines(preselected.map(fromWork));
    }).catch(() => {});
  }, [meta, form.client_id]);

  const supplyType = supplyTypeFor(meta?.profile.stateCode, form.place_of_supply);
  const calc = useMemo(
    () => calculate(lines, supplyType, form.export_with_lut, meta?.profile.roundOffTotal ?? true),
    [lines, supplyType, form.export_with_lut, meta],
  );

  if (!meta) return <div className="text-sm text-neutral-400">{error ?? 'Loading…'}</div>;

  if (!meta.profile.stateCode) {
    return (
      <Card title="Set up your business profile first">
        <p className="text-sm text-neutral-600">Your GST state decides whether invoices carry CGST+SGST or IGST.</p>
        <Link href="/admin/billing/settings" className={`${btnPrimary} mt-4 inline-block`}>Open business profile</Link>
      </Card>
    );
  }

  const set = (key: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
    const value = e.target.type === 'checkbox' ? (e.target as HTMLInputElement).checked : e.target.value;
    setForm(f => {
      const next = { ...f, [key]: value };
      if (key === 'invoice_date' && typeof value === 'string' && value && !isCn) next.due_date = addDays(value, meta.profile.paymentTermsDays ?? 15);
      return next;
    });
  };

  const setLine = (key: number, field: keyof InvoiceItem, value: string) =>
    setLines(ls => ls.map(l => (l.key === key ? { ...l, [field]: ['quantity', 'rate', 'discount', 'gstRate'].includes(field) ? (value === '' ? ('' as unknown as number) : Number(value)) : value } : l)));

  const addWork = (w: WorkItem) => setLines(ls => [...ls.filter(l => l.description.trim() || l.rate), fromWork(w)]);
  const usedWork = new Set(lines.map(l => l.workItemId).filter(Boolean));

  const save = async (issue: boolean) => {
    setSaving(issue ? 'issue' : 'draft');
    setError(null);
    const body = {
      ...form,
      product_id: Number(form.product_id),
      client_id: Number(form.client_id),
      due_date: form.due_date || null,
      place_of_supply: form.place_of_supply || null,
      items: lines.filter(l => l.description.trim() || Number(l.rate)).map(l => ({
        description: l.description, sac: l.sac || null, quantity: Number(l.quantity), unit: l.unit || 'NOS',
        rate: Number(l.rate), discount: Number(l.discount) || 0, gst_rate: Number(l.gstRate), work_item_id: l.workItemId || null,
      })),
    };
    try {
      const saved = await api<{ invoice: Invoice }>(id ? `/api/billing/invoices/${id}` : '/api/billing/invoices', id ? 'PUT' : 'POST', body);
      if (issue) {
        try {
          await api(`/api/billing/invoices/${saved.invoice.id}/issue`, 'POST');
        } catch (e) {
          // Saved as draft but couldn't issue (e.g. missing prefix): open the draft with the reason.
          router.push(`/admin/billing/invoices/${saved.invoice.id}?error=${encodeURIComponent((e as Error).message)}`);
          return;
        }
      }
      router.push(`/admin/billing/invoices/${saved.invoice.id}`);
    } catch (e) {
      setError((e as Error).message);
      window.scrollTo({ top: 0, behavior: 'smooth' });
      setSaving('');
    }
  };

  const unbilledLeft = unbilled.filter(w => !usedWork.has(w.id));

  return (
    <div className="mx-auto max-w-6xl space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Link href={id ? `/admin/billing/invoices/${id}` : '/admin/billing/invoices'} className="text-sm text-neutral-500 hover:underline">← Back</Link>
        <span className="text-sm text-neutral-500">{isCn ? `Credit note against ${invoice?.originalInvoice?.number}` : id ? 'Editing draft invoice' : 'New invoice'}</span>
      </div>
      <ErrorBanner message={error} onClose={() => setError(null)} />
      {!meta.profile.gstin && (
        <div className="rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-800">
          Add your GSTIN in <Link href="/admin/billing/settings" className="underline">Business profile</Link> before issuing. You can still save drafts.
        </div>
      )}

      <Card>
        <div className="grid gap-4 md:grid-cols-3">
          <Field label="Client *">
            <div className="flex gap-2">
              <select required value={form.client_id} onChange={set('client_id')} disabled={isCn || !!id} className={inputCls}>
                <option value="">Select client</option>
                {meta.clients.map(c => <option key={c.id} value={c.id}>{c.company || c.name}{c.gstin ? ` · ${c.gstin}` : ''}</option>)}
              </select>
              {!id && <button type="button" onClick={() => setAddingClient(true)} className={`${btnSecondary} whitespace-nowrap`}>+ New</button>}
            </div>
          </Field>
          <Field label="Product / series *" hint={product && !(isCn ? product.creditNotePrefix : product.invoicePrefix) ? <span className="text-orange-600">No {isCn ? 'credit note' : 'invoice'} prefix set for this product yet.</span> : undefined}>
            <select required value={form.product_id} onChange={set('product_id')} disabled={isCn} className={inputCls}>
              {meta.products.map(p => <option key={p.id} value={p.id}>{p.name}{(isCn ? p.creditNotePrefix : p.invoicePrefix) ? ` (${isCn ? p.creditNotePrefix : p.invoicePrefix})` : ''}</option>)}
            </select>
          </Field>
          <Field label="Place of supply *" hint={{ intra: 'Same state: CGST + SGST', inter: 'Other state: IGST', export: 'Export of services' }[supplyType]}>
            <select required value={form.place_of_supply} onChange={set('place_of_supply')} disabled={isCn} className={inputCls}>
              <option value="">Select</option>
              {meta.states.map(s => <option key={s.code} value={s.code}>{s.code} - {s.name}</option>)}
              <option value={OUTSIDE_INDIA}>96 - Outside India (export)</option>
            </select>
          </Field>
          <Field label={isCn ? 'Credit note date *' : 'Invoice date *'}><input type="date" required value={form.invoice_date} onChange={set('invoice_date')} className={inputCls} /></Field>
          {!isCn && <Field label="Due date"><input type="date" value={form.due_date} onChange={set('due_date')} className={inputCls} /></Field>}
          {isCn && <Field label="Reason *"><input required value={form.reason} onChange={set('reason')} className={inputCls} placeholder="e.g. Discount after sale, service deficiency" /></Field>}
          <div className="flex flex-col justify-end gap-2 pb-1 text-sm text-neutral-700">
            {supplyType === 'export' && !isCn && (
              <label className="flex items-center gap-2">
                <input type="checkbox" checked={form.export_with_lut} onChange={set('export_with_lut')} />
                Export under LUT (no IGST){!meta.profile.lutNumber && <span className="text-xs text-orange-600">add LUT no. in profile</span>}
              </label>
            )}
            <label className="flex items-center gap-2">
              <input type="checkbox" checked={form.reverse_charge} onChange={set('reverse_charge')} disabled={isCn} /> Tax payable on reverse charge
            </label>
          </div>
        </div>
        {client && (
          <p className="mt-3 text-xs text-neutral-500">
            Billing <span className="font-medium text-neutral-700">{client.company || client.name}</span>
            {client.gstin ? <> · GSTIN <span className="font-mono">{client.gstin}</span> (B2B)</> : ' · unregistered (B2C)'}
            {client.country.toLowerCase() === 'india' ? ` · ${stateName(meta.states, client.stateCode)}` : ` · ${client.country}`}
          </p>
        )}
      </Card>

      {!isCn && unbilledLeft.length > 0 && (
        <div className="rounded-xl border border-brand-200 bg-brand-50 p-4">
          <div className="mb-2 text-sm font-medium text-neutral-800">Unbilled work for this client</div>
          <div className="flex flex-wrap gap-2">
            {unbilledLeft.map(w => (
              <button key={w.id} type="button" onClick={() => addWork(w)} className="rounded-full border border-brand-300 bg-white px-3 py-1 text-xs text-brand-700 hover:bg-brand-100">
                + {w.title} · {money(w.quantity * w.rate)}
              </button>
            ))}
          </div>
        </div>
      )}

      <Card title="Items" actions={<button type="button" onClick={() => setLines(ls => [...ls, blankLine(product)])} className={btnSecondary}>+ Add line</button>}>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[900px] text-sm">
            <thead>
              <tr className="border-b border-neutral-200 text-left text-xs text-neutral-500">
                <th className="py-2 pr-2 font-medium">Description</th>
                <th className="w-24 py-2 pr-2 font-medium">SAC/HSN</th>
                <th className="w-20 py-2 pr-2 font-medium">Qty</th>
                <th className="w-20 py-2 pr-2 font-medium">Unit</th>
                <th className="w-28 py-2 pr-2 font-medium">Rate ₹</th>
                <th className="w-24 py-2 pr-2 font-medium">Discount ₹</th>
                <th className="w-24 py-2 pr-2 font-medium">GST</th>
                <th className="w-28 py-2 pr-2 text-right font-medium">Amount</th>
                <th className="w-8" />
              </tr>
            </thead>
            <tbody>
              {lines.map((l, i) => (
                <tr key={l.key} className="border-b border-neutral-100 align-top">
                  <td className="py-2 pr-2">
                    <textarea rows={l.description.includes('\n') ? 2 : 1} value={l.description} onChange={e => setLine(l.key, 'description', e.target.value)} placeholder="Service description" className={inputCls} aria-label={`Line ${i + 1} description`} />
                    {l.workItemId ? <div className="mt-1 text-xs text-brand-600">From work log</div> : null}
                  </td>
                  <td className="py-2 pr-2"><input value={l.sac ?? ''} onChange={e => setLine(l.key, 'sac', e.target.value)} maxLength={8} className={inputCls} aria-label={`Line ${i + 1} SAC`} /></td>
                  <td className="py-2 pr-2"><input type="number" step="0.01" min="0.01" value={l.quantity} onChange={e => setLine(l.key, 'quantity', e.target.value)} className={inputCls} aria-label={`Line ${i + 1} quantity`} /></td>
                  <td className="py-2 pr-2"><input value={l.unit} onChange={e => setLine(l.key, 'unit', e.target.value.toUpperCase())} maxLength={10} className={inputCls} aria-label={`Line ${i + 1} unit`} /></td>
                  <td className="py-2 pr-2"><input type="number" step="0.01" min="0" value={l.rate} onChange={e => setLine(l.key, 'rate', e.target.value)} className={inputCls} aria-label={`Line ${i + 1} rate`} /></td>
                  <td className="py-2 pr-2"><input type="number" step="0.01" min="0" value={l.discount} onChange={e => setLine(l.key, 'discount', e.target.value)} className={inputCls} aria-label={`Line ${i + 1} discount`} /></td>
                  <td className="py-2 pr-2">
                    <select value={l.gstRate} onChange={e => setLine(l.key, 'gstRate', e.target.value)} className={inputCls} aria-label={`Line ${i + 1} GST rate`}>
                      {meta.gstRates.map(r => <option key={r} value={r}>{r}%</option>)}
                    </select>
                  </td>
                  <td className="py-2 pr-2 text-right">
                    <div className="pt-2 font-medium">{money(calc.lines[i]?.amount)}</div>
                    <div className="text-xs text-neutral-400">{money(calc.lines[i]?.taxable)} + tax</div>
                  </td>
                  <td className="py-2 text-right">
                    <button type="button" onClick={() => setLines(ls => ls.filter(x => x.key !== l.key))} disabled={lines.length === 1} className="pt-2 text-neutral-400 hover:text-red-500 disabled:opacity-30" aria-label={`Remove line ${i + 1}`}>✕</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="mt-4 flex justify-end">
          <dl className="w-full max-w-xs space-y-1 text-sm">
            <div className="flex justify-between"><dt className="text-neutral-500">Taxable value</dt><dd>{money(calc.taxable)}</dd></div>
            {supplyType === 'intra' ? (
              <>
                <div className="flex justify-between"><dt className="text-neutral-500">CGST</dt><dd>{money(calc.cgst)}</dd></div>
                <div className="flex justify-between"><dt className="text-neutral-500">SGST</dt><dd>{money(calc.sgst)}</dd></div>
              </>
            ) : (
              <div className="flex justify-between"><dt className="text-neutral-500">IGST{supplyType === 'export' && form.export_with_lut ? ' (LUT, 0%)' : ''}</dt><dd>{money(calc.igst)}</dd></div>
            )}
            {calc.roundOff !== 0 && <div className="flex justify-between"><dt className="text-neutral-500">Round off</dt><dd>{money(calc.roundOff)}</dd></div>}
            <div className="flex justify-between border-t border-neutral-200 pt-2 text-base font-semibold"><dt>Total</dt><dd>{money(calc.grand)}</dd></div>
          </dl>
        </div>
      </Card>

      <Card>
        <div className="grid gap-4 md:grid-cols-2">
          <Field label="Notes (printed)"><textarea rows={3} value={form.notes} onChange={set('notes')} className={inputCls} /></Field>
          <Field label="Terms (printed)"><textarea rows={3} value={form.terms} onChange={set('terms')} className={inputCls} /></Field>
        </div>
      </Card>

      <div className="flex flex-wrap justify-end gap-2 pb-6">
        <button type="button" onClick={() => save(false)} disabled={!!saving || !form.client_id} className={btnSecondary}>{saving === 'draft' ? 'Saving…' : 'Save draft'}</button>
        <button type="button" onClick={() => save(true)} disabled={!!saving || !form.client_id} className={btnPrimary}>{saving === 'issue' ? 'Issuing…' : `Save & issue ${isCn ? 'credit note' : 'invoice'}`}</button>
      </div>

      {addingClient && (
        <Modal title="Add client" onClose={() => setAddingClient(false)}>
          <ClientForm states={meta.states} onCancel={() => setAddingClient(false)} onSaved={c => {
            setMeta(m => (m ? { ...m, clients: [...m.clients, c].sort((a, b) => (a.company || a.name).localeCompare(b.company || b.name)) } : m));
            setForm(f => ({ ...f, client_id: String(c.id) }));
            setAddingClient(false);
          }} />
        </Modal>
      )}
    </div>
  );
}

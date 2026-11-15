import { useEffect, useState } from 'react';
import Link from '@/components/ui/Link';
import { Card, ErrorBanner, Field, Modal, Notice } from '@/components/billing/ui';
import { useRouter } from '@/lib/navigation';
import { api, btnDanger, btnPrimary, btnSecondary, formatDate, inputCls, money, OUTSIDE_INDIA, type InvoiceItem } from '@/lib/billing';
import { QUOTE_STATUS } from './Index';

interface Quotation {
  id: number; number: string; status: string; displayStatus: string; quoteDate: string; validUntil: string | null;
  placeOfSupply: string; supplyType: string; billName: string; billCompany: string | null; billGstin: string | null; billAddress: string | null; billEmail: string | null;
  taxableTotal: number; cgstTotal: number; sgstTotal: number; igstTotal: number; roundOff: number; grandTotal: number;
  notes: string | null; terms: string | null; clientId: number; items: InvoiceItem[];
  product?: { name: string }; invoice?: { id: number; number: string | null; status: string } | null;
}

export default function QuotationShow({ id }: { id: number }) {
  const router = useRouter();
  const [q, setQ] = useState<Quotation | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [emailing, setEmailing] = useState(false);

  const load = () => api<{ quotation: Quotation }>(`/api/billing/quotations/${id}`).then(d => setQ(d.quotation)).catch(e => setError(e.message));
  useEffect(() => { load(); }, [id]);
  if (!q) return <div className="text-sm text-neutral-400">{error ?? 'Loading…'}</div>;

  const act = async (fn: () => Promise<void>) => {
    setBusy(true); setError(null); setNotice(null);
    try { await fn(); } catch (e) { setError((e as Error).message); } finally { setBusy(false); }
  };
  const setStatus = (status: string) => act(async () => { const d = await api<{ quotation: Quotation }>(`/api/billing/quotations/${id}/status`, 'POST', { status }); setQ(d.quotation); });
  const convert = () => act(async () => {
    const d = await api<{ invoice: { id: number } }>(`/api/billing/quotations/${id}/convert`, 'POST');
    router.push(`/admin/billing/invoices/${d.invoice.id}/edit`);
  });
  const duplicate = () => act(async () => {
    const d = await api<{ quotation: { id: number } }>(`/api/billing/quotations/${id}/duplicate`, 'POST');
    router.push(`/admin/billing/quotations/${d.quotation.id}/edit`);
  });
  const remove = () => { if (confirm('Delete this quotation?')) act(async () => { await api(`/api/billing/quotations/${id}`, 'DELETE'); router.push('/admin/billing/quotations'); }); };

  const converted = q.status === 'converted';
  const intra = q.supplyType === 'intra';

  return (
    <div className="mx-auto max-w-5xl space-y-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <Link href="/admin/billing/quotations" className="text-sm text-neutral-500 hover:underline">← Quotations</Link>
          <h2 className="mt-1 flex flex-wrap items-center gap-3 text-xl font-semibold text-neutral-900">
            Quotation {q.number}
            <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ${QUOTE_STATUS[q.displayStatus] ?? ''}`}>{q.displayStatus}</span>
          </h2>
          <div className="text-sm text-neutral-500">{q.product?.name} · {formatDate(q.quoteDate)}{q.validUntil ? ` · valid until ${formatDate(q.validUntil)}` : ''}</div>
        </div>
        <div className="flex flex-wrap gap-2">
          {!converted && <button onClick={remove} disabled={busy} className={btnDanger}>Delete</button>}
          {!converted && <Link href={`/admin/billing/quotations/${id}/edit`} className={btnSecondary}>Edit</Link>}
          <button onClick={duplicate} disabled={busy} className={btnSecondary}>Duplicate</button>
          <a href={`/admin/billing/quotations/${id}/pdf`} target="_blank" rel="noreferrer" className={btnSecondary}>PDF</a>
          <button onClick={() => setEmailing(true)} className={btnSecondary}>Email</button>
          {!converted && q.status !== 'accepted' && <button onClick={() => setStatus('accepted')} disabled={busy} className={btnSecondary}>Mark accepted</button>}
          {!converted && q.status !== 'declined' && <button onClick={() => setStatus('declined')} disabled={busy} className={btnSecondary}>Mark declined</button>}
          {!converted && q.status !== 'declined' && <button onClick={convert} disabled={busy} className={btnPrimary}>Convert to invoice</button>}
        </div>
      </div>
      <ErrorBanner message={error} onClose={() => setError(null)} />
      <Notice message={notice} />
      {converted && q.invoice && (
        <div className="rounded-lg border border-purple-200 bg-purple-50 px-4 py-3 text-sm text-purple-800">
          Converted to invoice <Link href={`/admin/billing/invoices/${q.invoice.id}`} className="font-medium underline">{q.invoice.number ?? '(draft)'}</Link>.
        </div>
      )}

      <Card>
        <div className="grid gap-4 text-sm sm:grid-cols-2">
          <div>
            <div className="text-xs font-medium uppercase text-neutral-400">Quotation for</div>
            <Link href={`/admin/billing/clients/${q.clientId}`} className="font-semibold text-brand-600 hover:underline">{q.billName}</Link>
            {q.billCompany && <div>Attn: {q.billCompany}</div>}
            {q.billAddress && <div className="whitespace-pre-line text-neutral-600">{q.billAddress}</div>}
            {q.billGstin && <div className="mt-1 font-mono text-xs">GSTIN {q.billGstin}</div>}
          </div>
          <div className="text-neutral-600">Place of supply: {q.placeOfSupply === OUTSIDE_INDIA ? 'Outside India' : q.placeOfSupply} · {q.supplyType}</div>
        </div>
      </Card>

      <Card title="Items">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-neutral-200 text-left text-xs text-neutral-500">
              <th className="py-2 pr-3 font-medium">Description</th>
              <th className="py-2 pr-3 text-right font-medium">Qty × Rate</th>
              <th className="py-2 pr-3 text-right font-medium">Before GST</th>
              <th className="py-2 pr-3 text-right font-medium">GST</th>
              <th className="py-2 text-right font-medium">Amount</th>
            </tr>
          </thead>
          <tbody>
            {q.items.map((i, n) => (
              <tr key={n} className="border-b border-neutral-100 align-top">
                <td className="whitespace-pre-line py-2 pr-3">{i.description}<div className="text-xs text-neutral-400">{i.sac}</div></td>
                <td className="whitespace-nowrap py-2 pr-3 text-right text-neutral-600">{i.quantity} {i.unit} × {money(i.rate)}</td>
                <td className="py-2 pr-3 text-right">{money(i.taxableValue)}</td>
                <td className="py-2 pr-3 text-right text-neutral-600">{money((i.cgst ?? 0) + (i.sgst ?? 0) + (i.igst ?? 0))}<div className="text-xs text-neutral-400">{i.gstRate}%</div></td>
                <td className="py-2 text-right font-medium">{money(i.amount)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <dl className="ml-auto mt-4 max-w-xs space-y-1 text-sm">
          <div className="flex justify-between"><dt className="text-neutral-500">Before GST</dt><dd>{money(q.taxableTotal)}</dd></div>
          {intra ? (
            <><div className="flex justify-between"><dt className="text-neutral-500">CGST</dt><dd>{money(q.cgstTotal)}</dd></div>
              <div className="flex justify-between"><dt className="text-neutral-500">SGST</dt><dd>{money(q.sgstTotal)}</dd></div></>
          ) : <div className="flex justify-between"><dt className="text-neutral-500">IGST</dt><dd>{money(q.igstTotal)}</dd></div>}
          <div className="flex justify-between border-t border-neutral-200 pt-2 text-base font-semibold"><dt>Total</dt><dd>{money(q.grandTotal)}</dd></div>
        </dl>
      </Card>

      {emailing && (
        <EmailQuote q={q} onClose={() => setEmailing(false)} onSent={stub => { setEmailing(false); load(); setNotice(stub ? 'Email logged only: RESEND_API_KEY is not set, so nothing was sent.' : 'Quotation sent with the PDF attached.'); }} />
      )}
    </div>
  );
}

function EmailQuote({ q, onClose, onSent }: { q: Quotation; onClose: () => void; onSent: (stub: boolean) => void }) {
  const [f, setF] = useState({
    to: q.billEmail ?? '',
    subject: `Quotation ${q.number} from LALogix Enterprises`,
    message: `Dear ${q.billCompany || q.billName},\n\nPlease find attached our quotation ${q.number} for ${money(q.grandTotal)}${q.validUntil ? `, valid until ${formatDate(q.validUntil)}` : ''}.\n\nHappy to answer any questions.\n\nRegards,\nLALogix Enterprises`,
  });
  const [error, setError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSending(true); setError(null);
    try { const d = await api<{ stub: boolean }>(`/api/billing/quotations/${q.id}/email`, 'POST', f); onSent(d.stub); }
    catch (err) { setError((err as Error).message); } finally { setSending(false); }
  };
  return (
    <Modal title={`Email quotation ${q.number}`} onClose={onClose}>
      <form onSubmit={submit} className="space-y-4">
        <ErrorBanner message={error} />
        <Field label="To *"><input type="email" required value={f.to} onChange={e => setF({ ...f, to: e.target.value })} className={inputCls} /></Field>
        <Field label="Subject *"><input required value={f.subject} onChange={e => setF({ ...f, subject: e.target.value })} className={inputCls} /></Field>
        <Field label="Message *" hint="The PDF is attached; a draft quotation is marked as sent."><textarea required rows={7} value={f.message} onChange={e => setF({ ...f, message: e.target.value })} className={inputCls} /></Field>
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className={btnSecondary}>Cancel</button>
          <button type="submit" disabled={sending} className={btnPrimary}>{sending ? 'Sending…' : 'Send email'}</button>
        </div>
      </form>
    </Modal>
  );
}

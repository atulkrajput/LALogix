import { useEffect, useState } from 'react';
import Link from '@/components/ui/Link';
import { Card, ErrorBanner, Field, Modal, Notice, StatusBadge } from '@/components/billing/ui';
import { useRouter, useSearchParams } from '@/lib/navigation';
import {
  api, btnDanger, btnPrimary, btnSecondary, formatDate, inputCls, money, OUTSIDE_INDIA, today, type Invoice,
} from '@/lib/billing';

const METHODS = { bank: 'Bank transfer', upi: 'UPI', cash: 'Cash', cheque: 'Cheque', card: 'Card', other: 'Other' };

export default function InvoiceShow({ id }: { id: number }) {
  const router = useRouter();
  const params = useSearchParams();
  const [invoice, setInvoice] = useState<Invoice | null>(null);
  const [error, setError] = useState<string | null>(() => params.get('error'));
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [dialog, setDialog] = useState<'' | 'payment' | 'cancel' | 'email'>('');

  const load = () => api<{ invoice: Invoice }>(`/api/billing/invoices/${id}`).then(d => setInvoice(d.invoice)).catch(e => setError(e.message));
  useEffect(() => { load(); }, [id]);

  if (!invoice) return <div className="text-sm text-neutral-400">{error ?? 'Loading…'}</div>;

  const isCn = invoice.type === 'credit_note';
  const external = invoice.source !== 'manual';
  const label = isCn ? 'credit note' : 'invoice';
  const intra = invoice.supplyType === 'intra';

  const act = async (fn: () => Promise<unknown>, success?: string) => {
    setBusy(true); setError(null); setNotice(null);
    try {
      await fn();
      if (success) setNotice(success);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const issue = () => act(async () => {
    const d = await api<{ invoice: Invoice }>(`/api/billing/invoices/${id}/issue`, 'POST');
    setInvoice(d.invoice);
    window.history.replaceState(null, '', window.location.pathname);
    setNotice(`Issued as ${d.invoice.number}.`);
  });

  const removeDraft = () => {
    if (!confirm('Delete this draft?')) return;
    act(async () => {
      await api(`/api/billing/invoices/${id}`, 'DELETE');
      router.push(isCn ? '/admin/billing/credit-notes' : '/admin/billing/invoices');
    });
  };

  const creditNote = () => act(async () => {
    const d = await api<{ invoice: Invoice }>(`/api/billing/invoices/${id}/credit-note`, 'POST');
    router.push(`/admin/billing/invoices/${d.invoice.id}/edit`);
  });

  const deletePayment = (paymentId: number) => {
    if (!confirm('Delete this payment?')) return;
    act(async () => {
      const d = await api<{ invoice: Invoice }>(`/api/billing/invoices/${id}/payments/${paymentId}`, 'DELETE');
      setInvoice(d.invoice);
    }, 'Payment deleted.');
  };

  return (
    <div className="mx-auto max-w-6xl space-y-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <Link href={isCn ? '/admin/billing/credit-notes' : '/admin/billing/invoices'} className="text-sm text-neutral-500 hover:underline">← {isCn ? 'Credit notes' : 'Invoices'}</Link>
          <h2 className="mt-1 flex flex-wrap items-center gap-3 text-xl font-semibold text-neutral-900">
            {isCn ? 'Credit note' : 'Invoice'} {invoice.number ?? '(draft)'}
            <StatusBadge status={invoice.paymentStatus} />
          </h2>
          <div className="text-sm text-neutral-500">{invoice.product?.name} · {formatDate(invoice.invoiceDate)}</div>
        </div>
        {external ? (
          <div className="flex flex-wrap items-center gap-2">
            <span className="rounded-lg bg-blue-50 px-3 py-2 text-sm text-blue-700">
              Issued by {invoice.product?.name} ({invoice.source === 'api' ? 'synced via API' : 'imported from CSV'}) · ref {invoice.externalId}
            </span>
            {!isCn && invoice.status === 'issued' && invoice.balanceDue > 0 && <button onClick={() => setDialog('payment')} className={btnPrimary}>Record payment</button>}
          </div>
        ) : (
        <div className="flex flex-wrap gap-2">
          {invoice.status === 'draft' && (
            <>
              <button onClick={removeDraft} disabled={busy} className={btnDanger}>Delete draft</button>
              <Link href={`/admin/billing/invoices/${id}/edit`} className={btnSecondary}>Edit</Link>
              <a href={`/admin/billing/invoices/${id}/pdf`} target="_blank" rel="noreferrer" className={btnSecondary}>Preview PDF</a>
              <button onClick={issue} disabled={busy} className={btnPrimary}>Issue {label}</button>
            </>
          )}
          {invoice.status !== 'draft' && (
            <>
              <a href={`/admin/billing/invoices/${id}/pdf`} target="_blank" rel="noreferrer" className={btnSecondary}>View PDF</a>
              <a href={`/admin/billing/invoices/${id}/pdf?download=1`} className={btnSecondary}>Download</a>
            </>
          )}
          {invoice.status === 'issued' && (
            <>
              <button onClick={() => setDialog('email')} className={btnSecondary}>Email</button>
              {!isCn && <button onClick={creditNote} disabled={busy} className={btnSecondary}>Credit note</button>}
              <button onClick={() => setDialog('cancel')} className={btnDanger}>Cancel {label}</button>
              {!isCn && invoice.balanceDue > 0 && <button onClick={() => setDialog('payment')} className={btnPrimary}>Record payment</button>}
            </>
          )}
        </div>
        )}
      </div>

      <ErrorBanner message={error} onClose={() => setError(null)} />
      <Notice message={notice} />
      {invoice.status === 'cancelled' && (
        <div className="rounded-lg border border-neutral-200 bg-neutral-100 px-4 py-3 text-sm text-neutral-600">
          Cancelled on {formatDate(invoice.cancelledAt)}: {invoice.cancelReason}. The number stays used and is reported as cancelled in GSTR-1.
        </div>
      )}

      <div className="grid gap-5 lg:grid-cols-3">
        <div className="space-y-5 lg:col-span-2">
          <Card>
            <div className="grid gap-4 text-sm sm:grid-cols-2">
              <div>
                <div className="text-xs font-medium uppercase text-neutral-400">{isCn ? 'Issued to' : 'Bill to'}</div>
                <Link href={`/admin/billing/clients/${invoice.clientId}`} className="font-semibold text-brand-600 hover:underline">{invoice.billName}</Link>
                {invoice.billCompany && <div>Attn: {invoice.billCompany}</div>}
                {invoice.billAddress && <div className="whitespace-pre-line text-neutral-600">{invoice.billAddress}</div>}
                <div className="mt-1 font-mono text-xs">{invoice.billGstin ? `GSTIN ${invoice.billGstin}` : <span className="font-sans text-neutral-400">Unregistered (B2C)</span>}</div>
              </div>
              <dl className="grid grid-cols-2 gap-y-1">
                <dt className="text-neutral-500">Place of supply</dt><dd>{invoice.placeOfSupply === OUTSIDE_INDIA ? 'Outside India' : invoice.placeOfSupply}</dd>
                <dt className="text-neutral-500">Supply</dt><dd className="capitalize">{invoice.supplyType}{invoice.supplyType === 'export' ? (invoice.exportWithLut ? ' (LUT)' : ' (with IGST)') : ''}</dd>
                {!isCn && <><dt className="text-neutral-500">Due date</dt><dd>{formatDate(invoice.dueDate)}</dd></>}
                <dt className="text-neutral-500">Reverse charge</dt><dd>{invoice.reverseCharge ? 'Yes' : 'No'}</dd>
                {isCn && invoice.originalInvoice && (
                  <><dt className="text-neutral-500">Against</dt><dd><Link href={`/admin/billing/invoices/${invoice.originalInvoice.id}`} className="text-brand-600 hover:underline">{invoice.originalInvoice.number}</Link></dd></>
                )}
                {isCn && invoice.reason && <><dt className="text-neutral-500">Reason</dt><dd>{invoice.reason}</dd></>}
                {invoice.emailedAt && <><dt className="text-neutral-500">Emailed</dt><dd>{formatDate(invoice.emailedAt)}</dd></>}
              </dl>
            </div>
          </Card>

          <Card title="Items">
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-neutral-200 text-left text-xs text-neutral-500">
                    <th className="py-2 pr-3 font-medium">Description</th>
                    <th className="py-2 pr-3 font-medium">SAC</th>
                    <th className="py-2 pr-3 text-right font-medium">Qty × Rate</th>
                    <th className="py-2 pr-3 text-right font-medium">Taxable</th>
                    <th className="py-2 pr-3 text-right font-medium">{intra ? 'CGST + SGST' : 'IGST'}</th>
                    <th className="py-2 text-right font-medium">Amount</th>
                  </tr>
                </thead>
                <tbody>
                  {(invoice.items ?? []).map(item => (
                    <tr key={item.id} className="border-b border-neutral-100 align-top">
                      <td className="whitespace-pre-line py-2 pr-3">{item.description}</td>
                      <td className="py-2 pr-3 text-neutral-500">{item.sac}</td>
                      <td className="whitespace-nowrap py-2 pr-3 text-right text-neutral-600">
                        {item.quantity} {item.unit} × {money(item.rate)}
                        {item.discount > 0 && <div className="text-xs">− {money(item.discount)}</div>}
                      </td>
                      <td className="py-2 pr-3 text-right">{money(item.taxableValue)}</td>
                      <td className="whitespace-nowrap py-2 pr-3 text-right text-neutral-600">
                        {money(intra ? (item.cgst ?? 0) + (item.sgst ?? 0) : item.igst)}
                        <div className="text-xs text-neutral-400">{item.gstRate}%</div>
                      </td>
                      <td className="py-2 text-right font-medium">{money(item.amount)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <dl className="ml-auto mt-4 max-w-xs space-y-1 text-sm">
              <div className="flex justify-between"><dt className="text-neutral-500">Taxable value</dt><dd>{money(invoice.taxableTotal)}</dd></div>
              {intra ? (
                <>
                  <div className="flex justify-between"><dt className="text-neutral-500">CGST</dt><dd>{money(invoice.cgstTotal)}</dd></div>
                  <div className="flex justify-between"><dt className="text-neutral-500">SGST</dt><dd>{money(invoice.sgstTotal)}</dd></div>
                </>
              ) : <div className="flex justify-between"><dt className="text-neutral-500">IGST</dt><dd>{money(invoice.igstTotal)}</dd></div>}
              {invoice.roundOff !== 0 && <div className="flex justify-between"><dt className="text-neutral-500">Round off</dt><dd>{money(invoice.roundOff)}</dd></div>}
              <div className="flex justify-between border-t border-neutral-200 pt-2 text-base font-semibold"><dt>Total</dt><dd>{money(invoice.grandTotal)}</dd></div>
            </dl>
          </Card>

          {(invoice.notes || invoice.terms) && (
            <Card>
              {invoice.notes && <><div className="text-xs font-medium uppercase text-neutral-400">Notes</div><p className="mb-3 whitespace-pre-line text-sm">{invoice.notes}</p></>}
              {invoice.terms && <><div className="text-xs font-medium uppercase text-neutral-400">Terms</div><p className="whitespace-pre-line text-sm text-neutral-600">{invoice.terms}</p></>}
            </Card>
          )}
        </div>

        <div className="space-y-5">
          {!isCn && invoice.status === 'issued' && (
            <Card title="Payment">
              <dl className="space-y-1 text-sm">
                <div className="flex justify-between"><dt className="text-neutral-500">Invoice total</dt><dd>{money(invoice.grandTotal)}</dd></div>
                {invoice.amountCredited > 0 && <div className="flex justify-between"><dt className="text-neutral-500">Credit notes</dt><dd>− {money(invoice.amountCredited)}</dd></div>}
                <div className="flex justify-between"><dt className="text-neutral-500">Received (incl. TDS)</dt><dd>− {money(invoice.amountPaid)}</dd></div>
                <div className="flex justify-between border-t border-neutral-200 pt-2 font-semibold"><dt>Balance due</dt><dd className={invoice.balanceDue > 0 ? 'text-orange-600' : 'text-green-700'}>{money(invoice.balanceDue)}</dd></div>
              </dl>
              {(invoice.payments ?? []).length > 0 && (
                <ul className="mt-4 space-y-2 border-t border-neutral-100 pt-3">
                  {invoice.payments!.map(p => (
                    <li key={p.id} className="flex items-start justify-between gap-2 text-sm">
                      <div>
                        <div className="font-medium">{money(p.amount)}{p.tdsAmount > 0 && <span className="text-xs font-normal text-neutral-500"> + {money(p.tdsAmount)} TDS</span>}</div>
                        <div className="text-xs text-neutral-500">{formatDate(p.paymentDate)} · {METHODS[p.method as keyof typeof METHODS] ?? p.method}{p.reference ? ` · ${p.reference}` : ''}</div>
                      </div>
                      <button onClick={() => deletePayment(p.id)} className="text-xs text-red-500 hover:underline">Delete</button>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          )}

          {!isCn && (invoice.creditNotes ?? []).length > 0 && (
            <Card title="Credit notes">
              <ul className="space-y-2 text-sm">
                {invoice.creditNotes!.map(cn => (
                  <li key={cn.id} className="flex justify-between">
                    <Link href={`/admin/billing/invoices/${cn.id}`} className="text-brand-600 hover:underline">{cn.number ?? 'Draft'}</Link>
                    <span className="text-neutral-600">{money(cn.grandTotal)} <StatusBadge status={cn.status} /></span>
                  </li>
                ))}
              </ul>
            </Card>
          )}
        </div>
      </div>

      {dialog === 'payment' && <PaymentDialog invoice={invoice} onClose={() => setDialog('')} onSaved={inv => { setInvoice(inv); setDialog(''); setNotice('Payment recorded.'); }} />}
      {dialog === 'cancel' && <CancelDialog invoice={invoice} label={label} onClose={() => setDialog('')} onSaved={inv => { setInvoice(inv); setDialog(''); setNotice(`${isCn ? 'Credit note' : 'Invoice'} cancelled.`); }} />}
      {dialog === 'email' && <EmailDialog invoice={invoice} label={label} onClose={() => setDialog('')} onSent={stub => { setDialog(''); load(); setNotice(stub ? 'Email logged only: RESEND_API_KEY is not set on this server, so nothing was sent.' : 'Email sent with the PDF attached.'); }} />}
    </div>
  );
}

function PaymentDialog({ invoice, onClose, onSaved }: { invoice: Invoice; onClose: () => void; onSaved: (inv: Invoice) => void }) {
  const [form, setForm] = useState({ payment_date: today(), amount: String(invoice.balanceDue), tds_amount: '', method: 'bank', reference: '', notes: '' });
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setForm({ ...form, [k]: e.target.value });

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true); setError(null);
    try {
      const d = await api<{ invoice: Invoice }>(`/api/billing/invoices/${invoice.id}/payments`, 'POST', {
        ...form, amount: Number(form.amount) || 0, tds_amount: Number(form.tds_amount) || 0,
      });
      onSaved(d.invoice);
    } catch (err) { setError((err as Error).message); } finally { setSaving(false); }
  };

  return (
    <Modal title={`Record payment · ${invoice.number}`} onClose={onClose}>
      <form onSubmit={submit} className="space-y-4">
        <ErrorBanner message={error} />
        <p className="text-sm text-neutral-500">Balance due {money(invoice.balanceDue)}. If the client deducted TDS, enter it separately: it counts towards the invoice.</p>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Date received *"><input type="date" required value={form.payment_date} onChange={set('payment_date')} className={inputCls} /></Field>
          <Field label="Method">
            <select value={form.method} onChange={set('method')} className={inputCls}>{Object.entries(METHODS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
          </Field>
          <Field label="Amount received ₹ *"><input type="number" step="0.01" min="0" required value={form.amount} onChange={set('amount')} className={inputCls} /></Field>
          <Field label="TDS deducted ₹"><input type="number" step="0.01" min="0" value={form.tds_amount} onChange={set('tds_amount')} className={inputCls} placeholder="0.00" /></Field>
          <Field label="Reference / UTR" className="sm:col-span-2"><input value={form.reference} onChange={set('reference')} className={inputCls} /></Field>
        </div>
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className={btnSecondary}>Cancel</button>
          <button type="submit" disabled={saving} className={btnPrimary}>{saving ? 'Saving…' : 'Record payment'}</button>
        </div>
      </form>
    </Modal>
  );
}

function CancelDialog({ invoice, label, onClose, onSaved }: { invoice: Invoice; label: string; onClose: () => void; onSaved: (inv: Invoice) => void }) {
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true); setError(null);
    try {
      const d = await api<{ invoice: Invoice }>(`/api/billing/invoices/${invoice.id}/cancel`, 'POST', { reason });
      onSaved(d.invoice);
    } catch (err) { setError((err as Error).message); } finally { setSaving(false); }
  };

  return (
    <Modal title={`Cancel ${label} ${invoice.number}`} onClose={onClose}>
      <form onSubmit={submit} className="space-y-4">
        <ErrorBanner message={error} />
        <p className="text-sm text-neutral-600">
          The number {invoice.number} stays used and is reported as cancelled in GSTR-1. This can't be undone.
          {label === 'invoice' && ' To reduce an invoice that was already reported, raise a credit note instead.'}
        </p>
        <Field label="Reason *"><input required value={reason} onChange={e => setReason(e.target.value)} className={inputCls} placeholder="e.g. Raised in error, duplicate" /></Field>
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className={btnSecondary}>Keep {label}</button>
          <button type="submit" disabled={saving} className="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50">{saving ? 'Cancelling…' : `Cancel ${label}`}</button>
        </div>
      </form>
    </Modal>
  );
}

function EmailDialog({ invoice, label, onClose, onSent }: { invoice: Invoice; label: string; onClose: () => void; onSent: (stub: boolean) => void }) {
  const seller = 'LALogix Enterprises';
  const [form, setForm] = useState({
    to: invoice.billEmail ?? '',
    subject: `${label === 'invoice' ? 'Invoice' : 'Credit note'} ${invoice.number} from ${seller}`,
    message: label === 'invoice'
      ? `Dear ${invoice.billCompany || invoice.billName},\n\nPlease find attached invoice ${invoice.number} dated ${formatDate(invoice.invoiceDate)} for ${money(invoice.grandTotal)}${invoice.dueDate ? `, due by ${formatDate(invoice.dueDate)}` : ''}.\n\nThank you for your business.\n\nRegards,\n${seller}`
      : `Dear ${invoice.billCompany || invoice.billName},\n\nPlease find attached credit note ${invoice.number} for ${money(invoice.grandTotal)}.\n\nRegards,\n${seller}`,
  });
  const [error, setError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSending(true); setError(null);
    try {
      const d = await api<{ stub: boolean }>(`/api/billing/invoices/${invoice.id}/email`, 'POST', form);
      onSent(d.stub);
    } catch (err) { setError((err as Error).message); } finally { setSending(false); }
  };

  return (
    <Modal title={`Email ${label} ${invoice.number}`} onClose={onClose}>
      <form onSubmit={submit} className="space-y-4">
        <ErrorBanner message={error} />
        <Field label="To *"><input type="email" required value={form.to} onChange={e => setForm({ ...form, to: e.target.value })} className={inputCls} /></Field>
        <Field label="Subject *"><input required value={form.subject} onChange={e => setForm({ ...form, subject: e.target.value })} className={inputCls} /></Field>
        <Field label="Message *" hint="The PDF is attached automatically."><textarea required rows={8} value={form.message} onChange={e => setForm({ ...form, message: e.target.value })} className={inputCls} /></Field>
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className={btnSecondary}>Cancel</button>
          <button type="submit" disabled={sending} className={btnPrimary}>{sending ? 'Sending…' : 'Send email'}</button>
        </div>
      </form>
    </Modal>
  );
}

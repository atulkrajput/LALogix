import { useEffect, useState } from 'react';
import { api, btnDanger, btnPrimary, btnSecondary, formatDate, type Product } from '@/lib/billing';
import { ErrorBanner, Modal } from './ui';
import SyncNow from './SyncNow';

interface Log { id: number; channel: string; action: string; externalId: string | null; number: string | null; outcome: string; message: string | null; invoiceId: number | null; createdAt: string }

const OUTCOME = { synced: 'bg-blue-100 text-blue-700', created: 'bg-green-100 text-green-700', unchanged: 'bg-neutral-100 text-neutral-600', cancelled: 'bg-orange-100 text-orange-700', rejected: 'bg-red-100 text-red-700' } as Record<string, string>;

export const ingestUrl = () => `${window.location.origin}/api/ingest/v1`;

/** Format a JSON value as a PHP array literal for the Laravel example. */
function toPhp(value: unknown, indent = ''): string {
  const next = `${indent}    `;
  if (Array.isArray(value)) return `[\n${value.map(v => `${next}${toPhp(v, next)},`).join('\n')}\n${indent}]`;
  if (value && typeof value === 'object') {
    return `[\n${Object.entries(value).map(([k, v]) => `${next}'${k}' => ${toPhp(v, next)},`).join('\n')}\n${indent}]`;
  }
  if (typeof value === 'string') return `'${value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
  return String(value);
}

export function samplePayload(product: Pick<Product, 'invoicePrefix' | 'defaultSac'>) {
  const fy = (() => { const d = new Date(); const y = d.getMonth() >= 3 ? d.getFullYear() : d.getFullYear() - 1; return `${String(y).slice(2)}-${String(y + 1).slice(2)}`; })();
  return {
    external_id: 'order_10045',
    number: `${product.invoicePrefix || 'PFX'}/${fy}/0045`,
    date: new Date().toISOString().slice(0, 10),
    customer: { name: 'Ravi Kumar', company: 'Acme Pvt Ltd', gstin: '27AAPFU0939F1ZV', email: 'ravi@example.com', state_code: '27', country: 'India' },
    items: [{ description: 'Pro plan – annual subscription', sac: product.defaultSac || '998314', quantity: 1, rate: 9999, gst_rate: 18 }],
    total: 11798.82,
    payment: { date: new Date().toISOString().slice(0, 10), amount: 11798.82, method: 'upi', reference: 'pay_ABC123' },
  };
}

/** Generate/revoke a product's ingest API key, with copy-paste examples and its sync log. */
export default function ConnectProduct({ product, onClose, onChanged }: { product: Product; onClose: () => void; onChanged: () => void }) {
  const [current, setCurrent] = useState(product);
  const [key, setKey] = useState<string | null>(null);
  const [logs, setLogs] = useState<Log[]>([]);
  const [synced, setSynced] = useState({ invoices: 0, credit_notes: 0 });
  const [lang, setLang] = useState<'curl' | 'php' | 'node'>('curl');
  const [error, setError] = useState<string | null>(null);
  const [copied, setCopied] = useState('');

  const loadLogs = () => api(`/api/billing/products/${product.id}/logs`).then(d => { setLogs(d.logs); setSynced(d.synced); }).catch(e => setError(e.message));
  useEffect(() => { loadLogs(); }, [product.id]);

  const generate = async () => {
    if (current.isConnected && !confirm(`Replace ${current.name}'s API key? The old key stops working immediately.`)) return;
    try {
      const d = await api(`/api/billing/products/${product.id}/api-key`, 'POST');
      setKey(d.key); setCurrent(d.product); onChanged();
    } catch (e) { setError((e as Error).message); }
  };

  const revoke = async () => {
    if (!confirm(`Revoke ${current.name}'s API key? It will no longer be able to send invoices.`)) return;
    try {
      const d = await api(`/api/billing/products/${product.id}/api-key`, 'DELETE');
      setKey(null); setCurrent(d.product); onChanged();
    } catch (e) { setError((e as Error).message); }
  };

  const copy = (text: string, what: string) => {
    navigator.clipboard?.writeText(text).then(() => { setCopied(what); setTimeout(() => setCopied(''), 1500); }).catch(() => {});
  };

  const url = ingestUrl();
  const shownKey = key ?? '<API key>';
  const body = JSON.stringify(samplePayload(current), null, 2);
  const examples = {
    curl: `curl -X POST ${url}/invoices \\\n  -H "Authorization: Bearer ${shownKey}" \\\n  -H "Content-Type: application/json" \\\n  -d '${JSON.stringify(samplePayload(current))}'`,
    php: `// Laravel (Http client) – call right after the invoice is issued\n$res = Http::withToken(config('services.lalogix.key'))\n    ->acceptJson()\n    ->post('${url}/invoices', ${toPhp(samplePayload(current))});\n\n// 201 created · 200 unchanged (safe to retry) · 422 { error, errors }`,
    node: `// Node 18+ – call right after the invoice is issued\nconst res = await fetch('${url}/invoices', {\n  method: 'POST',\n  headers: { Authorization: 'Bearer ${shownKey}', 'Content-Type': 'application/json' },\n  body: JSON.stringify(${body}),\n});\n// 201 created · 200 unchanged (safe to retry) · 422 { error, errors }`,
  };

  return (
    <Modal title={`Connect ${current.name}`} onClose={onClose}>
      <div className="space-y-5 text-sm">
        <ErrorBanner message={error} onClose={() => setError(null)} />

        <div className="rounded-lg border border-neutral-200 p-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <div className="font-medium text-neutral-900">{current.isConnected ? 'Connected' : 'Not connected'}</div>
              <div className="text-xs text-neutral-500">
                {current.isConnected ? <>Key {current.apiKeyHint} · created {formatDate(current.apiKeyCreatedAt)} · last sync {current.lastSyncedAt ? new Date(current.lastSyncedAt).toLocaleString('en-IN') : 'never'}</> : 'Create a key and add it to the product so it can send its invoices here.'}
              </div>
              <div className="mt-1 text-xs text-neutral-500">Synced so far: {synced.invoices} invoices, {synced.credit_notes} credit notes</div>
            </div>
            <div className="flex gap-2">
              {current.isConnected && <button onClick={revoke} className={btnDanger}>Revoke</button>}
              <button onClick={generate} className={btnPrimary}>{current.isConnected ? 'New key' : 'Create API key'}</button>
            </div>
          </div>
          {key && (
            <div className="mt-3 rounded-lg border border-green-200 bg-green-50 p-3">
              <div className="mb-1 text-xs font-medium text-green-800">Copy this key now: it won't be shown again.</div>
              <div className="flex items-center gap-2">
                <code className="flex-1 break-all rounded bg-white px-2 py-1 font-mono text-xs">{key}</code>
                <button onClick={() => copy(key, 'key')} className={btnSecondary}>{copied === 'key' ? 'Copied' : 'Copy'}</button>
              </div>
            </div>
          )}
        </div>

        <SyncNow product={current} onSaved={p => { setCurrent(p); onChanged(); }} onSynced={loadLogs} />

        <div>
          <div className="mb-1 text-xs font-medium text-neutral-600">Endpoint</div>
          <div className="flex items-center gap-2">
            <code className="flex-1 rounded bg-neutral-100 px-2 py-1 font-mono text-xs">{url}</code>
            <button onClick={() => copy(url, 'url')} className={btnSecondary}>{copied === 'url' ? 'Copied' : 'Copy'}</button>
          </div>
          <ul className="mt-2 list-inside list-disc space-y-0.5 text-xs text-neutral-500">
            <li><code>POST /invoices</code>: send each invoice when it's issued (with <code>payment</code> if already paid)</li>
            <li><code>POST /credit-notes</code>: refund / adjustment, with <code>invoice_external_id</code> and <code>reason</code></li>
            <li><code>POST /invoices/&#123;external_id&#125;/cancel</code> and <code>/credit-notes/&#123;external_id&#125;/cancel</code></li>
            <li><code>GET /ping</code>: check the key works</li>
          </ul>
        </div>

        <div>
          <div className="mb-2 flex items-center justify-between">
            <div className="flex gap-1">
              {(['curl', 'php', 'node'] as const).map(l => (
                <button key={l} onClick={() => setLang(l)} className={`rounded px-2 py-1 text-xs font-medium ${lang === l ? 'bg-neutral-800 text-white' : 'bg-neutral-100 text-neutral-600'}`}>{l === 'php' ? 'PHP / Laravel' : l === 'node' ? 'Node.js' : 'curl'}</button>
              ))}
            </div>
            <button onClick={() => copy(examples[lang], 'code')} className="text-xs text-brand-600 hover:underline">{copied === 'code' ? 'Copied' : 'Copy code'}</button>
          </div>
          <pre className="max-h-64 overflow-auto rounded-lg bg-neutral-900 p-3 text-xs leading-relaxed text-neutral-100">{examples[lang]}</pre>
          <p className="mt-2 text-xs text-neutral-500">
            Keep the product's own invoice number (it must use this product's own prefix). GST is recalculated from the lines; if <code>total</code> differs by more than ₹1 the invoice is rejected with the reason.
            Resending the same <code>external_id</code> is safe.
          </p>
        </div>

        <div>
          <div className="mb-2 flex items-center justify-between">
            <div className="text-xs font-medium text-neutral-600">Recent activity</div>
            <button onClick={loadLogs} className="text-xs text-brand-600 hover:underline">Refresh</button>
          </div>
          <div className="max-h-56 overflow-auto rounded-lg border border-neutral-200">
            <table className="w-full text-xs">
              <tbody>
                {logs.length === 0 ? (
                  <tr><td className="px-3 py-4 text-center text-neutral-400">Nothing received yet.</td></tr>
                ) : logs.map(l => (
                  <tr key={l.id} className="border-b border-neutral-100 align-top">
                    <td className="whitespace-nowrap px-3 py-2 text-neutral-500">{new Date(l.createdAt).toLocaleString('en-IN')}</td>
                    <td className="px-3 py-2">{l.channel} · {l.action.replace('_', ' ')}</td>
                    <td className="px-3 py-2 font-mono">{l.number ?? l.externalId ?? '—'}</td>
                    <td className="px-3 py-2"><span className={`rounded-full px-2 py-0.5 font-medium ${OUTCOME[l.outcome] ?? ''}`}>{l.outcome}</span>{l.message && <div className={`mt-1 ${l.outcome === 'rejected' ? 'text-red-600' : 'text-neutral-500'}`}>{l.message}</div>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </Modal>
  );
}

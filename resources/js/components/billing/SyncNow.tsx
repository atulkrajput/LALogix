import { useState } from 'react';
import { api, btnPrimary, btnSecondary, inputCls, type Product } from '@/lib/billing';

interface Result { synced?: number; failed?: number; remaining?: number; configured?: boolean; message?: string }

/** Default sync endpoint for products built like VIVK.in (Next.js cron route). VIVK.in serves on www. */
const suggestUrl = (p: Product) => {
  if (!p.website) return '';
  const base = p.website.replace(/\/$/, '').replace(/^https?:\/\/vivk\.in$/i, 'https://www.vivk.in');
  return `${base}/api/cron/lalogix-sync`;
};

/**
 * "Sync now": LALogix calls the product's sync endpoint (the same as the manual curl), which
 * sends its not-yet-synced invoices through the ingest API. URL + secret are saved once;
 * the secret is stored encrypted and never shown again.
 */
export default function SyncNow({ product, onSaved, onSynced }: { product: Product; onSaved: (p: Product) => void; onSynced: () => void }) {
  const [url, setUrl] = useState(product.syncUrl || suggestUrl(product));
  const [secret, setSecret] = useState('');
  const [busy, setBusy] = useState<'' | 'save' | 'sync'>('');
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<Result | null>(null);
  const ready = !!product.syncUrl && !!product.hasSyncSecret;
  const dirty = url !== (product.syncUrl || '') || secret !== '';

  const save = async () => {
    setBusy('save'); setError(null);
    try {
      const d = await api<{ product: Product }>(`/api/billing/products/${product.id}/sync`, 'PUT', { sync_url: url || null, sync_secret: secret || null });
      setSecret('');
      onSaved(d.product);
    } catch (e) { setError((e as Error).message); } finally { setBusy(''); }
  };

  const run = async () => {
    setBusy('sync'); setError(null); setResult(null);
    try {
      const d = await api<{ result: Result }>(`/api/billing/products/${product.id}/sync`, 'POST');
      setResult(d.result);
    } catch (e) { setError((e as Error).message); } finally { setBusy(''); onSynced(); }
  };

  return (
    <div className="rounded-lg border border-neutral-200 p-4">
      <div className="mb-1 font-medium text-neutral-900">Sync now</div>
      <p className="mb-3 text-xs text-neutral-500">
        Asks {product.name} to send every invoice it hasn't sent yet (retries and back-fill), instead of waiting for its daily job.
        Uses the product's sync URL and its <code>CRON_SECRET</code>.
      </p>
      <div className="grid gap-2 sm:grid-cols-[1fr_14rem]">
        <input value={url} onChange={e => setUrl(e.target.value)} placeholder="https://www.example.com/api/cron/lalogix-sync" className={`${inputCls} font-mono text-xs`} aria-label="Sync URL" />
        <input type="password" value={secret} onChange={e => setSecret(e.target.value)} autoComplete="new-password"
          placeholder={product.hasSyncSecret ? 'Secret saved (type to replace)' : "Product's CRON_SECRET"} className={`${inputCls} text-xs`} aria-label="Sync secret" />
      </div>
      <div className="mt-3 flex flex-wrap items-center gap-2">
        <button onClick={save} disabled={!!busy || !dirty || !url} className={btnSecondary}>{busy === 'save' ? 'Saving…' : 'Save'}</button>
        <button onClick={run} disabled={!!busy || !ready || dirty} className={btnPrimary} title={!ready ? 'Save the URL and secret first' : dirty ? 'Save your changes first' : undefined}>
          {busy === 'sync' ? 'Syncing…' : 'Sync now'}
        </button>
        {result && (
          <span className="text-sm text-neutral-700">
            {result.configured === false
              ? <span className="text-orange-600">{result.message || `${product.name} has no LALogix key set yet.`}</span>
              : <>✓ {result.synced ?? 0} sent{result.failed ? <span className="text-red-600">, {result.failed} failed (see activity below)</span> : ''}{result.remaining ? `, ${result.remaining} still waiting: click again` : ', all caught up'}</>}
          </span>
        )}
      </div>
      {error && <div className="mt-2 text-sm text-red-600">{error}</div>}
    </div>
  );
}

import { useEffect, useRef, useState } from 'react';
import Icon from '@/components/ui/Icon';
import Link from '@/components/ui/Link';
import apiFetch from '@/lib/api';
import { submitLead } from '@/lib/leads';
import { INDIAN_MOBILE, useSite } from '@/lib/site';
import { usePathname } from '@/lib/navigation';
import { trackEvent } from '@/lib/analytics';

type Msg = { role: 'user' | 'assistant'; content: string };

/**
 * AI chat (bottom-right, above the WhatsApp button). Shown only when
 * CHAT_WIDGET_ENABLED=true. Messages go to POST /api/chat, which calls the
 * VIVK API on the server. After a few replies it asks for name + phone and
 * saves a lead with source_widget=chat.
 */
export default function ChatWidget() {
  const { chatAskAfter, chatName, products } = useSite();
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const [messages, setMessages] = useState<Msg[]>([
    { role: 'assistant', content: `Namaste! I'm ${chatName}. Ask me anything about LALogix products: what they do, who they are for, or how to try them.` },
  ]);
  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const [lead, setLead] = useState({ name: '', phone: '', website: '' });
  const [leadState, setLeadState] = useState<'hidden' | 'ask' | 'saving' | 'saved' | 'dismissed'>('hidden');
  const [leadError, setLeadError] = useState<string | null>(null);
  const listRef = useRef<HTMLDivElement>(null);
  const product = products.find((p) => pathname === `/products/${p.slug}`);

  const replies = messages.filter((m) => m.role === 'assistant').length - 1;

  useEffect(() => {
    if (replies >= chatAskAfter && leadState === 'hidden') setLeadState('ask');
  }, [replies, chatAskAfter, leadState]);

  useEffect(() => {
    listRef.current?.scrollTo({ top: listRef.current.scrollHeight });
  }, [messages, leadState, open]);

  const send = async (e: React.FormEvent) => {
    e.preventDefault();
    const text = input.trim().slice(0, 1000);
    if (!text || busy) return;
    const next: Msg[] = [...messages, { role: 'user', content: text }];
    setMessages(next);
    setInput('');
    setBusy(true);
    try {
      const res = await apiFetch('/api/chat', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        // The greeting is ours, not the model's; send the last 12 turns only.
        body: JSON.stringify({ messages: next.slice(1).slice(-12) }),
      });
      const data = await res.json().catch(() => null);
      const reply = res.ok && data?.reply ? String(data.reply) : res.status === 429 ? 'You are sending messages too fast. Please wait a minute.' : 'Sorry, something went wrong. Please try WhatsApp.';
      setMessages((m) => [...m, { role: 'assistant', content: reply }]);
    } catch {
      setMessages((m) => [...m, { role: 'assistant', content: 'Network error. Please try again or message us on WhatsApp.' }]);
    } finally {
      setBusy(false);
    }
  };

  const saveLead = async (e: React.FormEvent) => {
    e.preventDefault();
    if (lead.name.trim().length < 2) return setLeadError('Please enter your name.');
    if (!INDIAN_MOBILE.test(lead.phone.trim())) return setLeadError('Please enter a valid 10-digit Indian mobile number.');
    setLeadError(null);
    setLeadState('saving');
    const transcript = messages
      .slice(1)
      .map((m) => `${m.role === 'user' ? 'Visitor' : chatName}: ${m.content}`)
      .join('\n')
      .slice(-4000);
    const res = await submitLead({
      name: lead.name.trim(),
      phone: lead.phone.trim(),
      product: product?.lead_key,
      message: `Chat transcript:\n${transcript}`,
      source_widget: 'chat',
      consent: true,
      website: lead.website,
    });
    if (res.ok) {
      setLeadState('saved');
      trackEvent('chat_lead', { product: product?.name ?? 'general' });
    } else {
      setLeadState('ask');
      setLeadError(res.error ?? 'Something went wrong.');
    }
  };

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        aria-controls="lx-chat"
        aria-label={open ? 'Close chat' : `Chat with ${chatName}, our AI assistant`}
        className="fixed bottom-[5.5rem] right-5 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-neutral-900 text-white shadow-lg transition-transform hover:scale-105 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-2"
      >
        <Icon name={open ? 'x' : 'bot'} className="h-7 w-7" />
      </button>

      {open && (
        <div
          id="lx-chat"
          role="dialog"
          aria-label={`${chatName}, LALogix assistant`}
          className="fixed bottom-40 right-3 z-50 flex max-h-[min(70vh,560px)] w-[calc(100vw-1.5rem)] max-w-sm flex-col overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-2xl sm:right-5"
        >
          <div className="flex items-center justify-between bg-neutral-900 px-4 py-3 text-white">
            <p className="font-semibold text-white">
              {chatName} <span className="text-sm font-normal text-neutral-300">· LALogix assistant</span>
            </p>
            <button type="button" onClick={() => setOpen(false)} aria-label="Close chat" className="rounded p-1 hover:bg-neutral-800">
              <Icon name="x" className="h-5 w-5" />
            </button>
          </div>

          <div ref={listRef} className="flex-1 space-y-3 overflow-y-auto p-4" aria-live="polite">
            {messages.map((m, i) => (
              <p
                key={i}
                className={`max-w-[85%] whitespace-pre-line rounded-2xl px-3 py-2 text-sm ${
                  m.role === 'user' ? 'ml-auto bg-brand-700 text-white' : 'bg-neutral-100 text-neutral-900'
                }`}
              >
                {m.content}
              </p>
            ))}
            {busy && <p className="w-16 rounded-2xl bg-neutral-100 px-3 py-2 text-sm text-neutral-600">…</p>}

            {(leadState === 'ask' || leadState === 'saving') && (
              <form onSubmit={saveLead} className="space-y-2 rounded-xl border border-brand-200 bg-brand-50 p-3" noValidate>
                <p className="text-sm font-medium text-neutral-900">Want a person to follow up on WhatsApp? Leave your name and number.</p>
                <label className="sr-only" htmlFor="chat-name">
                  Name
                </label>
                <input id="chat-name" placeholder="Name" value={lead.name} onChange={(e) => setLead({ ...lead, name: e.target.value })} autoComplete="name" className="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm" />
                <label className="sr-only" htmlFor="chat-phone">
                  Mobile number
                </label>
                <input
                  id="chat-phone"
                  type="tel"
                  inputMode="tel"
                  placeholder="Mobile number"
                  value={lead.phone}
                  onChange={(e) => setLead({ ...lead, phone: e.target.value })}
                  autoComplete="tel"
                  className="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm"
                />
                <input value={lead.website} onChange={(e) => setLead({ ...lead, website: e.target.value })} tabIndex={-1} autoComplete="off" className="hidden" aria-hidden="true" />
                {leadError && (
                  <p className="text-xs text-red-700" role="alert">
                    {leadError}
                  </p>
                )}
                <p className="text-xs text-neutral-600">
                  See our <Link href="/privacy-policy">privacy policy</Link>.
                </p>
                <div className="flex gap-2">
                  <button type="submit" disabled={leadState === 'saving'} className="rounded-lg bg-brand-700 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-800">
                    {leadState === 'saving' ? 'Saving…' : 'Send'}
                  </button>
                  <button type="button" onClick={() => setLeadState('dismissed')} className="rounded-lg px-3 py-2 text-sm text-neutral-700 hover:bg-white">
                    Not now
                  </button>
                </div>
              </form>
            )}
            {leadState === 'saved' && (
              <p className="rounded-xl bg-green-50 p-3 text-sm text-green-900" role="status">
                Thanks! Someone from the team will WhatsApp you.
              </p>
            )}
          </div>

          <form onSubmit={send} className="flex gap-2 border-t border-neutral-200 p-3">
            <label htmlFor="chat-input" className="sr-only">
              Your question
            </label>
            <input
              id="chat-input"
              value={input}
              onChange={(e) => setInput(e.target.value)}
              maxLength={1000}
              placeholder="Type your question…"
              className="min-w-0 flex-1 rounded-lg border border-neutral-300 px-3 py-2 text-sm"
            />
            <button type="submit" disabled={busy || !input.trim()} aria-label="Send" className="rounded-lg bg-neutral-900 px-3 text-white disabled:opacity-50">
              <Icon name="send" className="h-4 w-4" />
            </button>
          </form>
        </div>
      )}
    </>
  );
}

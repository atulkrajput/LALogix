import apiFetch from '@/lib/api';
import { getAttribution } from '@/lib/attribution';

/** Which widget produced the lead (stored in leads.source_widget). */
export type SourceWidget = 'form' | 'quiz' | 'chat' | 'whatsapp' | 'exit';

export interface LeadPayload {
  name?: string;
  phone: string;
  email?: string;
  /** product_interest key from config/lalogix.php (lead_key), e.g. "dairy", "vivk". */
  product?: string;
  message?: string;
  business_type?: string;
  size?: string;
  city?: string;
  source_widget: SourceWidget;
  consent?: boolean;
  /** Honeypot: real visitors never fill it. */
  website?: string;
}

export interface LeadResult {
  ok: boolean;
  error?: string;
  errors?: Record<string, string[]>;
}

/**
 * Sends a lead to the existing lead backend (POST /api/leads) with the page URL
 * and the visitor's campaign attribution.
 */
export async function submitLead(payload: LeadPayload, endpoint = '/api/leads'): Promise<LeadResult> {
  try {
    const res = await apiFetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...payload, page_url: window.location.href.slice(0, 500), attribution: getAttribution() }),
    });
    if (res.ok) return { ok: true };
    const data = await res.json().catch(() => null);
    return { ok: false, error: data?.error || 'Something went wrong. Please try again.', errors: data?.errors };
  } catch {
    return { ok: false, error: 'Network error. Please check your connection and try again.' };
  }
}

/** Fire-and-forget event log (e.g. WhatsApp clicks); never blocks navigation. */
export function logLeadEvent(type: 'whatsapp_click', data: { product?: string; location?: string } = {}): void {
  try {
    const body = JSON.stringify({ type, ...data, page_url: window.location.href.slice(0, 500), attribution: getAttribution() });
    const token = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1];
    void fetch('/api/lead-events', {
      method: 'POST',
      keepalive: true,
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
      },
      body,
    }).catch(() => undefined);
  } catch {
    // Logging must never break the click.
  }
}

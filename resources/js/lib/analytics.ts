/**
 * Analytics events. Every event is pushed to the GTM dataLayer as
 * { event: name, ...params }; see TRACKING.md for the list and the GA4 setup.
 * When only GA (no GTM) is configured, gtag() receives the same event.
 */

declare global {
  interface Window {
    gtag?: (...args: unknown[]) => void;
    dataLayer?: Record<string, unknown>[];
    fbq?: (...args: unknown[]) => void;
  }
}

export type SiteEvent =
  | 'demo_form_submit'
  | 'whatsapp_click'
  | 'chat_lead'
  | 'quiz_complete'
  | 'try_demo_click'
  | 'exit_offer_submit'
  | 'book_demo_click'
  | 'notify_me_submit';

/** Events that count as a lead (also sent to the Meta Pixel as "Lead"). */
const LEAD_EVENTS: SiteEvent[] = ['demo_form_submit', 'chat_lead', 'quiz_complete', 'exit_offer_submit', 'notify_me_submit'];

export function trackEvent(eventName: SiteEvent, params: Record<string, unknown> = {}) {
  if (typeof window === 'undefined') return;

  window.dataLayer = window.dataLayer || [];
  window.dataLayer.push({ event: eventName, ...params });

  // GA4 without GTM (gtag.js loaded directly in app.blade.php).
  if (window.gtag && !document.querySelector('script[src*="googletagmanager.com/gtm.js"]')) {
    window.gtag('event', eventName, params);
  }

  if (window.fbq) {
    if (LEAD_EVENTS.includes(eventName)) window.fbq('track', 'Lead', { content_name: eventName, ...params });
    else window.fbq('trackCustom', eventName, params);
  }
}

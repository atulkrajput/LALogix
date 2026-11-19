/**
 * Ad attribution for website leads.
 *
 * When a visitor lands with campaign tags (utm_source, utm_campaign… or fbclid / gclid),
 * they are remembered in this browser for 90 days; the latest tagged visit wins. The lead
 * forms send them with the submission, so each lead records which ad brought it.
 * Without tags, an outside referrer (e.g. google.com) is remembered once.
 */
const KEY = 'lx_attribution';
const MAX_AGE_DAYS = 90;
const UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as const;

export interface Attribution {
  utm_source?: string;
  utm_medium?: string;
  utm_campaign?: string;
  utm_content?: string;
  utm_term?: string;
  click_id?: string;
  landing_page?: string;
  referrer?: string;
  captured_at?: string;
}

function read(): Attribution | null {
  try {
    const stored = JSON.parse(localStorage.getItem(KEY) || 'null') as Attribution | null;
    if (!stored?.captured_at) return null;
    const ageDays = (Date.now() - new Date(stored.captured_at).getTime()) / 86_400_000;
    return ageDays <= MAX_AGE_DAYS ? stored : null;
  } catch {
    return null;
  }
}

function write(value: Attribution) {
  try {
    localStorage.setItem(KEY, JSON.stringify(value));
  } catch {
    // Private mode / storage blocked: attribution is simply not kept.
  }
}

/** Call once on page load. */
export function captureAttribution(): void {
  if (typeof window === 'undefined') return;
  const params = new URLSearchParams(window.location.search);
  const tagged: Attribution = {};
  for (const key of UTM) {
    const v = params.get(key);
    if (v) tagged[key] = v.slice(0, 191);
  }
  const clickId = params.get('fbclid') || params.get('gclid') || params.get('msclkid');
  if (clickId) {
    tagged.click_id = clickId.slice(0, 255);
    // Click ids without UTM tags still tell us the platform.
    if (!tagged.utm_source) tagged.utm_source = params.get('fbclid') ? 'facebook' : params.get('gclid') ? 'google' : 'bing';
  }

  const externalReferrer = (() => {
    try {
      return document.referrer && new URL(document.referrer).host !== window.location.host ? document.referrer.slice(0, 500) : undefined;
    } catch {
      return undefined;
    }
  })();
  const landing = (window.location.pathname + window.location.search).slice(0, 500);

  if (Object.keys(tagged).length) {
    // First tagged visit also goes into a cookie (first touch, 90 days) that the server
    // reads when a form arrives without attribution (see App\Support\LeadAttribution).
    if (!document.cookie.split('; ').some((c) => c.startsWith('lx_utm='))) {
      const first = JSON.stringify({ ...tagged, landing_page: landing.slice(0, 200) });
      document.cookie = `lx_utm=${encodeURIComponent(first)}; Max-Age=${MAX_AGE_DAYS * 86400}; Path=/; SameSite=Lax${window.location.protocol === 'https:' ? '; Secure' : ''}`;
    }
    write({ ...tagged, landing_page: landing, referrer: externalReferrer, captured_at: new Date().toISOString() });
  } else if (externalReferrer && !read()) {
    write({ referrer: externalReferrer, landing_page: landing, captured_at: new Date().toISOString() });
  }
}

/** What to send with a lead form (empty object when nothing was captured). */
export function getAttribution(): Attribution {
  const a = read();
  if (!a) return {};
  const { captured_at: _ignored, ...rest } = a;
  return rest;
}

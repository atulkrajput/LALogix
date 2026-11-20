import { usePage } from '@inertiajs/react';

/**
 * Website content comes from config/lalogix.php (the one place to edit text),
 * shared with every page as the `site` prop by HandleInertiaRequests.
 * TODO placeholders are already removed on the server when SHOW_TODOS is off.
 */

export interface Company {
  name: string;
  short_name: string;
  legal_form: string;
  city: string;
  state: string;
  country: string;
  address: string;
  email: string;
  /** WhatsApp username (no "@"), or null while it is not configured. No phone number is ever shared. */
  whatsapp: string | null;
  whatsapp_handle: string | null;
  trust_line: string;
  reply_hours: string | null;
  working_days: string | null;
  social: Record<string, string>;
}

export interface ProductSummary {
  slug: string;
  name: string;
  category: string | null;
  icon: string;
  status: 'live' | 'coming_soon';
  /** LALogix's own app (e.g. AISHO), not a product sold to businesses. */
  in_house: boolean;
  lead_key: string;
  whatsapp_text: string;
}

export interface SiteProps {
  company: Company;
  products: ProductSummary[];
  showTodos: boolean;
  calendlyUrl: string | null;
  chatEnabled: boolean;
  chatAskAfter: number;
  chatName: string;
  exitOffer: { title: string | null; text: string | null };
}

export interface ProductLink {
  label: string;
  url: string | null;
  note?: string | null;
}

/** Product card data (home, /products, /pricing). */
export interface ProductCard {
  slug: string;
  name: string;
  brand: string | null;
  category: string | null;
  icon: string;
  status: 'live' | 'coming_soon';
  lead_key: string;
  promise: string | null;
  bullets: string[];
  price_hint: string | null;
  trial: string | null;
  demo: ProductLink | null;
  site_url: string | null;
  play_store_url: string | null;
  billed: boolean;
  /** false hides the Book Demo button (consumer apps). */
  book_demo?: boolean;
  screenshots: Screenshot[];
}

export interface Screenshot {
  src: string;
  alt: string;
  width: number;
  height: number;
}

export interface Faq {
  q: string;
  a: string | null;
}

/** Full product (product page). */
export interface Product extends ProductCard {
  in_house?: boolean;
  outcomes: { title: string; text: string | null }[];
  features: { title: string; text: string | null }[];
  who_for: string[];
  faq: Faq[];
  whatsapp_text: string;
}

const FALLBACK: SiteProps = {
  company: {
    name: 'LALogix Enterprises',
    short_name: 'LALogix',
    legal_form: '',
    city: 'Dehradun',
    state: 'Uttarakhand',
    country: 'India',
    address: 'Dehradun, Uttarakhand, India',
    email: 'info@lalogix.com',
    whatsapp: null,
    whatsapp_handle: null,
    trust_line: '',
    reply_hours: null,
    working_days: null,
    social: {},
  },
  products: [],
  showTodos: false,
  calendlyUrl: null,
  chatEnabled: false,
  chatAskAfter: 3,
  chatName: 'LAL Ji',
  exitOffer: { title: null, text: null },
};

export function useSite(): SiteProps {
  const { site } = usePage<{ site?: SiteProps | null }>().props;
  return site ?? FALLBACK;
}

/** wa.me link for the WhatsApp username; null (hide the button) while no username is set. */
export function whatsappUrl(username: string | null, text: string): string | null {
  return username ? `https://wa.me/${username}?text=${encodeURIComponent(text)}` : null;
}

/** Indian mobile: optional +91 / 91 / 0, then 10 digits starting 6–9. */
export const INDIAN_MOBILE = /^(?:\+?91[\s-]?|0)?[6-9]\d{4}[\s-]?\d{5}$/;

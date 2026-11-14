import apiFetch from '@/lib/api';

export interface StateOption { code: string; name: string }

export interface Product {
  id: number;
  name: string;
  slug?: string;
  kind: 'services' | 'saas' | 'ecommerce';
  website?: string | null;
  invoicePrefix: string | null;
  creditNotePrefix: string | null;
  defaultSac: string | null;
  defaultGstRate: number;
  isActive?: boolean;
  isConnected?: boolean;
  apiKeyHint?: string | null;
  apiKeyCreatedAt?: string | null;
  lastSyncedAt?: string | null;
  _count?: { invoices: number };
}

export interface Client {
  id: number;
  name: string;
  company: string | null;
  gstin: string | null;
  pan?: string | null;
  email: string | null;
  phone?: string | null;
  addressLine1?: string | null;
  addressLine2?: string | null;
  city?: string | null;
  stateCode: string | null;
  pincode?: string | null;
  country: string;
  notes?: string | null;
  outstanding?: number;
  _count?: { invoices: number; workItems: number };
}

export interface WorkItem {
  id: number;
  clientId: number;
  productId: number | null;
  title: string;
  description: string | null;
  sac: string | null;
  quantity: number;
  unit: string;
  rate: number;
  gstRate: number;
  workDate: string | null;
  invoiceId: number | null;
  client?: { id: number; name: string; company: string | null };
  product?: { id: number; name: string } | null;
  invoice?: { id: number; number: string | null; status: string } | null;
}

export interface InvoiceItem {
  id?: number;
  workItemId?: number | null;
  description: string;
  sac: string | null;
  quantity: number;
  unit: string;
  rate: number;
  discount: number;
  taxableValue?: number;
  gstRate: number;
  cgst?: number;
  sgst?: number;
  igst?: number;
  amount?: number;
}

export interface Payment {
  id: number;
  paymentDate: string;
  amount: number;
  tdsAmount: number;
  method: string;
  reference: string | null;
  notes: string | null;
}

export interface Invoice {
  id: number;
  type: 'invoice' | 'credit_note';
  source: 'manual' | 'api' | 'import';
  externalId: string | null;
  productId: number;
  clientId: number;
  originalInvoiceId: number | null;
  number: string | null;
  status: 'draft' | 'issued' | 'cancelled';
  paymentStatus: string;
  invoiceDate: string;
  dueDate: string | null;
  placeOfSupply: string;
  supplyType: 'intra' | 'inter' | 'export';
  exportWithLut: boolean;
  reverseCharge: boolean;
  billName: string;
  billCompany: string | null;
  billGstin: string | null;
  billAddress: string | null;
  billStateCode: string | null;
  billCountry: string;
  billEmail: string | null;
  taxableTotal: number;
  cgstTotal: number;
  sgstTotal: number;
  igstTotal: number;
  taxTotal: number;
  roundOff: number;
  grandTotal: number;
  amountPaid: number;
  amountCredited: number;
  balanceDue: number;
  notes: string | null;
  terms: string | null;
  reason: string | null;
  issuedAt: string | null;
  emailedAt: string | null;
  cancelledAt: string | null;
  cancelReason: string | null;
  product?: Product;
  client?: Client;
  items?: InvoiceItem[];
  payments?: Payment[];
  creditNotes?: { id: number; number: string | null; status: string; invoiceDate: string; grandTotal: number }[];
  originalInvoice?: { id: number; number: string; invoiceDate: string; grandTotal: number; amountCredited: number } | null;
}

/** JSON request that throws the API's { error } message on failure. */
export async function api<T = any>(url: string, method = 'GET', body?: unknown): Promise<T> {
  const res = await apiFetch(url, {
    method,
    headers: body !== undefined ? { 'Content-Type': 'application/json' } : undefined,
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || `Request failed (${res.status})`);
  return data as T;
}

const inr = new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
export const money = (v: number | string | null | undefined) => `₹${inr.format(Number(v || 0))}`;

export const formatDate = (d: string | null | undefined) =>
  d ? new Date(d.length === 10 ? `${d}T00:00:00` : d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

export const today = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

export const OUTSIDE_INDIA = '96';

export function stateName(states: StateOption[], code: string | null | undefined) {
  if (!code) return '';
  if (code === OUTSIDE_INDIA) return 'Outside India';
  return states.find(s => s.code === code)?.name ?? code;
}

export const STATUS_STYLES: Record<string, string> = {
  draft: 'bg-neutral-100 text-neutral-600',
  issued: 'bg-blue-100 text-blue-700',
  unpaid: 'bg-yellow-100 text-yellow-800',
  partial: 'bg-orange-100 text-orange-700',
  paid: 'bg-green-100 text-green-700',
  overdue: 'bg-red-100 text-red-700',
  cancelled: 'bg-neutral-200 text-neutral-500 line-through',
};

export const supplyTypeFor = (sellerState: string | null | undefined, pos: string) =>
  pos === OUTSIDE_INDIA ? 'export' : pos === sellerState ? 'intra' : 'inter';

/** Same maths as App\Services\Billing\InvoiceCalculator (paise), for the live preview. */
export function calculate(
  lines: Pick<InvoiceItem, 'quantity' | 'rate' | 'discount' | 'gstRate'>[],
  supplyType: string,
  exportWithLut: boolean,
  roundOff: boolean,
) {
  const sum = { taxable: 0, cgst: 0, sgst: 0, igst: 0 };
  const out = lines.map(l => {
    const taxable = Math.max(0, Math.round(Number(l.quantity || 0) * Number(l.rate || 0) * 100) - Math.round(Number(l.discount || 0) * 100));
    let cgst = 0, sgst = 0, igst = 0;
    const rate = Number(l.gstRate || 0);
    if (supplyType === 'intra') cgst = sgst = Math.round(taxable * rate / 200);
    else if (supplyType === 'inter' || (supplyType === 'export' && !exportWithLut)) igst = Math.round(taxable * rate / 100);
    sum.taxable += taxable; sum.cgst += cgst; sum.sgst += sgst; sum.igst += igst;
    return { taxable: taxable / 100, cgst: cgst / 100, sgst: sgst / 100, igst: igst / 100, amount: (taxable + cgst + sgst + igst) / 100 };
  });
  const gross = sum.taxable + sum.cgst + sum.sgst + sum.igst;
  const grand = roundOff ? Math.round(gross / 100) * 100 : gross;
  return {
    lines: out,
    taxable: sum.taxable / 100, cgst: sum.cgst / 100, sgst: sum.sgst / 100, igst: sum.igst / 100,
    roundOff: (grand - gross) / 100, grand: grand / 100,
  };
}

const fieldBase = 'rounded-lg border border-neutral-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500';
export const inputCls = `w-full ${fieldBase}`;
/** Toolbar filters: sized by their content instead of full width. */
export const filterCls = `bg-white ${fieldBase}`;
export const btnPrimary = 'rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50';
export const btnSecondary = 'rounded-lg border border-neutral-300 bg-white px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 disabled:opacity-50';
export const btnDanger = 'rounded-lg border border-red-200 bg-white px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50 disabled:opacity-50';

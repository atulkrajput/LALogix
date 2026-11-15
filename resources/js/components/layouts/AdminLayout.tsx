
import apiFetch from '@/lib/api';
import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { useRouter, usePathname } from '@/lib/navigation';
import Link from '@/components/ui/Link';

interface User {
  id: number;
  name: string;
  email: string;
  role: string;
}

export default function AdminLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  // Admin routes are protected server-side (auth middleware); the user is shared by HandleInertiaRequests.
  const user = usePage<{ auth: { user: User | null } }>().props.auth.user;
  const [sidebarOpen, setSidebarOpen] = useState(false);

  if (!user) return null;

  const navItems = [
    { href: '/admin/growth', label: 'Growth', icon: '📈' },
    { href: '/admin', label: 'Dashboard', icon: '📊' },
    { href: '/admin/leads', label: 'Leads', icon: '👥' },
    { href: '/admin/demos', label: 'Demos', icon: '📅' },
    { href: '/admin/marketing', label: 'Marketing', icon: '🎯' },
    { href: '/admin/campaigns', label: 'Campaigns', icon: '📣' },
    { href: '/admin/outreach', label: 'Outreach', icon: '📧' },
    { href: '/admin/followups', label: 'Follow-ups', icon: '🔔' },
    { href: '/admin/automation', label: 'Automation', icon: '⚡' },
    { href: '/admin/activities', label: 'Activities', icon: '📝' },
  ];
  const billingItems = [
    { href: '/admin/billing/invoices', label: 'Invoices', icon: '🧾' },
    { href: '/admin/billing/credit-notes', label: 'Credit Notes', icon: '↩️' },
    { href: '/admin/billing/purchases', label: 'Purchases', icon: '🛒' },
    { href: '/admin/billing/reports', label: 'GST Reports', icon: '📑' },
    { href: '/admin/billing/gstr2b', label: 'GSTR-2B Match', icon: '🔁' },
    { href: '/admin/billing/import', label: 'Import', icon: '⬆️' },
    { href: '/admin/billing/clients', label: 'Clients', icon: '🏢' },
    { href: '/admin/billing/work', label: 'Work Log', icon: '🛠️' },
    { href: '/admin/billing/products', label: 'Products', icon: '📦' },
    { href: '/admin/billing/settings', label: 'Business Profile', icon: '⚙️' },
  ];
  // Highlight the deepest matching section (e.g. /admin/billing/invoices/12 → Invoices).
  const active = [...navItems, ...billingItems]
    .filter(i => pathname === i.href || (i.href !== '/admin' && pathname.startsWith(`${i.href}/`)))
    .sort((a, b) => b.href.length - a.href.length)[0];

  const navLink = (item: { href: string; label: string; icon: string }) => (
    <Link
      key={item.href}
      href={item.href}
      onClick={() => setSidebarOpen(false)}
      className={`flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
        active?.href === item.href
          ? 'bg-brand-50 text-brand-700'
          : 'text-neutral-600 hover:bg-neutral-100'
      }`}
    >
      <span>{item.icon}</span>
      {item.label}
    </Link>
  );

  const handleLogout = async () => {
    await apiFetch('/api/auth/logout', { method: 'POST' });
    router.push('/admin/login');
  };

  return (
    <div className="flex h-screen bg-neutral-50">
      {/* Mobile overlay */}
      {sidebarOpen && <div className="fixed inset-0 z-30 bg-black/50 lg:hidden" onClick={() => setSidebarOpen(false)} />}

      {/* Sidebar */}
      <aside className={`fixed inset-y-0 left-0 z-40 w-64 transform bg-white border-r border-neutral-200 transition-transform lg:static lg:translate-x-0 ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'}`}>
        <div className="flex h-16 items-center gap-2 border-b border-neutral-200 px-6">
          <span className="text-xl font-bold text-brand-600">LALogix</span>
          <span className="rounded bg-brand-100 px-2 py-0.5 text-xs font-medium text-brand-700">CRM</span>
        </div>
        <nav className="absolute bottom-28 left-0 right-0 top-16 space-y-1 overflow-y-auto px-3 pt-4">
          {navItems.map(navLink)}
          <div className="px-3 pb-1 pt-4 text-xs font-semibold uppercase tracking-wide text-neutral-400">Billing</div>
          {billingItems.map(navLink)}
        </nav>
        <div className="absolute bottom-0 left-0 right-0 border-t border-neutral-200 p-4">
          <div className="text-sm text-neutral-600">{user.name}</div>
          <div className="text-xs text-neutral-400">{user.email}</div>
          <button onClick={handleLogout} className="mt-2 text-sm text-error-500 hover:underline">Logout</button>
        </div>
      </aside>

      {/* Main content */}
      <div className="flex flex-1 flex-col overflow-hidden">
        <header className="flex h-16 items-center gap-4 border-b border-neutral-200 bg-white px-6">
          <button onClick={() => setSidebarOpen(true)} className="lg:hidden" aria-label="Open sidebar">
            <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" /></svg>
          </button>
          <h1 className="text-lg font-semibold text-neutral-900">
            {active?.label || 'Admin'}
          </h1>
        </header>
        <main className="flex-1 overflow-y-auto p-6">{children}</main>
      </div>
    </div>
  );
}

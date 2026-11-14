import type { Metadata } from 'next';
import { Inter } from 'next/font/google';
import { SpeedInsights } from '@vercel/speed-insights/next';
import '@/styles/globals.css';
import AnalyticsProvider from '@/components/analytics/AnalyticsProvider';
import SiteShell from '@/components/layouts/SiteShell';

const inter = Inter({
  subsets: ['latin'],
  weight: ['400', '500', '600', '700'],
  variable: '--font-inter',
  display: 'swap',
});

export const metadata: Metadata = {
  metadataBase: new URL('https://lalogix.com'),
  title: {
    default: 'LALogix Enterprises - Automation Software for Schools, Retail & Dairy',
    template: '%s | LALogix Enterprises Automation Software',
  },
  description:
    'Automation software for schools and businesses. Manage operations with School ERP, Inventory POS, and Dairy Management systems.',
  keywords: [
    'automation software',
    'school management software',
    'school ERP software',
    'inventory POS',
    'dairy management software',
    'business automation India',
  ],
  authors: [{ name: 'LALogix Enterprises' }],
  creator: 'LALogix Enterprises',
  publisher: 'LALogix Enterprises',
  openGraph: {
    type: 'website',
    locale: 'en_IN',
    url: 'https://lalogix.com',
    siteName: 'LALogix Enterprises',
    title: 'LALogix Enterprises - Automation Software for Schools, Retail & Dairy',
    description:
      'Automation software for schools and businesses. Manage operations with School ERP, Inventory POS, and Dairy Management systems.',
    images: [
      {
        url: '/images/og/home.png',
        width: 1200,
        height: 630,
        alt: 'LALogix Enterprises Automation Software',
      },
    ],
  },
  twitter: {
    card: 'summary_large_image',
    site: '@lalogix',
    creator: '@lalogix',
    title: 'LALogix Enterprises - Automation Software for Schools, Retail & Dairy',
    description:
      'Automation software for schools and businesses. Manage operations with School ERP, Inventory POS, and Dairy Management systems.',
    images: ['/images/og/home.png'],
  },
  robots: {
    index: true,
    follow: true,
    googleBot: {
      index: true,
      follow: true,
      'max-video-preview': -1,
      'max-image-preview': 'large',
      'max-snippet': -1,
    },
  },
  alternates: {
    canonical: 'https://lalogix.com',
  },
  icons: {
    icon: '/icon.png',
    shortcut: '/icon.png',
    apple: '/icon.png',
  },
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en" className={inter.variable}>
      <body className="font-sans antialiased">
        <AnalyticsProvider />
        <SiteShell>{children}</SiteShell>
        <SpeedInsights />
      </body>
    </html>
  );
}

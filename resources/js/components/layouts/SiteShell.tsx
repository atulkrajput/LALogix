import { lazy, Suspense, useEffect, useState } from 'react';
import Navbar from './Navbar';
import Footer from './Footer';
import WhatsAppFloat from '@/components/ui/WhatsAppFloat';
import { useSite } from '@/lib/site';

// Loaded in the browser only, after the page is interactive.
const ChatWidget = lazy(() => import('@/components/widgets/ChatWidget'));
const ExitIntent = lazy(() => import('@/components/widgets/ExitIntent'));

/** Public website frame: header, page (each page renders its own <main>), footer and floating widgets. */
export default function SiteShell({ children }: { children: React.ReactNode }) {
  const { chatEnabled } = useSite();
  const [mounted, setMounted] = useState(false);
  useEffect(() => setMounted(true), []);

  return (
    <>
      <a
        href="#main-content"
        className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus:rounded focus:bg-white focus:px-4 focus:py-2 focus:shadow"
      >
        Skip to content
      </a>
      <Navbar />
      <div id="main-content" className="pt-16">
        {children}
      </div>
      <Footer />
      <WhatsAppFloat />
      {mounted && (
        <Suspense fallback={null}>
          {chatEnabled && <ChatWidget />}
          <ExitIntent />
        </Suspense>
      )}
    </>
  );
}

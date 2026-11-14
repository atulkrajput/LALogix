import type { SEOMetadata } from '@/types';

interface PageLayoutProps {
  children: React.ReactNode;
  /**
   * Kept for compatibility. SEO tags and JSON-LD are now rendered server-side
   * by Laravel (app/Support/Seo.php → resources/views/app.blade.php).
   */
  seo?: SEOMetadata;
}

export default function PageLayout({ children }: PageLayoutProps) {
  return <>{children}</>;
}

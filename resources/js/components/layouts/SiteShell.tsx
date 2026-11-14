
import { usePathname } from '@/lib/navigation';
import Navbar from './Navbar';
import Footer from './Footer';
import WhatsAppFloat from '@/components/ui/WhatsAppFloat';

export default function SiteShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const isAdmin = pathname.startsWith('/admin');

  if (isAdmin) return <>{children}</>;

  return (
    <>
      <Navbar />
      <main>{children}</main>
      <Footer />
      <WhatsAppFloat />
    </>
  );
}

import { usePathname } from '@/lib/navigation';
import { useSite, whatsappUrl } from '@/lib/site';
import { WhatsAppIcon } from '@/components/ui/Icon';
import { trackEvent } from '@/lib/analytics';
import { logLeadEvent } from '@/lib/leads';

/**
 * Floating WhatsApp button on every page. On a product page the prefilled
 * message names that product; each click is logged as a lead event.
 */
export default function WhatsAppFloat() {
  const { company, products } = useSite();
  const pathname = usePathname();

  const product = products.find((p) => pathname === `/products/${p.slug}`);
  const message = product
    ? product.whatsapp_text
    : pathname === '/book-demo'
      ? "Hi, I'd like to book a free demo of a LALogix product."
      : pathname === '/pricing'
        ? "Hi, I'd like to know the pricing of LALogix products."
        : `Hi, I'm on lalogix.com (${pathname}) and I'm interested in LALogix products.`;

  const onClick = () => {
    trackEvent('whatsapp_click', { location: 'floating', product: product?.name ?? 'general' });
    logLeadEvent('whatsapp_click', { product: product?.lead_key, location: 'floating' });
  };

  return (
    <a
      href={whatsappUrl(company.whatsapp, message)}
      target="_blank"
      rel="noopener noreferrer"
      onClick={onClick}
      aria-label={product ? `Ask about ${product.name} on WhatsApp` : 'Chat with LALogix on WhatsApp'}
      className="fixed bottom-5 right-5 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-[#128C4B] text-white shadow-lg transition-transform hover:scale-105 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#128C4B] focus-visible:ring-offset-2"
    >
      <WhatsAppIcon className="h-7 w-7" />
    </a>
  );
}

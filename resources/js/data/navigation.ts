/**
 * Site navigation. Product names and contact details come from
 * config/lalogix.php (see lib/site.ts); this file only holds page links.
 */
export const logo = {
  src: '/images/logo.webp',
  alt: 'LALogix',
  width: 396,
  height: 132,
};

/** Top navigation after the "Products" dropdown. */
export const mainLinks = [
  { label: 'Industries', href: '/industries' },
  { label: 'Pricing', href: '/pricing' },
  { label: 'Insights', href: '/insights' },
  { label: 'About', href: '/about' },
];

export const ctaButton = {
  label: 'Book a Free Demo',
  href: '/book-demo',
};

export const companyLinks = [
  { label: 'All products', href: '/products' },
  { label: 'Industries', href: '/industries' },
  { label: 'Pricing', href: '/pricing' },
  { label: 'Insights', href: '/insights' },
  { label: 'About', href: '/about' },
  { label: 'Contact', href: '/contact' },
  { label: 'Book a Free Demo', href: '/book-demo' },
];

export const legalLinks = [
  { label: 'Privacy Policy', href: '/privacy-policy' },
  { label: 'Terms & Conditions', href: '/terms-and-conditions' },
  { label: 'Refund Policy', href: '/refund-policy' },
  { label: 'Shipping Policy', href: '/shipping-policy' },
];

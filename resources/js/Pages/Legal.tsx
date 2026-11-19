import Link from '@/components/ui/Link';
import { legalLinks } from '@/data/navigation';
import { usePathname } from '@/lib/navigation';

interface Props {
  heading: string;
  html: string;
  updated: string;
}

/** Privacy / terms / refund / shipping policies (content/legal/*.md, rendered by the server). */
export default function Legal({ heading, html, updated }: Props) {
  const pathname = usePathname();

  return (
    <main className="bg-white">
      <div className="mx-auto max-w-3xl px-4 py-12 sm:px-6 md:py-16">
        <nav aria-label="Policies" className="mb-8 flex flex-wrap gap-2">
          {legalLinks.map((l) => (
            <Link
              key={l.href}
              href={l.href}
              aria-current={pathname === l.href ? 'page' : undefined}
              className={`rounded-full px-3 py-1 text-sm ${
                pathname === l.href ? 'bg-neutral-900 text-white hover:text-white' : 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200 hover:text-neutral-900'
              }`}
            >
              {l.label}
            </Link>
          ))}
        </nav>
        <h1 className="text-3xl font-bold text-neutral-900 md:text-4xl">{heading}</h1>
        <p className="mt-2 text-sm text-neutral-500">Last updated: {updated}</p>
        <div className="rich-text mt-8" dangerouslySetInnerHTML={{ __html: html }} />
      </div>
    </main>
  );
}

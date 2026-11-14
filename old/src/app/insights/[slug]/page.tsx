import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import Image from 'next/image';
import Link from 'next/link';
import PageLayout from '@/components/layouts/PageLayout';
import Button from '@/components/ui/Button';
import { getPostBySlug, getAllSlugs, getAllPosts } from '@/lib/blog';
import { buildBlogPostingJsonLd, buildBreadcrumbJsonLd } from '@/data/seo';
import BlogContent from '../blog/BlogContent';

const SITE_URL = 'https://lalogix.com';

interface InsightsPostPageProps {
  params: Promise<{ slug: string }>;
}

export async function generateStaticParams() {
  const slugs = getAllSlugs();
  return slugs.map((slug) => ({ slug }));
}

export async function generateMetadata({ params }: InsightsPostPageProps): Promise<Metadata> {
  const { slug } = await params;
  const post = getPostBySlug(slug);
  if (!post) return {};
  return {
    title: `${post.title} | LALogix Insights`,
    description: post.excerpt,
    openGraph: {
      title: post.title,
      description: post.excerpt,
      images: post.featuredImage ? [post.featuredImage] : [],
      type: 'article',
      publishedTime: post.date,
      authors: [post.author],
    },
    twitter: {
      card: 'summary_large_image',
      title: post.title,
      description: post.excerpt,
      images: post.featuredImage ? [post.featuredImage] : [],
    },
    alternates: {
      canonical: `${SITE_URL}/insights/${slug}`,
    },
  };
}

function estimateReadingTime(content: string): number {
  const words = content.trim().split(/\s+/).length;
  return Math.max(1, Math.ceil(words / 220));
}

export default async function InsightsPostPage({ params }: InsightsPostPageProps) {
  const { slug } = await params;
  const post = getPostBySlug(slug);
  if (!post) return notFound();

  const seo = {
    title: `${post.title} | LALogix Insights`,
    description: post.excerpt,
    canonical: `${SITE_URL}/insights/${slug}`,
    jsonLd: [
      buildBlogPostingJsonLd({
        title: post.title,
        description: post.excerpt,
        url: `${SITE_URL}/insights/${slug}`,
        datePublished: post.date,
        author: post.author,
        image: post.featuredImage,
      }),
      buildBreadcrumbJsonLd([
        { name: 'Home', url: SITE_URL },
        { name: 'Insights', url: `${SITE_URL}/insights` },
        { name: post.title, url: `${SITE_URL}/insights/${slug}` },
      ]),
    ],
  };

  const htmlContent = markdownToHtml(post.content);
  const headings = extractHeadings(post.content);
  const readingTime = estimateReadingTime(post.content);
  const formattedDate = new Date(post.date).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  });

  const allPosts = getAllPosts();
  const relatedPosts = allPosts
    .filter((p) => p.slug !== slug && p.tags?.some((t) => post.tags?.includes(t)))
    .slice(0, 3);

  const shareUrl = `${SITE_URL}/insights/${slug}`;

  return (
    <PageLayout seo={seo}>
      <BlogContent>
        <article>
          <div className="mx-auto max-w-4xl px-4 pt-24 md:pt-28 sm:px-6 lg:px-8">
            <nav aria-label="Breadcrumb" className="flex items-center gap-2 text-sm text-neutral-400">
              <Link href="/" className="hover:text-brand-600 transition-colors">Home</Link>
              <span>/</span>
              <Link href="/insights" className="hover:text-brand-600 transition-colors">Insights</Link>
              <span>/</span>
              <span className="text-neutral-600 truncate max-w-[200px]">{post.title}</span>
            </nav>
          </div>

          <header className="mx-auto max-w-4xl px-4 pb-8 pt-10 sm:px-6 lg:px-8">
            {post.tags && post.tags.length > 0 && (
              <div className="mb-5 flex flex-wrap gap-2">
                {post.tags.map((tag) => (
                  <span
                    key={tag}
                    className="rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-brand-600 ring-1 ring-brand-200/60"
                  >
                    {tag}
                  </span>
                ))}
              </div>
            )}

            <h1 className="text-3xl font-extrabold leading-tight tracking-tight text-neutral-900 sm:text-4xl lg:text-[2.75rem] lg:leading-[1.15]">
              {post.title}
            </h1>

            <p className="mt-4 text-lg leading-relaxed text-neutral-500">
              {post.excerpt}
            </p>

            <div className="mt-6 flex flex-wrap items-center gap-x-5 gap-y-3 border-b border-neutral-200 pb-6">
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-brand-700 text-sm font-bold text-white">
                  {post.author.split(' ').map((n) => n[0]).join('').slice(0, 2)}
                </div>
                <div>
                  <p className="text-sm font-semibold text-neutral-900">{post.author}</p>
                  <p className="text-xs text-neutral-400">
                    <time dateTime={post.date}>{formattedDate}</time>
                  </p>
                </div>
              </div>

              <span className="hidden sm:block h-5 w-px bg-neutral-200" />

              <span className="flex items-center gap-1.5 text-sm text-neutral-400">
                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z" />
                </svg>
                {readingTime} min read
              </span>

              <div className="ml-auto flex items-center gap-2">
                <span className="text-xs font-medium uppercase tracking-wider text-neutral-400">Share</span>
                <a
                  href={`https://twitter.com/intent/tweet?url=${encodeURIComponent(shareUrl)}&text=${encodeURIComponent(post.title)}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="flex h-8 w-8 items-center justify-center rounded-full bg-neutral-100 text-neutral-500 transition-colors hover:bg-brand-50 hover:text-brand-600"
                  aria-label="Share on Twitter"
                >
                  <svg className="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                </a>
                <a
                  href={`https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(shareUrl)}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="flex h-8 w-8 items-center justify-center rounded-full bg-neutral-100 text-neutral-500 transition-colors hover:bg-brand-50 hover:text-brand-600"
                  aria-label="Share on LinkedIn"
                >
                  <svg className="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
                </a>
                <a
                  href={`https://wa.me/?text=${encodeURIComponent(post.title + ' ' + shareUrl)}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="flex h-8 w-8 items-center justify-center rounded-full bg-neutral-100 text-neutral-500 transition-colors hover:bg-green-50 hover:text-green-600"
                  aria-label="Share on WhatsApp"
                >
                  <svg className="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                </a>
              </div>
            </div>
          </header>

          {post.featuredImage && (
            <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
              <div className="relative aspect-[21/9] overflow-hidden rounded-2xl bg-neutral-100 shadow-lg ring-1 ring-neutral-900/5">
                <Image
                  src={post.featuredImage}
                  alt={`${post.title} - featured image`}
                  fill
                  className="object-cover"
                  priority
                  sizes="(max-width: 1024px) 100vw, 896px"
                />
              </div>
            </div>
          )}

          <div className="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
            <div className="lg:grid lg:grid-cols-[1fr_220px] lg:gap-12">
              <div className="min-w-0">
                <div
                  className="
                    prose prose-lg prose-neutral max-w-none
                    prose-headings:scroll-mt-24 prose-headings:font-extrabold prose-headings:tracking-tight
                    prose-h2:mt-14 prose-h2:mb-5 prose-h2:text-2xl prose-h2:border-b prose-h2:border-neutral-200 prose-h2:pb-3
                    prose-p:leading-8 prose-p:text-neutral-700
                    prose-a:text-brand-600 prose-a:no-underline hover:prose-a:text-brand-700
                    prose-strong:text-neutral-900 prose-blockquote:border-brand-200 prose-blockquote:text-neutral-700
                    prose-ol:mt-5 prose-ul:mt-5 prose-li:leading-7 prose-li:text-neutral-700
                  "
                  dangerouslySetInnerHTML={{ __html: htmlContent }}
                />
              </div>

              <aside className="mt-10 lg:mt-0">
                <div className="sticky top-24 rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm">
                  <h3 className="text-sm font-semibold uppercase tracking-[0.12em] text-neutral-500">On this page</h3>
                  <ul className="mt-4 space-y-2 text-sm text-neutral-600">
                    {headings.map((heading) => (
                      <li key={heading.id} className={heading.depth === 2 ? 'ml-0' : 'ml-3'}>
                        <a href={`#${heading.id}`} className="hover:text-brand-600 transition-colors">
                          {heading.text}
                        </a>
                      </li>
                    ))}
                  </ul>
                </div>
              </aside>
            </div>
          </div>
        </article>

        <div className="mx-auto max-w-5xl px-4 pb-16 sm:px-6 lg:px-8">
          {relatedPosts.length > 0 && (
            <div className="mt-8">
              <h2 className="text-2xl font-bold tracking-tight text-neutral-900">Related Insights</h2>
              <div className="mt-6 grid gap-6 md:grid-cols-3">
                {relatedPosts.map((rp) => (
                  <Link key={rp.slug} href={`/insights/${rp.slug}`} className="group block">
                    <Card hover className="h-full">
                      <div className="mb-4 overflow-hidden rounded-lg">
                        <Image
                          src={rp.featuredImage}
                          alt={rp.title}
                          width={600}
                          height={300}
                          className="h-40 w-full object-cover transition-transform duration-300 group-hover:scale-105"
                        />
                      </div>
                      <p className="text-sm text-neutral-500">{new Date(rp.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</p>
                      <h3 className="mt-2 text-lg font-semibold text-neutral-900">{rp.title}</h3>
                    </Card>
                  </Link>
                ))}
              </div>
            </div>
          )}

          <div className="mt-12 flex justify-center">
            <Button href="/insights" className="group inline-flex items-center gap-2 text-sm font-medium text-brand-600 hover:text-brand-800 transition-colors">
              <span>←</span>
              Back to Insights
            </Button>
          </div>
        </div>
      </BlogContent>
    </PageLayout>
  );
}

function markdownToHtml(markdown: string): string {
  const escaped = markdown
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

  return escaped
    .replace(/^### (.*)$/gm, '<h3>$1</h3>')
    .replace(/^## (.*)$/gm, '<h2>$1</h2>')
    .replace(/^# (.*)$/gm, '<h1>$1</h1>')
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.*?)\*/g, '<em>$1</em>')
    .replace(/`(.*?)`/g, '<code>$1</code>')
    .replace(/\n\n/g, '</p><p>')
    .replace(/\n/g, '<br />');
}

function extractHeadings(markdown: string): Array<{ id: string; text: string; depth: number }> {
  const headings: Array<{ id: string; text: string; depth: number }> = [];
  const lines = markdown.split('\n');

  lines.forEach((line) => {
    const match = line.match(/^(#{1,3})\s+(.*)$/);
    if (!match) return;

    const depth = match[1].length;
    const text = match[2].trim();
    const id = text.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    headings.push({ id, text, depth });
  });

  return headings;
}

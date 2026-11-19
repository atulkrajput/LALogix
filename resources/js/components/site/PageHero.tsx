/** Simple page header used by inner pages. */
export default function PageHero({ title, subtitle, children }: { title: string; subtitle?: string; children?: React.ReactNode }) {
  return (
    <section className="bg-gradient-to-br from-brand-50 via-white to-brand-100">
      <div className="mx-auto max-w-4xl px-4 py-12 text-center sm:px-6 md:py-16">
        <h1 className="text-4xl font-bold tracking-tight text-neutral-900 md:text-5xl">{title}</h1>
        {subtitle && <p className="mx-auto mt-4 max-w-2xl text-lg text-neutral-700">{subtitle}</p>}
        {children && <div className="mt-6 flex flex-wrap justify-center gap-3">{children}</div>}
      </div>
    </section>
  );
}

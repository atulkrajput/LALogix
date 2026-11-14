<?php

namespace App\Support;

/**
 * Server-side SEO metadata (title, description, Open Graph, Twitter, canonical, JSON-LD).
 *
 * Ported from old/src/data/seo.ts, old/src/app/layout.tsx and the product pages'
 * generateMetadata(). Rendered into <head> by resources/views/app.blade.php so
 * crawlers and social previews get it without running JavaScript.
 */
class Seo
{
    public const TITLE_TEMPLATE = '%s | LALogix Enterprises Automation Software';

    public const DEFAULT_TITLE = 'LALogix Enterprises - Automation Software for Schools, Retail & Dairy';

    public const DEFAULT_DESCRIPTION = 'Automation software for schools and businesses. Manage operations with School ERP, Inventory POS, and Dairy Management systems.';

    public const DEFAULT_KEYWORDS = [
        'automation software', 'school management software', 'school ERP software',
        'inventory POS', 'dairy management software', 'business automation India',
    ];

    public static function siteUrl(): string
    {
        return config('lalogix.site_url');
    }

    public static function siteName(): string
    {
        return config('lalogix.site_name');
    }

    /** SEO for a named static page (keys match the old `pageSEO` object). */
    public static function page(string $key): array
    {
        $url = self::siteUrl();
        $name = self::siteName();

        $pages = [
            'home' => [
                'title' => 'LALogix Enterprises — Automation Software for Schools, Retail & Dairy',
                'description' => 'Automation software for schools and businesses. Manage operations with School ERP, Inventory POS, and Dairy Management systems. 15+ years of expertise.',
                'keywords' => [
                    'business automation software', 'school management software', 'school ERP software',
                    'inventory POS software', 'dairy management software', 'automation solutions India',
                    'school management system India',
                ],
                'ogImage' => '/images/og/home.png',
                'canonical' => $url,
                'jsonLd' => [[
                    '@context' => 'https://schema.org',
                    '@type' => 'Organization',
                    'name' => $name,
                    'url' => $url,
                    'logo' => "{$url}/logo.png",
                    'description' => 'LALogix is an automation software company building platforms for schools, retail businesses, and dairy farms.',
                    'address' => [
                        '@type' => 'PostalAddress',
                        'addressLocality' => 'Dehradun',
                        'addressRegion' => 'Uttarakhand',
                        'addressCountry' => 'IN',
                    ],
                    'contactPoint' => [
                        '@type' => 'ContactPoint',
                        'email' => 'info@lalogix.com',
                        'contactType' => 'sales',
                    ],
                    'sameAs' => [
                        'https://linkedin.com/company/lalogix',
                        'https://twitter.com/lalogix',
                        'https://facebook.com/lalogix',
                    ],
                ]],
            ],
            'solutions' => [
                'title' => 'Solutions — School, Retail & Dairy Software | LALogix Enterprises',
                'description' => 'Explore LALogix Enterprises automation solutions: School Management System, Inventory POS, Dairy Management, and custom automation services.',
                'keywords' => ['business automation solutions', 'school ERP', 'retail POS system', 'dairy farm software', 'custom automation'],
                'ogImage' => '/images/og/solutions.png',
                'canonical' => "{$url}/solutions",
                'jsonLd' => [self::breadcrumb([['Home', $url], ['Solutions', "{$url}/solutions"]])],
            ],
            'about' => [
                'title' => 'About LALogix Enterprises — 15+ Years of Automation Expertise',
                'description' => "Learn about LALogix Enterprises' mission, history, and team. Over 15 years of experience building automation software for schools, retail, and agriculture.",
                'keywords' => ['about LALogix Enterprises', 'automation software company', 'software company Dehradun', 'business automation India'],
                'ogImage' => '/images/og/about.png',
                'canonical' => "{$url}/about",
                'jsonLd' => [
                    [
                        '@context' => 'https://schema.org',
                        '@type' => 'Organization',
                        'name' => $name,
                        'url' => $url,
                        'logo' => "{$url}/logo.png",
                        'foundingDate' => '2008',
                        'description' => 'LALogix is an automation software company with 15+ years of experience building platforms for schools, retail businesses, and dairy farms.',
                        'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Dehradun', 'addressCountry' => 'IN'],
                    ],
                    self::breadcrumb([['Home', $url], ['About', "{$url}/about"]]),
                ],
            ],
            'blog' => [
                'title' => 'Insights on Business Automation | LALogix Enterprises',
                'description' => 'Read articles and insights on school management, retail automation, dairy farm technology, and business process optimization from LALogix Enterprises.',
                'keywords' => ['business automation blog', 'school management tips', 'retail technology insights', 'dairy farm automation'],
                'ogImage' => '/images/og/blog.png',
                'canonical' => "{$url}/insights",
                'jsonLd' => [self::breadcrumb([['Home', $url], ['Insights', "{$url}/insights"]])],
            ],
            'contact' => [
                'title' => 'Contact LALogix Enterprises — Get in Touch',
                'description' => "Contact LALogix Enterprises for inquiries about our automation software, custom solutions, or partnership opportunities. We're based in Dehradun, India.",
                'keywords' => ['contact LALogix Enterprises', 'automation software inquiry', 'business software consultation'],
                'ogImage' => '/images/og/contact.png',
                'canonical' => "{$url}/contact",
                'jsonLd' => [self::breadcrumb([['Home', $url], ['Contact', "{$url}/contact"]])],
            ],
            'bookDemo' => [
                'title' => 'Book a Demo — See LALogix Enterprises in Action',
                'description' => 'Schedule a free demo of LALogix Enterprises automation software. See how our school management, inventory POS, or dairy management systems can transform your operations.',
                'keywords' => ['book demo', 'free software demo', 'school management demo', 'POS software demo', 'dairy management demo'],
                'ogImage' => '/images/og/book-demo.png',
                'canonical' => "{$url}/book-demo",
                'jsonLd' => [self::breadcrumb([['Home', $url], ['Book a Demo', "{$url}/book-demo"]])],
            ],
            'customAutomation' => [
                'title' => 'Custom Automation Solutions | LALogix Enterprises',
                'description' => 'LALogix Enterprises builds custom automation solutions including business process automation, custom ERP development, workflow automation, and system integrations.',
                'keywords' => ['custom automation solutions', 'custom ERP development', 'business process automation', 'workflow automation India'],
                'ogImage' => '/images/og/custom-automation.png',
                'canonical' => "{$url}/custom-automation",
                'jsonLd' => [self::breadcrumb([['Home', $url], ['Custom Automation', "{$url}/custom-automation"]])],
            ],
            'thankYou' => [
                'title' => 'Thank You — LALogix',
                'description' => 'Thank you for your interest in LALogix. Our team will get back to you shortly.',
                'canonical' => "{$url}/thank-you",
                'noindex' => true,
            ],
            'notFound' => [
                'title' => 'Page Not Found',
                'description' => self::DEFAULT_DESCRIPTION,
                'noindex' => true,
            ],
        ];

        return $pages[$key] ?? [];
    }

    /** SEO for the three product pages (ported from old/src/data/products.ts + product pages). */
    public static function product(string $slug): ?array
    {
        $products = [
            'school-management' => [
                'name' => 'School Management System',
                'description' => 'An all-in-one school management platform that digitizes every aspect of school administration — from student enrollment and attendance to fee collection, exams, and parent communication.',
                'heroImage' => '/images/products/school-management-hero.png',
                'category' => 'EducationalApplication',
                'title' => 'School Management Software — School ERP System | LALogix',
                'seoDescription' => 'Best school management software in India. Complete school ERP with student management, attendance tracking, fee management, exams, parent app, and school automation system.',
                'keywords' => [
                    'school management software', 'school ERP software', 'school automation system',
                    'school management system India', 'student management system', 'school administration software',
                    'attendance tracking software', 'school fee management software',
                ],
                'ogImage' => '/images/og/school-management.png',
            ],
            'inventory-pos' => [
                'name' => 'Inventory POS System',
                'description' => 'A powerful point-of-sale and inventory management system designed for retail businesses of all sizes. Streamline billing, track stock in real-time, and grow your business with data-driven insights.',
                'heroImage' => '/images/products/inventory-pos-hero.png',
                'category' => 'BusinessApplication',
                'title' => 'Inventory POS Software — Retail Inventory Management | LALogix',
                'seoDescription' => 'Smart inventory management system and POS software for retail. Barcode scanning, stock tracking, purchase management, and sales reports for retail inventory software.',
                'keywords' => [
                    'inventory management system', 'POS software for retail', 'retail inventory software',
                    'inventory POS software', 'POS billing software', 'stock management software', 'retail POS system',
                ],
                'ogImage' => '/images/og/inventory-pos.png',
            ],
            'dairy-management' => [
                'name' => 'Dairy Management System',
                'description' => 'A comprehensive dairy management platform that digitizes farm operations — from cattle records and milk production to vendor purchases, quality testing, and financial reporting.',
                'heroImage' => '/images/products/dairy-management-hero.png',
                'category' => 'BusinessApplication',
                'title' => 'Dairy Farm Management Software — Cattle & Milk Management | LALogix',
                'seoDescription' => 'Complete dairy farm management software with cattle management, milk production management system, vendor tracking, fat/SNF calculations, and financial reports.',
                'keywords' => [
                    'dairy farm management software', 'milk production management system', 'cattle management software',
                    'dairy management software', 'dairy ERP', 'dairy farm software', 'milk tracking software',
                ],
                'ogImage' => '/images/og/dairy-management.png',
            ],
        ];

        if (! isset($products[$slug])) {
            return null;
        }

        $p = $products[$slug];
        $site = self::siteUrl();
        $url = "{$site}/products/{$slug}";

        return [
            'title' => $p['title'],
            'description' => $p['seoDescription'],
            'keywords' => $p['keywords'],
            'ogImage' => $p['ogImage'],
            'canonical' => $url,
            'jsonLd' => [
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'SoftwareApplication',
                    'name' => $p['name'],
                    'description' => $p['seoDescription'],
                    'url' => $url,
                    'image' => $p['heroImage'],
                    'applicationCategory' => $p['category'],
                    'operatingSystem' => 'Web',
                    'offers' => [
                        '@type' => 'Offer',
                        'price' => '0',
                        'priceCurrency' => 'INR',
                        'availability' => 'https://schema.org/InStock',
                        'description' => 'Contact for pricing',
                    ],
                    'publisher' => ['@type' => 'Organization', 'name' => self::siteName(), 'url' => $site],
                ],
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'Product',
                    'name' => $p['name'],
                    'description' => $p['description'],
                    'url' => $url,
                    'image' => $p['heroImage'],
                    'brand' => ['@type' => 'Organization', 'name' => self::siteName()],
                ],
                self::breadcrumb([['Home', $site], ['Solutions', "{$site}/solutions"], [$p['name'], $url]]),
            ],
        ];
    }

    /** SEO for a single insight post. */
    public static function post(array $post): array
    {
        $site = self::siteUrl();
        $url = "{$site}/insights/{$post['slug']}";

        return [
            'title' => "{$post['title']} | LALogix Insights",
            'description' => $post['excerpt'],
            'ogImage' => $post['featuredImage'] ?: null,
            'ogType' => 'article',
            'canonical' => $url,
            'jsonLd' => [
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'BlogPosting',
                    'headline' => $post['title'],
                    'description' => $post['excerpt'],
                    'url' => $url,
                    'datePublished' => $post['date'],
                    'author' => ['@type' => 'Person', 'name' => $post['author']],
                    'image' => $post['featuredImage'],
                    'publisher' => [
                        '@type' => 'Organization',
                        'name' => self::siteName(),
                        'logo' => ['@type' => 'ImageObject', 'url' => "{$site}/logo.png"],
                    ],
                ],
                self::breadcrumb([['Home', $site], ['Insights', "{$site}/insights"], [$post['title'], $url]]),
            ],
        ];
    }

    /** @param  array<int, array{0: string, 1: string}>  $items  [name, url] pairs */
    public static function breadcrumb(array $items): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn ($item, $i) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $item[0],
                'item' => $item[1],
            ], $items, array_keys($items)),
        ];
    }

    /** Fill in defaults and resolve the final <title>, absolute OG image URL, etc. */
    public static function resolve(?array $seo): array
    {
        $seo = $seo ?? [];
        $site = self::siteUrl();

        $title = isset($seo['title']) ? sprintf(self::TITLE_TEMPLATE, $seo['title']) : self::DEFAULT_TITLE;
        $ogImage = $seo['ogImage'] ?? '/images/og/home.png';
        if ($ogImage && ! str_starts_with($ogImage, 'http')) {
            $ogImage = $site.$ogImage;
        }

        return [
            'title' => $title,
            'ogTitle' => $seo['title'] ?? self::DEFAULT_TITLE,
            'description' => $seo['description'] ?? self::DEFAULT_DESCRIPTION,
            'keywords' => implode(', ', $seo['keywords'] ?? self::DEFAULT_KEYWORDS),
            'canonical' => $seo['canonical'] ?? $site,
            'ogImage' => $ogImage,
            'ogType' => $seo['ogType'] ?? 'website',
            'noindex' => $seo['noindex'] ?? false,
            'jsonLd' => $seo['jsonLd'] ?? [],
        ];
    }
}

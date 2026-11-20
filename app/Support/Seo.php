<?php

namespace App\Support;

/**
 * Server-side SEO metadata (title, description, Open Graph, Twitter, canonical, JSON-LD).
 *
 * Rendered into <head> by resources/views/app.blade.php so crawlers and social
 * previews get it without running JavaScript.
 *
 * Rules (checked by tests/Feature/SeoTest.php): every title is complete as
 * written (no suffix is appended) and under 60 characters; every indexable
 * page has its own 150–160 character description and its own OG image.
 */
class Seo
{
    public const DEFAULT_TITLE = 'LALogix: Ready-to-Use Software for Indian Businesses';

    public const DEFAULT_DESCRIPTION = 'LALogix builds ready-to-use cloud software for Indian schools, dairies and shops: AI chat, dairy accounts, school management and billing. Try any product free.';

    public static function siteUrl(): string
    {
        return config('lalogix.site_url');
    }

    public static function siteName(): string
    {
        return config('lalogix.site_name');
    }

    /** Static pages: key => [path, title, description, og image]. */
    public static function pages(): array
    {
        return [
            'home' => ['', 'LALogix: Ready-to-Use Software for Schools, Dairies & Shops',
                'Cloud apps Indian businesses switch on in a day: VIVK AI chat, AMRITIX dairy accounts, school management and billing with stock. Try any LALogix product free.',
                '/images/og/home.jpg'],
            'products' => ['/products', 'All LALogix Products: School, Dairy, POS and AI Chat',
                'Compare every LALogix business product: VIVK AI chat, AMRITIX dairy app, School Automation, Inventory & POS and DoonPortal. Try a live demo or book a free demo.',
                '/images/og/products.jpg'],
            'pricing' => ['/pricing', 'Pricing for LALogix Products in Indian Rupees',
                'Simple rupee pricing for every LALogix product, with GST invoices and one bill from LALogix Enterprises. See plans for VIVK, AMRITIX, school and POS software.',
                '/images/og/pricing.jpg'],
            'industries' => ['/industries', 'Software by Industry: Schools, Dairies, Shops | LALogix',
                'Find the LALogix product for your business: school management for schools, AMRITIX for dairies, Inventory & POS for shops, VIVK AI chat for customer replies.',
                '/images/og/industries.jpg'],
            'about' => ['/about', 'About LALogix Enterprises, Dehradun',
                'LALogix Enterprises is a Dehradun software company with 15+ years’ experience. We build and run ready-made cloud products for Indian schools, dairies and shops.',
                '/images/og/about.jpg'],
            'blog' => ['/insights', 'Insights: Guides for Schools, Dairies and Shops | LALogix',
                'Practical guides from LALogix on running schools, dairies and shops with software: fees and attendance, milk hisab, billing, stock and AI tools for small teams.',
                '/images/og/insights.jpg'],
            'contact' => ['/contact', 'Contact LALogix: WhatsApp, Phone and E-mail',
                'Talk to LALogix about any of our products on WhatsApp, phone or e-mail. We are based in Dehradun, Uttarakhand, and help businesses across India get started.',
                '/images/og/contact.jpg'],
            'bookDemo' => ['/book-demo', 'Book a Free Demo of Any LALogix Product',
                'Book a free demo of VIVK, AMRITIX, School Automation or Inventory & POS. Leave your name and mobile number and we will WhatsApp you to fix a time that suits.',
                '/images/og/book-demo.jpg'],
            'privacy' => ['/privacy-policy', 'Privacy Policy | LALogix Enterprises',
                'How LALogix Enterprises collects, uses and protects personal information on lalogix.com and in VIVK, AMRITIX, AISHO and our school and inventory software.',
                '/images/og/legal.jpg'],
            'terms' => ['/terms-and-conditions', 'Terms and Conditions | LALogix Enterprises',
                'Terms for using lalogix.com and subscribing to LALogix Enterprises products: accounts, trials, rupee pricing with GST invoices, acceptable use and liability.',
                '/images/og/legal.jpg'],
            'refund' => ['/refund-policy', 'Refund and Cancellation Policy | LALogix',
                'How to cancel a LALogix subscription and when you get a refund: duplicate payments, renewals after cancelling, services not delivered and early first payments.',
                '/images/og/legal.jpg'],
            'shipping' => ['/shipping-policy', 'Shipping and Delivery Policy | LALogix',
                'LALogix products are software delivered online, so nothing is shipped. Read how and when account access and GST invoices reach you after a payment is made.',
                '/images/og/legal.jpg'],
        ];
    }

    /** SEO for a named static page. */
    public static function page(string $key): array
    {
        $site = self::siteUrl();

        if ($key === 'thankYou') {
            return ['title' => 'Thank You | LALogix', 'description' => 'Thank you for contacting LALogix. We will get back to you shortly.', 'canonical' => "{$site}/thank-you", 'noindex' => true];
        }
        if ($key === 'notFound') {
            return ['title' => 'Page Not Found | LALogix', 'description' => self::DEFAULT_DESCRIPTION, 'noindex' => true];
        }

        $pages = self::pages();
        if (! isset($pages[$key])) {
            return [];
        }
        [$path, $title, $description, $og] = $pages[$key];
        $url = $site.$path;
        $label = trim(explode('|', $title)[0]);

        $jsonLd = $key === 'home'
            ? [self::organization(), self::website()]
            : [self::breadcrumb([['Home', $site], [$label, $url]])];
        if ($key === 'home') {
            $faq = array_values(array_filter(Site::clean(config('lalogix.home.faq')), fn ($f) => ! empty($f['q']) && ! empty($f['a'])));
            if ($faq) {
                $jsonLd[] = self::faqPage($faq);
            }
        }
        if ($key === 'about') {
            array_unshift($jsonLd, self::organization());
        }

        return [
            'title' => $title,
            'description' => $description,
            'ogImage' => $og,
            'canonical' => $url,
            'jsonLd' => $jsonLd,
        ];
    }

    public static function organization(): array
    {
        $site = self::siteUrl();
        $c = Site::company();

        return [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $c['name'],
            'url' => $site,
            'logo' => "{$site}/logo.png",
            'email' => $c['email'],
            'description' => 'LALogix Enterprises builds ready-to-use cloud software for Indian schools, dairies and shops.',
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => $c['city'],
                'addressRegion' => $c['state'],
                'addressCountry' => 'IN',
            ],
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'email' => $c['email'],
                'contactType' => 'sales',
                'areaServed' => 'IN',
                'availableLanguage' => ['en', 'hi'],
            ],
            'sameAs' => array_values($c['social'] ?? []),
        ];
    }

    private static function website(): array
    {
        return ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => self::siteName(), 'url' => self::siteUrl()];
    }

    /** SEO for a product page, from config/lalogix.php. */
    public static function product(string $slug): ?array
    {
        $p = Site::product($slug);
        if (! $p) {
            return null;
        }

        $site = self::siteUrl();
        $url = "{$site}/products/{$slug}";
        $image = $site."/images/og/{$slug}.jpg";

        $app = [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => $p['name'],
            'alternateName' => $p['brand'] ?: null,
            'description' => $p['seo']['description'],
            'url' => $url,
            'image' => $image,
            'applicationCategory' => $p['schema_category'],
            'operatingSystem' => $p['os'],
            'publisher' => ['@type' => 'Organization', 'name' => self::siteName(), 'url' => $site],
        ];
        // Only state a price when we actually have one; otherwise just availability.
        $app['offers'] = [
            '@type' => 'Offer',
            'priceCurrency' => 'INR',
            'availability' => $p['status'] === 'coming_soon' ? 'https://schema.org/PreOrder' : 'https://schema.org/InStock',
            'seller' => ['@type' => 'Organization', 'name' => self::siteName()],
        ];
        if (! empty($p['site_url'])) {
            $app['sameAs'] = $p['site_url'];
        }

        $faq = array_values(array_filter($p['faq'] ?? [], fn ($f) => ! empty($f['q']) && ! empty($f['a'])));

        return [
            'title' => $p['seo']['title'],
            'description' => $p['seo']['description'],
            'ogImage' => "/images/og/{$slug}.jpg",
            'canonical' => $url,
            'jsonLd' => array_values(array_filter([
                array_filter($app, fn ($v) => $v !== null),
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'Product',
                    'name' => $p['name'],
                    'description' => $p['promise'] ?: $p['seo']['description'],
                    'url' => $url,
                    'image' => $image,
                    'brand' => ['@type' => 'Brand', 'name' => $p['brand'] ?: $p['name']],
                    'manufacturer' => ['@type' => 'Organization', 'name' => self::siteName()],
                ],
                $faq ? self::faqPage($faq) : null,
                self::breadcrumb([['Home', $site], ['Products', "{$site}/products"], [$p['name'], $url]]),
            ])),
        ];
    }

    /** SEO for a single insight post. */
    public static function post(array $post): array
    {
        $site = self::siteUrl();
        $url = "{$site}/insights/{$post['slug']}";
        $title = self::fitTitle($post['title'], ' | LALogix Insights');

        return [
            'title' => $title,
            'description' => self::fitDescription($post['description'] ?? $post['excerpt'] ?? ''),
            'ogImage' => ($post['featuredImage'] ?? null) ?: '/images/og/insights.jpg',
            'ogType' => 'article',
            'canonical' => $url,
            'jsonLd' => [
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'BlogPosting',
                    'headline' => $post['title'],
                    'description' => $post['excerpt'] ?? '',
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

    /** Append the suffix only when the result stays under 60 characters; cut long titles at a word. */
    public static function fitTitle(string $title, string $suffix = ' | LALogix'): string
    {
        $title = trim($title);
        if (mb_strlen($title.$suffix) < 60) {
            return $title.$suffix;
        }
        if (mb_strlen($title) < 60) {
            return $title;
        }

        return rtrim(mb_substr($title, 0, mb_strrpos(mb_substr($title, 0, 58), ' ') ?: 58), ' ,:;-').'…';
    }

    /** Keep descriptions within 160 characters (cut at a word). */
    public static function fitDescription(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)));
        if ($text === '') {
            return self::DEFAULT_DESCRIPTION;
        }
        if (mb_strlen($text) <= 160) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, mb_strrpos(mb_substr($text, 0, 159), ' ') ?: 159), ' ,:;-').'…';
    }

    /** @param  array<int, array{q: string, a: string}>  $items */
    public static function faqPage(array $items): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn ($f) => [
                '@type' => 'Question',
                'name' => $f['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
            ], $items),
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

        $title = $seo['title'] ?? self::DEFAULT_TITLE;
        $ogImage = $seo['ogImage'] ?? '/images/og/home.jpg';
        if ($ogImage && ! str_starts_with($ogImage, 'http')) {
            $ogImage = $site.$ogImage;
        }

        return [
            'title' => $title,
            'ogTitle' => $seo['ogTitle'] ?? $title,
            'description' => $seo['description'] ?? self::DEFAULT_DESCRIPTION,
            'canonical' => $seo['canonical'] ?? $site,
            'ogImage' => $ogImage,
            'ogType' => $seo['ogType'] ?? 'website',
            'noindex' => $seo['noindex'] ?? false,
            'jsonLd' => $seo['jsonLd'] ?? [],
        ];
    }
}

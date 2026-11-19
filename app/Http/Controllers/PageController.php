<?php

namespace App\Http\Controllers;

use App\Services\BlogService;
use App\Support\Seo;
use App\Support\Site;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Public website pages. Each page is a React component in resources/js/Pages,
 * rendered through Inertia; SEO tags are rendered server-side in app.blade.php.
 */
class PageController extends Controller
{
    /** Policy pages: URL slug => [Markdown file in content/legal, SEO key, heading]. */
    public const LEGAL = [
        'privacy-policy' => ['privacy', 'Privacy Policy'],
        'terms-and-conditions' => ['terms', 'Terms and Conditions'],
        'refund-policy' => ['refund', 'Refund and Cancellation Policy'],
        'shipping-policy' => ['shipping', 'Shipping and Delivery Policy'],
    ];

    private function render(string $component, array $seo, array $props = []): InertiaResponse
    {
        $meta = Seo::resolve($seo);

        // `meta` lets the React side update <title> on client-side navigation.
        return Inertia::render($component, [...$props, 'meta' => ['title' => $meta['title']]])
            ->withViewData(['seo' => $meta]);
    }

    public function home(): InertiaResponse
    {
        return $this->render('Home', Seo::page('home'));
    }

    public function about(): InertiaResponse
    {
        return $this->render('About', Seo::page('about'));
    }

    public function contact(): InertiaResponse
    {
        return $this->render('Contact', Seo::page('contact'));
    }

    public function bookDemo(): InertiaResponse
    {
        return $this->render('BookDemo', Seo::page('bookDemo'));
    }

    public function thankYou(): InertiaResponse
    {
        return $this->render('ThankYou', Seo::page('thankYou'));
    }

    /** /products: every product as a card. */
    public function products(): InertiaResponse
    {
        return $this->render('Products/Index', Seo::page('products'), ['products' => Site::cards()]);
    }

    /** /products/{slug}: one template for every product, driven by config/lalogix.php. */
    public function product(string $slug): InertiaResponse
    {
        $product = Site::product($slug) ?? abort(404);

        $others = array_values(array_filter(Site::cards(), fn ($p) => $p['slug'] !== $slug && $p['status'] === 'live'));

        return $this->render('Products/Show', Seo::product($slug), [
            'product' => $product,
            'related' => array_slice($others, 0, 3),
        ]);
    }

    public function pricing(): InertiaResponse
    {
        return $this->render('Pricing', Seo::page('pricing'), ['products' => Site::cards()]);
    }

    public function industries(): InertiaResponse
    {
        return $this->render('Industries', Seo::page('industries'), [
            'industries' => Site::clean(config('lalogix.industries')),
            'products' => Site::cards(),
        ]);
    }

    /** Privacy, terms, refund and shipping policies (content/legal/*.md). */
    public function legal(string $slug): InertiaResponse
    {
        [$seoKey, $heading] = self::LEGAL[$slug] ?? abort(404);

        return $this->render('Legal', Seo::page($seoKey), [
            'heading' => $heading,
            'html' => self::legalHtml($slug),
            'updated' => Carbon::parse(config('lalogix.legal_updated'))->format('j F Y'),
        ]);
    }

    /** Markdown → HTML with the company details filled in from config/lalogix.php. */
    public static function legalHtml(string $slug): string
    {
        $c = Site::company();
        $billed = array_filter(Site::products(), fn ($p) => $p['billed'] ?? false);
        $names = array_map(fn ($p) => ($p['brand'] && ! Site::isTodo($p['brand']) ? $p['brand'] : $p['name']), $billed);
        $list = count($names) > 1 ? implode(', ', array_slice($names, 0, -1)).' and '.end($names) : implode('', $names);

        $markdown = strtr(File::get(base_path("content/legal/{$slug}.md")), [
            '{{company}}' => $c['name'],
            '{{legal_form}}' => strtolower($c['legal_form']),
            '{{address}}' => $c['address'],
            '{{email}}' => $c['email'],
            '{{phone}}' => $c['phone_display'],
            '{{site}}' => preg_replace('#^https?://#', '', Seo::siteUrl()),
            '{{products}}' => $list,
        ]);

        // Hide TODO notes on the public site (same rule as Site::clean, line by line).
        $lines = array_map(fn ($line) => Site::clean($line), preg_split('/\R/', $markdown));
        $markdown = implode("\n", array_filter($lines, fn ($l) => $l !== null));

        return Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    public function insights(BlogService $blog): InertiaResponse
    {
        // The list page does not need the full Markdown bodies.
        $posts = array_map(fn ($p) => array_diff_key($p, ['content' => true]), $blog->all());

        return $this->render('Insights/Index', Seo::page('blog'), ['posts' => $posts]);
    }

    public function insight(BlogService $blog, string $slug): InertiaResponse
    {
        $post = $blog->find($slug) ?? abort(404);

        $related = collect($blog->all())
            ->filter(fn ($p) => $p['slug'] !== $slug && array_intersect($p['tags'], $post['tags']))
            ->take(3)
            ->map(fn ($p) => array_diff_key($p, ['content' => true]))
            ->values()
            ->all();

        return $this->render('Insights/Show', Seo::post($post), [
            'post' => $post,
            'relatedPosts' => $related,
            'shareUrl' => Seo::siteUrl()."/insights/{$slug}",
        ]);
    }

    public function sitemap(BlogService $blog): Response
    {
        $site = Seo::siteUrl();
        $now = now()->toAtomString();

        $routes = [
            ['', 1, 'weekly'],
            ['/products', 0.9, 'monthly'],
            ['/pricing', 0.8, 'monthly'],
            ['/industries', 0.7, 'monthly'],
            ['/about', 0.7, 'monthly'],
            ['/insights', 0.8, 'weekly'],
            ['/contact', 0.7, 'monthly'],
            ['/book-demo', 0.85, 'monthly'],
        ];
        foreach (Site::slugs() as $slug) {
            $routes[] = ["/products/{$slug}", 0.9, 'monthly'];
        }
        foreach (array_keys(self::LEGAL) as $legal) {
            $routes[] = ["/{$legal}", 0.3, 'yearly'];
        }
        foreach ($blog->slugs() as $slug) {
            $routes[] = ["/insights/{$slug}", 0.6, 'monthly'];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($routes as [$path, $priority, $freq]) {
            $xml .= '  <url><loc>'.e($site.$path)."</loc><lastmod>{$now}</lastmod><changefreq>{$freq}</changefreq><priority>{$priority}</priority></url>\n";
        }
        $xml .= '</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }
}

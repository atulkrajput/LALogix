<?php

namespace App\Http\Controllers;

use App\Services\BlogService;
use App\Support\Seo;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Public website pages. Each page is a React component in resources/js/Pages,
 * rendered through Inertia; SEO tags are rendered server-side in app.blade.php.
 */
class PageController extends Controller
{
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

    public function solutions(): InertiaResponse
    {
        return $this->render('Solutions', Seo::page('solutions'));
    }

    public function about(): InertiaResponse
    {
        return $this->render('About', Seo::page('about'));
    }

    public function customAutomation(): InertiaResponse
    {
        return $this->render('CustomAutomation', Seo::page('customAutomation'));
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

    public function product(string $slug): InertiaResponse
    {
        $component = [
            'school-management' => 'Products/SchoolManagement',
            'inventory-pos' => 'Products/InventoryPos',
            'dairy-management' => 'Products/DairyManagement',
        ][$slug] ?? abort(404);

        return $this->render($component, Seo::product($slug));
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
            ['/solutions', 0.9, 'monthly'],
            ['/products/school-management', 0.95, 'monthly'],
            ['/products/inventory-pos', 0.9, 'monthly'],
            ['/products/dairy-management', 0.9, 'monthly'],
            ['/custom-automation', 0.8, 'monthly'],
            ['/about', 0.7, 'monthly'],
            ['/insights', 0.8, 'weekly'],
            ['/contact', 0.7, 'monthly'],
            ['/book-demo', 0.85, 'monthly'],
        ];
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

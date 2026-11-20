<?php

namespace Tests\Feature;

use App\Http\Controllers\PageController;
use App\Support\Seo;
use App\Support\Site;
use Tests\TestCase;

/** Public website: policy pages, redirects, SEO rules, logo. */
class WebsiteTest extends TestCase
{
    public static function policyPages(): array
    {
        return [
            ['/privacy-policy', 'Privacy Policy'],
            ['/terms-and-conditions', 'Terms and Conditions'],
            ['/refund-policy', 'Refund and Cancellation Policy'],
            ['/shipping-policy', 'Shipping and Delivery Policy'],
        ];
    }

    /** @dataProvider policyPages */
    public function test_policy_pages_render_with_company_details(string $url, string $heading): void
    {
        $this->get($url)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Legal')
                ->where('heading', $heading)
                ->where('html', fn ($html) => str_contains($html, 'LALogix Enterprises') && str_contains($html, 'info@lalogix.com')));
    }

    public function test_policies_cover_every_billed_product(): void
    {
        $html = PageController::legalHtml('terms-and-conditions');
        foreach (['VIVK.in', 'AMRITIX', 'Aishō', 'School Automation', 'LALogix Billing'] as $product) {
            $this->assertStringContainsString($product, $html);
        }
    }

    public function test_todo_notes_are_hidden_when_show_todos_is_off(): void
    {
        config(['lalogix.show_todos' => false]);
        $html = PageController::legalHtml('privacy-policy');
        $this->assertStringNotContainsString('TODO', $html);
        $this->assertStringContainsString('Dehradun', $html);

        config(['lalogix.show_todos' => true]);
        $this->assertStringContainsString('TODO: legal review', PageController::legalHtml('privacy-policy'));
    }

    public function test_site_clean_keeps_real_text_and_drops_placeholders(): void
    {
        config(['lalogix.show_todos' => false]);
        $this->assertSame('Yes, it works.', Site::clean('Yes, it works. TODO: confirm details.'));
        $this->assertNull(Site::clean('TODO: AISHO promise'));
        $this->assertSame('Price on request', Site::clean('From ₹TODO/month', 'price_hint'));
        $this->assertSame(['A', 'C'], Site::clean(['A', 'TODO: B', 'C']));
        $this->assertSame([['title' => 'Ok', 'text' => 'x']], Site::clean([['title' => 'Ok', 'text' => 'x'], ['title' => 'TODO: f', 'text' => 'TODO']]));
    }

    public static function redirects(): array
    {
        return [
            ['/blog', '/insights'],
            ['/blog/some-post', '/insights/some-post'],
            ['/custom-automation', '/products'],
            ['/services', '/products'],
            ['/solutions', '/industries'],
            ['/products/dairy-management', '/products/amritix'],
            ['/demo', '/book-demo'],
        ];
    }

    /** @dataProvider redirects */
    public function test_old_urls_redirect_permanently(string $from, string $to): void
    {
        $this->get($from)->assertStatus(301)->assertRedirect($to);
    }

    public function test_www_redirects_to_the_bare_domain(): void
    {
        $this->get('http://www.lalogix.com/about?ref=x')
            ->assertStatus(301)
            ->assertRedirect('https://lalogix.com/about?ref=x');
    }

    public function test_bare_domain_is_not_redirected(): void
    {
        $this->get('http://lalogix.com/about')->assertOk();
    }

    public function test_logo_used_in_json_ld_exists_and_is_large_enough(): void
    {
        $path = public_path('logo.png');
        $this->assertFileExists($path);
        [$w, $h] = getimagesize($path);
        $this->assertGreaterThanOrEqual(512, $w);
        $this->assertGreaterThanOrEqual(512, $h);
        $this->assertSame(Seo::siteUrl().'/logo.png', Seo::organization()['logo']);
    }

    public function test_titles_and_descriptions_follow_the_seo_rules(): void
    {
        $titles = [];
        $descriptions = [];
        $entries = array_map(fn ($p) => ['title' => $p[1], 'description' => $p[2], 'og' => $p[3]], Seo::pages());
        foreach (Site::slugs() as $slug) {
            $s = Seo::product($slug);
            $entries[$slug] = ['title' => $s['title'], 'description' => $s['description'], 'og' => $s['ogImage']];
        }

        foreach ($entries as $key => $e) {
            $this->assertLessThan(60, mb_strlen($e['title']), "{$key} title too long");
            $this->assertSame(1, substr_count($e['title'], 'LALogix') + substr_count($e['title'], 'LALOGIX'), "{$key} title should name LALogix once");
            $len = mb_strlen($e['description']);
            $this->assertTrue($len >= 150 && $len <= 160, "{$key} description is {$len} chars");
            $this->assertFileExists(public_path(ltrim($e['og'], '/')), "{$key} OG image missing");
            $titles[] = $e['title'];
            $descriptions[] = $e['description'];
        }

        $this->assertSame(count($titles), count(array_unique($titles)), 'titles must be unique');
        $this->assertSame(count($descriptions), count(array_unique($descriptions)), 'descriptions must be unique');
    }

    public function test_page_renders_title_without_duplicated_suffix(): void
    {
        $html = $this->get('/about')->assertOk()->getContent();
        $this->assertStringContainsString('<title inertia>About LALogix Enterprises, Dehradun</title>', $html);
        $this->assertSame(1, substr_count($html, '<title'));
        $this->assertStringContainsString('og:image" content="https://lalogix.com/images/og/about.jpg"', $html);
    }

    public function test_sitemap_lists_policy_pages_and_not_removed_pages(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('https://lalogix.com/privacy-policy', $xml);
        $this->assertStringNotContainsString('custom-automation', $xml);
        $this->assertStringNotContainsString('/solutions', $xml);
    }

    public function test_products_pricing_and_industries_pages_render(): void
    {
        $this->get('/products')->assertOk()->assertInertia(fn ($p) => $p->component('Products/Index')->has('products', count(Site::cards())));
        $this->get('/pricing')->assertOk()->assertInertia(fn ($p) => $p->component('Pricing')->has('products', count(Site::cards())));
        $this->get('/industries')->assertOk()->assertInertia(fn ($p) => $p->component('Industries')->has('industries'));
    }

    public function test_every_product_page_uses_the_shared_template(): void
    {
        foreach (Site::slugs() as $slug) {
            $html = $this->get("/products/{$slug}")
                ->assertOk()
                ->assertInertia(fn ($p) => $p->component('Products/Show')->where('product.slug', $slug))
                ->getContent();
            $this->assertStringContainsString('"@type":"SoftwareApplication"', $html, $slug);
            $this->assertStringContainsString('"@type":"Product"', $html, $slug);
        }
        $this->get('/products/unknown-thing')->assertNotFound();
    }

    public function test_navigation_lists_every_product_from_config(): void
    {
        $this->get('/')->assertInertia(fn ($p) => $p->has('site.products', count(Site::slugs())));
    }

    public function test_whatsapp_uses_the_username_and_no_phone_number_is_published(): void
    {
        config(['lalogix.company.whatsapp_username' => '@lalogix']);

        $this->get('/')->assertInertia(fn ($p) => $p->where('site.company.whatsapp', 'lalogix')
            ->where('site.company.whatsapp_handle', '@lalogix')
            ->missing('site.company.tel')
            ->missing('site.company.phone'));
        $this->assertSame('https://wa.me/lalogix?text=Hi', Site::whatsappUrl('Hi'));

        foreach (['/', '/contact', '/book-demo', '/products/amritix', '/privacy-policy', '/refund-policy', '/terms-and-conditions', '/shipping-policy'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('tel:', $html, $url);
            $this->assertStringNotContainsString('87555', $html, $url);
            $this->assertStringNotContainsString('"telephone"', $html, $url);
        }
        $this->assertStringContainsString('@lalogix', PageController::legalHtml('refund-policy'));
    }

    public function test_whatsapp_is_hidden_until_a_username_is_set(): void
    {
        config(['lalogix.company.whatsapp_username' => 'TODO: WhatsApp username', 'lalogix.show_todos' => false]);

        $this->get('/')->assertInertia(fn ($p) => $p->where('site.company.whatsapp', null));
        $this->assertNull(Site::whatsappUrl('Hi'));

        $refund = PageController::legalHtml('refund-policy');
        $this->assertStringNotContainsString('TODO', $refund);
        $this->assertStringContainsString('You can cancel at any time', $refund);
    }

    public function test_sitemap_lists_every_product_and_new_page(): void
    {
        $xml = $this->get('/sitemap.xml')->getContent();
        foreach (['/products', '/pricing', '/industries', ...array_map(fn ($s) => "/products/{$s}", Site::slugs())] as $path) {
            $this->assertStringContainsString("<loc>https://lalogix.com{$path}</loc>", $xml);
        }
    }

    public function test_homepage_has_the_new_sections_and_faq_schema(): void
    {
        $html = $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Home')
                ->where('home.hero.title', 'Ready-to-use software for schools, dairies and shops. Live today, not someday.')
                ->has('products', count(Site::cards()))
                ->has('quiz.rules')
                ->has('home.faq', 5))
            ->getContent();
        $this->assertStringContainsString('"@type":"FAQPage"', $html);
        $this->assertStringContainsString('"@type":"Organization"', $html);
    }

    public function test_removed_claims_and_generic_links_are_gone(): void
    {
        $files = array_merge(glob(resource_path('js/Pages/*.tsx')), glob(resource_path('js/Pages/*/*.tsx')), glob(resource_path('js/components/*/*.tsx')));
        foreach ($files as $file) {
            $src = file_get_contents($file);
            $this->assertStringNotContainsString('Join hundreds', $src, $file);
            $this->assertStringNotContainsString('Learn more', $src, $file);
            $this->assertStringNotContainsString('custom-automation', $src, $file);
        }
        $this->assertStringNotContainsString('hundreds', json_encode(config('lalogix')));
    }

    public function test_amritix_lists_only_real_features(): void
    {
        $text = strtolower(json_encode(config('lalogix.products.amritix')));
        foreach (['cattle', 'quality testing', 'fat/snf', 'breeding'] as $banned) {
            $this->assertStringNotContainsString($banned, $text);
        }
        $titles = array_column(config('lalogix.products.amritix.features'), 'title');
        $this->assertSame(['Multi-dairy accounts', 'Vendors', 'Milk purchase and sale', 'Inventory', 'Income and expense', 'Financial reports', 'Ledger and accounts', 'Android app'], $titles);
    }

    public function test_aisho_is_an_in_house_app_and_not_in_the_product_comparison(): void
    {
        $compare = array_column(Site::cards(), 'slug');
        $this->assertNotContains('aisho', $compare);
        $this->assertContains('vivk', $compare);

        foreach (['/products', '/pricing', '/'] as $url) {
            $this->get($url)->assertInertia(fn ($p) => $p->where('products', fn ($list) => ! collect($list)->contains('slug', 'aisho')));
        }

        // Still has its own page and stays in the menu, marked in-house.
        $this->get('/products/aisho')->assertOk()->assertInertia(fn ($p) => $p->where('product.in_house', true));
        $this->get('/')->assertInertia(fn ($p) => $p->where('site.products', fn ($list) => collect($list)->firstWhere('slug', 'aisho')['in_house'] === true));
    }

    public function test_htaccess_redirects_use_the_original_request_path(): void
    {
        // Behind the root .htaccess, REQUEST_URI inside public/.htaccess already starts with
        // /public/, so redirects built from it sent visitors to /public/... (live bug, Nov 2026).
        $public = file_get_contents(public_path('.htaccess'));
        $rules = preg_replace('/^\s*#.*$/m', '', $public);
        $this->assertStringNotContainsString('%{REQUEST_URI}', $rules);
        $this->assertStringContainsString('RewriteCond %{THE_REQUEST}', $rules);

        // The www redirect lives in the root .htaccess, before the rewrite into public/.
        $root = file_get_contents(base_path('.htaccess'));
        $www = strpos($root, 'RewriteCond %{HTTP_HOST} ^www');
        $this->assertNotFalse($www);
        $this->assertLessThan(strpos($root, 'RewriteRule ^(.*)$ public/$1'), $www);
    }
}

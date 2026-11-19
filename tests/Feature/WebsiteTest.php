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
        foreach (['VIVK.in', 'AMRITIX', 'AISHO.live', 'School Automation', 'Inventory &amp; POS'] as $product) {
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
}

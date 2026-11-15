<?php

namespace Tests\Feature;

use App\Models\IngestLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductSyncTest extends TestCase
{
    use RefreshDatabase;

    private Product $vivk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create(['name' => 'A', 'email' => 'a@example.test', 'password' => Hash::make('x'), 'role' => 'admin']));
        $this->vivk = Product::where('slug', 'vivk')->firstOrFail();
    }

    public function test_secret_is_stored_encrypted_and_never_returned(): void
    {
        $res = $this->putJson("/api/billing/products/{$this->vivk->id}/sync", [
            'sync_url' => 'https://www.vivk.in/api/cron/lalogix-sync',
            'sync_secret' => 'cron-secret-123',
        ])->assertOk();

        $res->assertJsonPath('product.syncUrl', 'https://www.vivk.in/api/cron/lalogix-sync')
            ->assertJsonPath('product.hasSyncSecret', true)
            ->assertJsonMissingPath('product.syncSecret');
        $raw = \DB::table('products')->where('id', $this->vivk->id)->value('sync_secret');
        $this->assertNotSame('cron-secret-123', $raw);
        $this->assertSame('cron-secret-123', $this->vivk->fresh()->sync_secret);

        // Saving without a secret keeps the saved one; clear_secret removes it.
        $this->putJson("/api/billing/products/{$this->vivk->id}/sync", ['sync_url' => 'https://www.vivk.in/api/cron/lalogix-sync'])->assertJsonPath('product.hasSyncSecret', true);
        $this->putJson("/api/billing/products/{$this->vivk->id}/sync", ['sync_url' => 'https://www.vivk.in/api/cron/lalogix-sync', 'clear_secret' => true])->assertJsonPath('product.hasSyncSecret', false);
        $this->getJson('/api/billing/products')->assertJsonMissingPath('products.1.syncSecret');
    }

    public function test_sync_now_calls_the_product_with_its_secret(): void
    {
        $this->vivk->forceFill(['sync_url' => 'https://www.vivk.in/api/cron/lalogix-sync', 'sync_secret' => 'cron-secret-123'])->save();
        Http::fake(['www.vivk.in/*' => Http::response(['synced' => 7, 'failed' => 0, 'remaining' => 0, 'configured' => true])]);

        $this->postJson("/api/billing/products/{$this->vivk->id}/sync")->assertOk()
            ->assertJsonPath('result.synced', 7)->assertJsonPath('result.remaining', 0);

        Http::assertSent(fn (ClientRequest $r) => $r->method() === 'GET'
            && $r->url() === 'https://www.vivk.in/api/cron/lalogix-sync'
            && $r->hasHeader('Authorization', 'Bearer cron-secret-123'));
        $log = IngestLog::latest('id')->first();
        $this->assertSame(['pull', 'sync', 'synced'], [$log->channel, $log->action, $log->outcome]);
        $this->assertStringContainsString('7 sent', $log->message);
    }

    public function test_sync_now_reports_problems(): void
    {
        $this->postJson("/api/billing/products/{$this->vivk->id}/sync")->assertStatus(422)
            ->assertJsonPath('error', 'Save VIVK.in\'s sync URL and secret first.');

        $this->vivk->forceFill(['sync_url' => 'https://www.vivk.in/api/cron/lalogix-sync', 'sync_secret' => 'wrong'])->save();
        Http::fake(['www.vivk.in/*' => Http::response(['error' => 'Unauthorized'], 401)]);
        $this->postJson("/api/billing/products/{$this->vivk->id}/sync")->assertStatus(502)
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'refused the secret'));
        $this->assertSame('rejected', IngestLog::latest('id')->value('outcome'));
    }

    public function test_sync_errors_show_the_url_and_the_products_own_reason(): void
    {
        $this->vivk->forceFill(['sync_url' => 'https://api.example.test/api/cron/lalogix-sync', 'sync_secret' => 'secret'])->save();

        // Nested error object (e.g. AISHO's API): its message is shown instead of crashing.
        Http::fake(['api.example.test/*' => Http::sequence()
            ->push(['error' => ['code' => 'db_down', 'message' => 'Database unavailable']], 500)
            ->push('<!doctype html><html>Not found</html>', 404, ['Content-Type' => 'text/html; charset=UTF-8'])]);
        $this->postJson("/api/billing/products/{$this->vivk->id}/sync")->assertStatus(502)
            ->assertJsonPath('error', 'VIVK.in answered 500 at https://api.example.test/api/cron/lalogix-sync: Database unavailable');

        // A web page instead of JSON: the URL points at the website, not the API.
        $this->postJson("/api/billing/products/{$this->vivk->id}/sync")->assertStatus(502)
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'answered 404 at https://api.example.test/api/cron/lalogix-sync: got a web page instead of the sync API'));
    }

    public function test_redirects_are_not_followed_and_the_target_is_suggested(): void
    {
        // vivk.in answers 307 → www.vivk.in; following it would drop the Authorization header.
        $this->vivk->forceFill(['sync_url' => 'https://vivk.in/api/cron/lalogix-sync', 'sync_secret' => 'cron-secret-123'])->save();
        Http::fake(['vivk.in/*' => Http::response('', 307, ['Location' => 'https://www.vivk.in/api/cron/lalogix-sync'])]);

        $this->postJson("/api/billing/products/{$this->vivk->id}/sync")->assertStatus(502)
            ->assertJsonPath('redirect', 'https://www.vivk.in/api/cron/lalogix-sync')
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'redirects to https://www.vivk.in/api/cron/lalogix-sync'));
        Http::assertSentCount(1);
    }

    public function test_sync_url_must_be_https_outside_local(): void
    {
        $this->app['env'] = 'production';
        // (CSRF is only skipped automatically in the testing environment.)
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->putJson("/api/billing/products/{$this->vivk->id}/sync", ['sync_url' => 'http://www.vivk.in/api/cron/lalogix-sync'])
            ->assertStatus(422)->assertJsonPath('error', 'The sync URL must start with https://.');
    }
}

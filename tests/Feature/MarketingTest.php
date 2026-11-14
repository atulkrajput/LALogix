<?php

namespace Tests\Feature;

use App\Models\AdSpend;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use App\Support\Gstin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        $this->actingAs(User::create(['name' => 'A', 'email' => 'a@example.test', 'password' => Hash::make('x'), 'role' => 'admin']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_website_lead_forms_store_ad_attribution(): void
    {
        $attribution = [
            'utm_source' => 'facebook', 'utm_medium' => 'paid', 'utm_campaign' => 'School ERP - Nov',
            'utm_content' => '<b>Video ad</b>', 'click_id' => 'fbclid_abc', 'landing_page' => '/book-demo?utm_source=facebook',
            'referrer' => str_repeat('x', 900),
        ];
        $this->postJson('/api/contact', ['name' => 'Ravi', 'email' => 'ravi@example.test', 'message' => 'Hi', 'attribution' => $attribution])->assertCreated();
        $this->postJson('/api/demo', ['name' => 'Priya', 'email' => 'priya@example.test', 'attribution' => ['utm_source' => 'google', 'utm_campaign' => 'Brand']])->assertCreated();
        $this->postJson('/api/leads', ['name' => 'No tags', 'email' => 'n@example.test'])->assertCreated();

        $ravi = Lead::where('email', 'ravi@example.test')->first();
        $this->assertSame('facebook', $ravi->utm_source);
        $this->assertSame('School ERP - Nov', $ravi->utm_campaign);
        $this->assertSame('Video ad', $ravi->utm_content);                 // tags stripped
        $this->assertSame('fbclid_abc', $ravi->click_id);
        $this->assertSame(500, mb_strlen($ravi->referrer));                // truncated
        $this->assertSame('Brand', Lead::where('email', 'priya@example.test')->value('utm_campaign'));
        $this->assertNull(Lead::where('email', 'n@example.test')->value('utm_source'));
    }

    public function test_billing_client_from_lead_keeps_campaign_and_is_idempotent(): void
    {
        $this->admin();
        $lead = Lead::create(['name' => 'Ravi', 'organization' => 'Acme School', 'email' => 'ravi@example.test', 'city' => 'Pune',
            'product_interest' => 'school', 'utm_source' => 'facebook', 'utm_campaign' => 'School ERP - Nov']);

        $res = $this->postJson("/api/billing/clients/from-lead/{$lead->id}")->assertCreated()->assertJsonPath('created', true);
        $client = Client::find($res->json('client.id'));
        $this->assertSame($lead->id, $client->lead_id);
        $this->assertSame('Acme School', $client->company);
        $this->assertSame('School ERP - Nov', $client->utm_campaign);
        $this->assertSame('converted', $lead->fresh()->status);

        $this->postJson("/api/billing/clients/from-lead/{$lead->id}")->assertOk()->assertJsonPath('created', false)->assertJsonPath('client.id', $client->id);
        $this->assertSame(1, Client::count());
    }

    public function test_meta_sync_pages_upserts_and_keeps_token_secret(): void
    {
        $this->admin();
        $this->postJson('/api/marketing/meta/sync')->assertStatus(502)->assertJsonPath('error', 'Save the Meta ad account id and access token first.');
        $this->putJson('/api/marketing/meta', ['ad_account_id' => 'act_1234567890', 'access_token' => 'EAAG-secret'])
            ->assertOk()->assertJsonPath('meta.has_token', true)->assertJsonMissingPath('meta.access_token');
        $this->assertNotSame('EAAG-secret', \DB::table('business_profiles')->value('meta_access_token'));
        $this->getJson('/api/billing/settings')->assertJsonMissingPath('profile.metaAccessToken');

        Http::fake([
            'graph.facebook.com/*/act_1234567890/insights*' => Http::sequence()
                ->push(['data' => [
                    ['campaign_id' => '111', 'campaign_name' => 'School ERP - Nov', 'spend' => '1500.50', 'impressions' => '20000', 'clicks' => '300',
                        'actions' => [['action_type' => 'link_click', 'value' => '300'], ['action_type' => 'lead', 'value' => '12']], 'date_start' => '2026-11-01'],
                ], 'paging' => ['next' => 'https://graph.facebook.com/v23.0/act_1234567890/insights?after=abc']])
                ->push(['data' => [
                    ['campaign_id' => '111', 'campaign_name' => 'School ERP - Nov', 'spend' => '999.50', 'impressions' => '10000', 'clicks' => '100', 'date_start' => '2026-11-02'],
                ]])
                ->push(['data' => [
                    ['campaign_id' => '111', 'campaign_name' => 'School ERP - Nov', 'spend' => '1600', 'impressions' => '20000', 'clicks' => '310', 'date_start' => '2026-11-01'],
                ]]),
        ]);

        $this->postJson('/api/marketing/meta/sync', ['days' => 30])->assertOk()
            ->assertJsonPath('result.rows', 2)->assertJsonPath('result.days', 2);
        Http::assertSent(fn (ClientRequest $r) => $r->hasHeader('Authorization', 'Bearer EAAG-secret') && str_contains($r->url(), 'level=campaign'));
        $this->assertSame(2, AdSpend::count());
        $this->assertSame(12, AdSpend::where('date', '2026-11-01')->value('platform_leads'));

        // Re-sync updates the same day instead of duplicating it.
        $this->postJson('/api/marketing/meta/sync', ['days' => 30])->assertOk();
        $this->assertSame(2, AdSpend::count());
        $this->assertEqualsWithDelta(1600, AdSpend::where('date', '2026-11-01')->value('spend'), 0.001);
        $this->assertNotNull(BusinessProfile::current()->meta_synced_at);
    }

    public function test_meta_errors_are_reported(): void
    {
        $this->admin();
        $this->putJson('/api/marketing/meta', ['ad_account_id' => '1234567890', 'access_token' => 'expired'])->assertOk();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Error validating access token: Session has expired.']], 400)]);
        $this->postJson('/api/marketing/meta/sync')->assertStatus(502)
            ->assertJsonPath('error', 'Meta Marketing API: Error validating access token: Session has expired.');
        $this->putJson('/api/marketing/meta', ['ad_account_id' => 'abc'])->assertStatus(422);
    }

    public function test_spend_csv_import_reads_meta_and_google_exports(): void
    {
        $this->admin();
        // Meta Ads Manager layout: "Cost per result" comes before "Amount spent" and must not be read as spend.
        $meta = "\xEF\xBB\xBFReporting starts,Reporting ends,Campaign name,Results,Cost per result,Amount spent (INR),Impressions,Link clicks\n"
            ."2026-11-01,2026-11-01,School ERP - Nov,12,125.04,1500.50,20000,300\n"
            ."2026-11-02,2026-11-02,School ERP - Nov,5,199.90,999.50,10000,100\n"
            .",,Total,17,,2500.00,30000,400\n";
        $this->post('/api/marketing/spend/import', ['platform' => 'meta', 'file' => UploadedFile::fake()->createWithContent('meta.csv', $meta)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('saved', 2);
        $this->assertEqualsWithDelta(1500.50, AdSpend::where('date', '2026-11-01')->value('spend'), 0.001);
        $this->assertSame(12, AdSpend::where('date', '2026-11-01')->value('platform_leads'));

        $google = "Day,Campaign,Campaign ID,Cost,Impr.,Clicks,Conversions\n02/11/2026,Brand search,987,\"1,200.00\",4000,220,3\n";
        $this->post('/api/marketing/spend/import', ['platform' => 'google', 'file' => UploadedFile::fake()->createWithContent('g.csv', $google)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('saved', 1);
        $g = AdSpend::where('platform', 'google')->first();
        $this->assertSame(['987', 'Brand search', '2026-11-02', 4000, 220], [$g->campaign_id, $g->campaign_name, $g->date->toDateString(), $g->impressions, $g->clicks]);
        $this->assertEqualsWithDelta(1200, $g->spend, 0.001);

        // Re-import replaces the same days.
        $this->post('/api/marketing/spend/import', ['platform' => 'meta', 'file' => UploadedFile::fake()->createWithContent('meta.csv', $meta)], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(3, AdSpend::count());

        $this->post('/api/marketing/spend/import', ['platform' => 'other', 'file' => UploadedFile::fake()->createWithContent('x.csv', "foo,bar\n1,2\n")], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error', fn ($e) => str_contains($e, '"date"'));
    }

    public function test_campaign_report_joins_spend_leads_customers_and_revenue(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->admin();
        $gstin = '29ABCDE1234F1Z'.Gstin::checkDigit('29ABCDE1234F1Z');
        $this->putJson('/api/billing/settings', ['legal_name' => 'LALogix', 'gstin' => $gstin, 'state_code' => '29', 'payment_terms_days' => 15])->assertOk();

        foreach ([['2026-11-01', 1500], ['2026-11-02', 500]] as [$date, $amount]) {
            AdSpend::create(['platform' => 'meta', 'date' => $date, 'campaign_id' => '111', 'campaign_name' => 'School ERP - Nov',
                'campaign_key' => '111', 'spend' => $amount, 'impressions' => 10000, 'clicks' => 200, 'platform_leads' => 6]);
        }
        $mk = fn (array $attrs) => Lead::create(['name' => 'L', 'email' => uniqid().'@example.test', 'product_interest' => 'school', ...$attrs]);
        $byName = $mk(['utm_source' => 'facebook', 'utm_campaign' => 'school erp - nov']);   // matches the campaign name
        $mk(['utm_source' => 'fb', 'utm_campaign' => '111']);                                   // matches the campaign id
        $mk(['utm_source' => 'facebook', 'utm_campaign' => 'School ERP - Nov']);
        $mk(['utm_source' => 'newsletter', 'utm_campaign' => 'Nov newsletter']);                // tagged, no spend
        $mk(['source' => 'website']);                                                           // untagged

        // One lead becomes a customer and is invoiced ₹10,000 (+ GST).
        $clientId = $this->postJson("/api/billing/clients/from-lead/{$byName->id}")->json('client.id');
        $this->putJson("/api/billing/clients/{$clientId}", ['name' => 'L', 'state_code' => '29', 'country' => 'India'])->assertOk();
        $inv = $this->postJson('/api/billing/invoices', ['product_id' => 1, 'client_id' => $clientId, 'invoice_date' => '2026-11-15',
            'items' => [['description' => 'ERP', 'quantity' => 1, 'rate' => 10000, 'gst_rate' => 18]]])->json('invoice.id');
        $this->postJson("/api/billing/invoices/{$inv}/issue")->assertOk();

        $r = $this->getJson('/api/marketing?from=2026-11-01&to=2026-11-20')->assertOk()->json();

        $t = $r['totals'];
        $this->assertEqualsWithDelta(2000, $t['spend'], 0.001);
        $this->assertSame(5, $t['leads']);
        $this->assertSame(4, $t['campaign_leads']);
        $this->assertEqualsWithDelta(500, $t['cost_per_lead'], 0.001);   // 2000 / 4 tagged leads
        $this->assertSame(1, $t['customers']);
        $this->assertEqualsWithDelta(10000, $t['revenue'], 0.001);
        $this->assertEqualsWithDelta(5.0, $t['roas'], 0.001);

        $campaigns = collect($r['campaigns'])->keyBy('name');
        $school = $campaigns['School ERP - Nov'];
        $this->assertSame([3, 1, 12], [$school['leads'], $school['customers'], $school['platform_leads']]);
        $this->assertEqualsWithDelta(666.67, $school['cost_per_lead'], 0.01);
        $this->assertEqualsWithDelta(5.0, $school['roas'], 0.001);
        $this->assertEqualsWithDelta(2.0, $school['ctr'], 0.001);          // 400 clicks / 20,000 impressions
        $this->assertNull($campaigns['nov newsletter']['platform']);
        $this->assertSame(1, $campaigns['nov newsletter']['leads']);

        $sources = collect($r['sources'])->keyBy('source');
        $this->assertSame(3, $sources['facebook / instagram']['leads']);
        $this->assertEqualsWithDelta(10000, $sources['facebook / instagram']['revenue'], 0.001);
        $this->assertSame(1, $sources['website']['leads']);
        $this->assertFalse($r['meta']['has_token']);

        $this->getJson('/api/marketing?from=2025-01-01&to=2026-11-20')->assertStatus(422);
    }

    public function test_synced_customer_campaign_counts(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->admin();
        Client::create(['name' => 'VIVK user', 'country' => 'India', 'state_code' => '29', 'utm_source' => 'instagram', 'utm_campaign' => 'VIVK launch']);
        $r = $this->getJson('/api/marketing?from=2026-11-01&to=2026-11-20')->assertOk()->json();
        $this->assertSame(1, collect($r['campaigns'])->firstWhere('name', 'vivk launch')['customers']);
        $this->assertSame(1, collect($r['sources'])->firstWhere('source', 'facebook / instagram')['customers']);
    }
}

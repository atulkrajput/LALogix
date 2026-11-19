<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ChatController;
use App\Jobs\NotifyNewLead;
use App\Models\Lead;
use App\Models\User;
use App\Services\EmailService;
use App\Services\LeadCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Website lead capture into the existing leads backend. */
class LeadCaptureTest extends TestCase
{
    use RefreshDatabase;

    private function shortForm(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ravi Kumar',
            'phone' => '98765 43210',
            'product' => 'dairy',
            'message' => 'Two dairies in Doiwala',
            'consent' => true,
            'source_widget' => 'form',
            'page_url' => 'https://lalogix.com/products/amritix',
            'website' => '',
            'attribution' => ['utm_source' => 'facebook', 'utm_medium' => 'paid', 'utm_campaign' => 'dairy-nov'],
        ], $overrides);
    }

    public function test_short_demo_form_creates_a_lead_with_all_fields(): void
    {
        Bus::fake([NotifyNewLead::class]);

        $this->postJson('/api/demo', $this->shortForm())->assertCreated()->assertJsonPath('success', true);

        $lead = Lead::sole();
        $this->assertSame('Ravi Kumar', $lead->name);
        $this->assertSame('+919876543210', $lead->phone);
        $this->assertNull($lead->email);
        $this->assertSame('dairy', $lead->product_interest);
        $this->assertSame('form', $lead->source_widget);
        $this->assertSame('new', $lead->status);
        $this->assertSame('https://lalogix.com/products/amritix', $lead->page_url);
        $this->assertSame('facebook', $lead->utm_source);
        $this->assertSame('paid', $lead->utm_medium);
        $this->assertSame('dairy-nov', $lead->utm_campaign);
        $this->assertNotNull($lead->consent_at);

        Bus::assertDispatched(NotifyNewLead::class, fn ($job) => $job->lead->is($lead));
    }

    public static function badPhones(): array
    {
        return [['12345'], ['5876543210'], ['98765432101'], ['abcdefghij']];
    }

    /** @dataProvider badPhones */
    public function test_demo_form_rejects_invalid_indian_mobiles(string $phone): void
    {
        $this->postJson('/api/demo', $this->shortForm(['phone' => $phone]))
            ->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'Please enter a valid 10-digit Indian mobile number.');
        $this->assertSame(0, Lead::count());
    }

    public function test_phone_formats_are_normalised(): void
    {
        foreach (['+91 98765 43210', '919876543210', '09876543210', '98765-43210'] as $input) {
            $this->assertSame('+919876543210', LeadCaptureService::normalizePhone($input), $input);
        }
    }

    public function test_name_and_phone_are_required(): void
    {
        $this->postJson('/api/demo', $this->shortForm(['name' => '', 'source_widget' => 'quiz']))->assertStatus(422);
        $this->postJson('/api/demo', $this->shortForm(['phone' => '']))->assertStatus(422);
        $this->assertSame(0, Lead::count());
    }

    public function test_honeypot_submissions_are_silently_dropped(): void
    {
        $this->postJson('/api/demo', $this->shortForm(['website' => 'http://spam.example']))->assertCreated();
        $this->postJson('/api/leads', $this->shortForm(['website' => 'buy now']))->assertCreated();
        $this->assertSame(0, Lead::count());
    }

    public function test_lead_forms_are_rate_limited(): void
    {
        Bus::fake([NotifyNewLead::class]);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/demo', $this->shortForm())->assertCreated();
        }
        $this->postJson('/api/demo', $this->shortForm())->assertStatus(429);
        $this->assertSame(5, Lead::count());
    }

    public function test_old_demo_payload_with_email_still_works(): void
    {
        Bus::fake([NotifyNewLead::class]);
        $this->postJson('/api/demo', [
            'name' => 'Old Form', 'email' => 'old@example.test', 'organization' => 'School',
            'scheduled_date' => '2026-12-01', 'scheduled_time' => '11:00', 'product_interest' => 'school',
        ])->assertCreated();

        $lead = Lead::sole();
        $this->assertSame('demo_scheduled', $lead->status);
        $this->assertSame('school', $lead->product_interest);
        $this->assertSame(1, $lead->demoBookings()->count());
    }

    public function test_quiz_lead_keeps_business_type_and_size(): void
    {
        Bus::fake([NotifyNewLead::class]);
        $this->postJson('/api/leads', $this->shortForm([
            'source_widget' => 'quiz', 'product' => 'school', 'business_type' => 'School', 'size' => '50–500',
        ]))->assertCreated();

        $lead = Lead::sole();
        $this->assertSame(['quiz', 'school', 'School', '50–500'], [$lead->source_widget, $lead->product_interest, $lead->business_type, $lead->size]);
    }

    public function test_exit_popup_lead_needs_only_a_phone(): void
    {
        Bus::fake([NotifyNewLead::class]);
        $this->postJson('/api/leads', ['phone' => '9876543210', 'source_widget' => 'exit'])->assertCreated();
        $this->assertSame('Website visitor', Lead::sole()->name);
        $this->assertSame('exit', Lead::sole()->source_widget);
    }

    public function test_unknown_products_and_widgets_are_not_stored_as_is(): void
    {
        Bus::fake([NotifyNewLead::class]);
        $this->postJson('/api/leads', $this->shortForm(['product' => '<script>']))->assertCreated();
        $this->assertSame('general', Lead::sole()->product_interest);
        $this->postJson('/api/leads', $this->shortForm(['source_widget' => 'popup']))->assertStatus(422);
    }

    public function test_first_visit_utm_cookie_is_used_when_the_form_sends_none(): void
    {
        Bus::fake([NotifyNewLead::class]);
        $this->withCredentials()->withUnencryptedCookie('lx_utm', json_encode(['utm_source' => 'google', 'utm_campaign' => 'school-search']))
            ->postJson('/api/demo', $this->shortForm(['attribution' => []]))
            ->assertCreated();

        $this->assertSame(['google', 'school-search'], [Lead::sole()->utm_source, Lead::sole()->utm_campaign]);
    }

    public function test_new_lead_alert_emails_info_address(): void
    {
        $lead = Lead::create(['name' => 'Asha', 'phone' => '+919876543210', 'product_interest' => 'vivk', 'source_widget' => 'chat']);

        $email = $this->mock(EmailService::class);
        $email->shouldReceive('send')->once()->withArgs(fn ($to, $subject, $html) => $to === 'info@lalogix.com'
            && str_contains($subject, 'Asha') && str_contains($html, '+919876543210'))->andReturn(['success' => true]);

        app()->call([new NotifyNewLead($lead), 'handle']);
    }

    public function test_whatsapp_clicks_are_logged_as_lead_events(): void
    {
        $this->postJson('/api/lead-events', [
            'type' => 'whatsapp_click', 'product' => 'dairy', 'location' => 'floating',
            'page_url' => 'https://lalogix.com/products/amritix', 'attribution' => ['utm_source' => 'facebook'],
        ])->assertCreated();

        $row = DB::table('lead_events')->first();
        $this->assertSame(['whatsapp_click', 'dairy', 'floating', 'facebook'], [$row->type, $row->product, $row->location, $row->utm_source]);
        $this->assertSame(64, strlen($row->ip_hash));

        $this->postJson('/api/lead-events', ['type' => 'drop_table'])->assertStatus(422);
    }

    public function test_chat_is_off_unless_enabled(): void
    {
        config(['lalogix.chat.enabled' => false]);
        $this->postJson('/api/chat', ['messages' => [['role' => 'user', 'content' => 'Hi']]])->assertNotFound();
    }

    public function test_chat_proxies_to_vivk_without_exposing_the_key(): void
    {
        config(['lalogix.chat' => ['enabled' => true, 'api_url' => 'https://api.vivk.test/v1/chat', 'api_key' => 'secret-key', 'model' => 'vivk-1', 'timeout' => 5, 'ask_contact_after' => 3]]);
        Http::fake(['api.vivk.test/*' => Http::response(['choices' => [['message' => ['content' => 'AMRITIX keeps your dairy hisab.']]]])]);

        $res = $this->postJson('/api/chat', ['messages' => [['role' => 'user', 'content' => 'What is AMRITIX?']]])
            ->assertOk()
            ->assertJsonPath('reply', 'AMRITIX keeps your dairy hisab.');
        $this->assertStringNotContainsString('secret-key', $res->getContent());

        Http::assertSent(function ($request) {
            $system = $request['messages'][0]['content'];

            return $request->hasHeader('Authorization', 'Bearer secret-key')
                && $request['messages'][0]['role'] === 'system'
                && str_contains($system, 'AMRITIX') && str_contains($system, 'Never invent prices')
                && end($request->data()['messages'])['content'] === 'What is AMRITIX?';
        });
    }

    public function test_chat_falls_back_to_whatsapp_when_the_api_fails(): void
    {
        config(['lalogix.chat.enabled' => true, 'lalogix.chat.api_url' => 'https://api.vivk.test/v1/chat']);
        Http::fake(['api.vivk.test/*' => Http::response('boom', 500)]);

        $this->postJson('/api/chat', ['messages' => [['role' => 'user', 'content' => 'Hi']]])
            ->assertOk()
            ->assertJsonPath('fallback', true);
    }

    public function test_chat_prompt_has_no_todo_placeholders_in_production(): void
    {
        config(['lalogix.show_todos' => false]);
        $prompt = ChatController::systemPrompt();
        $this->assertStringNotContainsString('TODO', $prompt);
        $this->assertStringContainsString('Multi-dairy accounts', $prompt);
    }

    public function test_admin_can_filter_leads_and_export_csv(): void
    {
        Lead::create(['name' => 'A', 'phone' => '+919876543210', 'product_interest' => 'dairy', 'source_widget' => 'quiz', 'status' => 'trial']);
        Lead::create(['name' => 'B', 'phone' => '+919876543211', 'product_interest' => 'vivk', 'source_widget' => 'chat', 'status' => 'new']);
        Lead::create(['name' => 'C', 'email' => 'c@example.test', 'product_interest' => 'school', 'status' => 'paid']);

        $this->getJson('/api/leads')->assertUnauthorized();
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => Hash::make('x'), 'role' => 'admin']));

        $this->getJson('/api/leads?product=dairy')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('leads.0.name', 'A');
        $this->getJson('/api/leads?source_widget=chat')->assertJsonPath('total', 1)->assertJsonPath('leads.0.name', 'B');
        $this->getJson('/api/leads?source_widget=none')->assertJsonPath('total', 1)->assertJsonPath('leads.0.name', 'C');
        $this->getJson('/api/leads?status=trial')->assertJsonPath('total', 1);

        $csv = $this->get('/api/leads/export?product=dairy')->assertOk()->streamedContent();
        $this->assertStringContainsString('source_widget', $csv);
        $this->assertStringContainsString('utm_campaign', $csv);
        $this->assertSame(2, count(array_filter(explode("\n", trim($csv)))));
    }

    public function test_csv_export_neutralises_formula_injection(): void
    {
        Lead::create(['name' => '=HYPERLINK("http://evil")', 'phone' => '+919876543210', 'product_interest' => 'vivk']);
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => Hash::make('x'), 'role' => 'admin']));

        $csv = $this->get('/api/leads/export')->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }
}

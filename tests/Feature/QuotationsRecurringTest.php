<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Services\Billing\RecurringInvoiceService;
use App\Support\Gstin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class QuotationsRecurringTest extends TestCase
{
    use RefreshDatabase;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->actingAs(User::create(['name' => 'A', 'email' => 'a@example.test', 'password' => Hash::make('x'), 'role' => 'admin']));
        $gstin = '29ABCDE1234F1Z'.Gstin::checkDigit('29ABCDE1234F1Z');
        $this->putJson('/api/billing/settings', ['legal_name' => 'LALogix', 'gstin' => $gstin, 'state_code' => '29', 'payment_terms_days' => 15])->assertOk();
        $this->clientId = $this->postJson('/api/billing/clients', ['name' => 'Acme', 'company' => 'Acme Pvt Ltd', 'email' => 'accounts@acme.test', 'state_code' => '29', 'country' => 'India'])->json('client.id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function quote(array $over = []): array
    {
        return $this->postJson('/api/billing/quotations', $over + [
            'product_id' => 1, 'client_id' => $this->clientId, 'invoice_date' => '2026-11-15',
            'items' => [['description' => 'Website redesign', 'sac' => '998314', 'quantity' => 1, 'rate' => 50000, 'gst_rate' => 18]],
        ])->assertCreated()->json('quotation');
    }

    public function test_quotation_lifecycle_and_conversion(): void
    {
        $q = $this->quote();
        $this->assertSame('QT/26-27/0001', $q['number']);
        $this->assertSame('2026-11-30', $q['validUntil']);                 // 15 days by default
        $this->assertSame('intra', $q['supplyType']);
        $this->assertEqualsWithDelta(59000, $q['grandTotal'], 0.001);
        $this->assertSame('QT/26-27/0002', $this->quote()['number']);

        $this->postJson("/api/billing/quotations/{$q['id']}/status", ['status' => 'sent'])->assertOk()->assertJsonPath('quotation.status', 'sent');
        $this->postJson("/api/billing/quotations/{$q['id']}/status", ['status' => 'accepted'])->assertOk();

        // Convert → draft invoice with the same lines; quotation locked.
        $invoice = $this->postJson("/api/billing/quotations/{$q['id']}/convert")->assertCreated()->json('invoice');
        $this->assertSame('draft', $invoice['status']);
        $this->assertNull($invoice['number']);
        $this->assertEqualsWithDelta(59000, $invoice['grandTotal'], 0.001);
        $this->assertStringContainsString('As per quotation QT/26-27/0001', $invoice['notes']);
        $this->assertSame('converted', Quotation::find($q['id'])->status);
        $this->putJson("/api/billing/quotations/{$q['id']}", ['product_id' => 1, 'client_id' => $this->clientId, 'invoice_date' => '2026-11-15',
            'items' => [['description' => 'x', 'quantity' => 1, 'rate' => 1, 'gst_rate' => 18]]])->assertStatus(422);
        $this->postJson("/api/billing/quotations/{$q['id']}/convert")->assertStatus(422);
        $this->deleteJson("/api/billing/quotations/{$q['id']}")->assertStatus(422);

        // Duplicate makes a new open quotation.
        $dup = $this->postJson("/api/billing/quotations/{$q['id']}/duplicate")->assertCreated()->json('quotation');
        $this->assertSame(['QT/26-27/0003', 'draft'], [$dup['number'], $dup['status']]);

        // PDF renders; quotations never appear in GST reports or revenue.
        $pdf = $this->get("/admin/billing/quotations/{$dup['id']}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertSame(0, $this->getJson('/api/billing/reports/gst?from=2026-11-01&to=2026-11-30')->json('counts.invoices'));

        $list = $this->getJson('/api/billing/quotations')->assertOk()->json();
        $this->assertSame(3, $list['summary']['count']);
        $this->assertEqualsWithDelta(50000, $list['summary']['won_value'], 0.001);
        $this->assertEqualsWithDelta(100.0, $list['summary']['win_rate'], 0.001);
    }

    public function test_expired_and_declined_quotations(): void
    {
        $q = $this->quote(['due_date' => '2026-11-20']);
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->getJson("/api/billing/quotations/{$q['id']}")->assertJsonPath('quotation.displayStatus', 'expired');
        $this->assertCount(1, $this->getJson('/api/billing/quotations?status=expired')->json('quotations'));
        $this->postJson("/api/billing/quotations/{$q['id']}/status", ['status' => 'declined'])->assertOk();
        $this->postJson("/api/billing/quotations/{$q['id']}/convert")->assertStatus(422);
    }

    private function schedule(array $over = []): array
    {
        return $this->postJson('/api/billing/recurring', $over + [
            'name' => 'Acme AMC', 'product_id' => 1, 'client_id' => $this->clientId, 'frequency' => 'monthly',
            'start_date' => '2026-11-15', 'auto_issue' => true,
            'items' => [['description' => 'AMC for {period}', 'sac' => '998313', 'quantity' => 1, 'rate' => 10000, 'gst_rate' => 18]],
            'notes' => 'Billing month: {month} {year}',
        ])->assertCreated()->json('schedule');
    }

    public function test_recurring_creates_issues_and_catches_up_once_per_period(): void
    {
        $s = $this->schedule();                                           // start today → first invoice now
        $first = Invoice::where('recurring_invoice_id', $s['id'])->first();
        $this->assertSame(['issued', 'LAL/26-27/0001', '2026-11-15'], [$first->status, $first->number, $first->recurring_period->toDateString()]);
        $this->assertSame('AMC for November 2026', $first->items[0]->description);
        $this->assertSame('Billing month: November 2026', $first->notes);
        $this->assertSame('2026-12-15', RecurringInvoice::find($s['id'])->next_run_date->toDateString());

        $service = app(RecurringInvoiceService::class);
        $this->assertSame(0, $service->runDue()['created']);              // nothing due again today

        // Three months later: December, January and February are caught up, oldest first.
        Carbon::setTestNow('2027-02-20 09:00:00');
        $r = $service->runDue();
        $this->assertSame([3, 3], [$r['created'], $r['issued']]);
        $this->assertSame(['2026-11-15', '2026-12-15', '2027-01-15', '2027-02-15'],
            Invoice::where('recurring_invoice_id', $s['id'])->orderBy('recurring_period')->get()->map(fn ($i) => $i->recurring_period->toDateString())->all());
        $this->assertSame(0, $service->runDue()['created']);
        $this->assertSame(4, RecurringInvoice::find($s['id'])->invoices_created);

        // The database refuses a second invoice for the same period.
        $this->expectException(\Illuminate\Database\QueryException::class);
        Invoice::whereKey($first->id)->first()->replicate(['number', 'sequence'])->fill(['status' => 'draft'])->save();
    }

    public function test_month_end_quarterly_pause_resume_end_and_failures(): void
    {
        // 31 Jan quarterly → 30 Apr → 31 Jul (keeps the 31st where the month has one).
        $s = RecurringInvoice::create(['name' => 'Q', 'product_id' => 1, 'client_id' => $this->clientId, 'frequency' => 'quarterly',
            'start_date' => '2027-01-31', 'next_run_date' => '2027-01-31', 'items' => [['description' => 'Q {period}', 'quantity' => 1, 'rate' => 100, 'gst_rate' => 18]]]);
        $this->assertSame('2027-04-30', $s->periodAfter(Carbon::parse('2027-01-31'))->toDateString());
        $this->assertSame('2027-07-31', $s->periodAfter(Carbon::parse('2027-04-30'))->toDateString());
        $this->assertSame('Jan 2027 – Mar 2027', app(RecurringInvoiceService::class)->periodLabel($s, Carbon::parse('2027-01-31')));

        $m = $this->schedule(['start_date' => '2026-12-01', 'end_date' => '2027-01-31']);
        $this->assertSame(0, Invoice::where('recurring_invoice_id', $m['id'])->count());   // starts next month
        $this->postJson("/api/billing/recurring/{$m['id']}/status", ['status' => 'paused'])->assertOk();
        Carbon::setTestNow('2027-01-10 10:00:00');
        $this->assertSame(0, app(RecurringInvoiceService::class)->runDue()['created']);     // paused
        $this->postJson("/api/billing/recurring/{$m['id']}/status", ['status' => 'active'])->assertOk();
        $this->assertSame('2027-02-01', RecurringInvoice::find($m['id'])->next_run_date->toDateString()); // skipped the paused December/January
        Carbon::setTestNow('2027-02-05 10:00:00');
        app(RecurringInvoiceService::class)->runDue();
        $this->assertSame('ended', RecurringInvoice::find($m['id'])->status);                // past the end date

        // Issuing fails (no GSTIN) → invoice kept as draft, reason shown on the schedule.
        BusinessProfile::current()->update(['gstin' => null]);
        $f = $this->schedule(['name' => 'Fails', 'start_date' => '2027-02-05']);
        $inv = Invoice::where('recurring_invoice_id', $f['id'])->first();
        $this->assertSame('draft', $inv->status);
        $this->assertStringContainsString('Created as draft, not issued', RecurringInvoice::find($f['id'])->last_error);
    }

    public function test_auto_email_cron_url_and_admin_page_trigger(): void
    {
        $this->postJson('/api/billing/recurring', ['name' => 'X', 'product_id' => 1, 'client_id' => $this->postJson('/api/billing/clients', ['name' => 'No mail', 'state_code' => '29', 'country' => 'India'])->json('client.id'),
            'frequency' => 'monthly', 'start_date' => '2026-11-15', 'auto_email' => true, 'items' => [['description' => 'x', 'quantity' => 1, 'rate' => 1, 'gst_rate' => 18]]])
            ->assertStatus(422)->assertJsonPath('error', fn ($e) => str_contains($e, 'no e-mail address'));

        $s = $this->schedule(['auto_email' => true, 'auto_issue' => false]);              // e-mail forces issuing
        $inv = Invoice::where('recurring_invoice_id', $s['id'])->first();
        $this->assertSame('issued', $inv->status);
        $this->assertNotNull($inv->emailed_at);                                        // logged (no Resend key in tests)

        // Secret cron URL.
        $url = $this->getJson('/api/billing/recurring/cron-url')->assertOk()->json('url');
        $this->assertSame($url, $this->getJson('/api/billing/recurring/cron-url')->json('url')); // stable
        Carbon::setTestNow('2026-12-16 08:00:00');
        auth()->logout();
        $this->getJson(parse_url($url, PHP_URL_PATH))->assertOk()->assertJsonPath('created', 1);
        $this->getJson('/cron/recurring/'.str_repeat('x', 40))->assertNotFound();

        // Opening an admin page runs due schedules (throttled).
        $this->actingAs(User::first());
        Carbon::setTestNow('2027-01-16 08:00:00');
        Cache::flush();
        $this->get('/admin/billing/recurring')->assertOk();
        $this->assertSame(3, Invoice::where('recurring_invoice_id', $s['id'])->count());

        $list = $this->getJson('/api/billing/recurring')->assertOk()->json();
        $this->assertEqualsWithDelta(10000, $list['summary']['monthly_value'], 0.001);
        $this->assertEqualsWithDelta(120000, $list['summary']['yearly_value'], 0.001);
    }
}

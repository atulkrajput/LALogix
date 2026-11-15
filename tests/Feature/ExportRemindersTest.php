<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Models\User;
use App\Services\Billing\AccountingExport;
use App\Services\Billing\PaymentReminderService;
use App\Support\Gstin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ExportRemindersTest extends TestCase
{
    use RefreshDatabase;

    private int $acme;
    private int $local;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->actingAs(User::create(['name' => 'A', 'email' => 'a@example.test', 'password' => Hash::make('x'), 'role' => 'admin']));
        $gstin = '29ABCDE1234F1Z'.Gstin::checkDigit('29ABCDE1234F1Z');
        $this->putJson('/api/billing/settings', ['legal_name' => 'LALogix Enterprises', 'gstin' => $gstin, 'state_code' => '29', 'payment_terms_days' => 15,
            'upi_id' => 'lalogix@upi', 'bank_account_number' => '1234567890', 'bank_ifsc' => 'HDFC0001234'])->assertOk();
        $buyer = '27AAACA1234A1Z'.Gstin::checkDigit('27AAACA1234A1Z');
        $this->acme = $this->postJson('/api/billing/clients', ['name' => 'Ravi', 'company' => 'Acme & Sons Pvt Ltd', 'gstin' => $buyer, 'email' => 'accounts@acme.test', 'country' => 'India'])->json('client.id');
        $this->local = $this->postJson('/api/billing/clients', ['name' => 'Local Shop', 'email' => 'shop@example.test', 'state_code' => '29', 'country' => 'India'])->json('client.id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(int $client, string $date, float $rate = 10000, ?string $due = null): Invoice
    {
        $id = $this->postJson('/api/billing/invoices', array_filter([
            'product_id' => 1, 'client_id' => $client, 'invoice_date' => $date, 'due_date' => $due,
            'items' => [['description' => 'Website work', 'sac' => '998314', 'quantity' => 1, 'rate' => $rate, 'gst_rate' => 18]],
        ]))->assertCreated()->json('invoice.id');
        $this->postJson("/api/billing/invoices/{$id}/issue")->assertOk();

        return Invoice::find($id);
    }

    public function test_accounting_pack_books_and_tally(): void
    {
        $a = $this->invoice($this->acme, '2026-11-02', 10000.40);              // inter-state, rounds off
        $b = $this->invoice($this->local, '2026-11-05', 5000);                 // intra-state
        $c = $this->invoice($this->local, '2026-11-06', 100);
        $this->postJson("/api/billing/invoices/{$c->id}/cancel", ['reason' => 'Duplicate'])->assertOk();
        $this->postJson("/api/billing/invoices/{$a->id}/payments", ['payment_date' => '2026-11-10', 'amount' => 10800, 'tds_amount' => 1000, 'method' => 'bank', 'reference' => 'UTR1'])->assertOk();
        $note = $this->postJson("/api/billing/invoices/{$b->id}/credit-note")->assertCreated()->json('invoice.id');
        $this->putJson("/api/billing/invoices/{$note}", ['product_id' => 1, 'client_id' => $this->local, 'invoice_date' => '2026-11-12', 'reason' => 'Discount',
            'items' => [['description' => 'Discount', 'sac' => '998314', 'quantity' => 1, 'rate' => 1000, 'gst_rate' => 18]]])->assertOk();
        $this->postJson("/api/billing/invoices/{$note}/issue")->assertOk();
        $this->postJson('/api/billing/purchases', ['vendor_name' => 'Hosting Co', 'category' => 'hosting', 'bill_number' => 'H-1', 'bill_date' => '2026-11-03',
            'taxable_value' => 1000, 'gst_rate' => 18, 'payment_status' => 'paid', 'paid_on' => '2026-11-04', 'payment_method' => 'bank'])->assertCreated();
        $this->postJson('/api/billing/purchases', ['vendor_name' => 'Facebook Ireland', 'vendor_country' => 'Ireland', 'category' => 'ads', 'bill_date' => '2026-11-08',
            'taxable_value' => 2000, 'gst_rate' => 18, 'payment_status' => 'paid', 'paid_on' => '2026-11-08', 'payment_method' => 'card'])->assertCreated();

        $preview = $this->getJson('/api/billing/export?from=2026-11-01&to=2026-11-30')->assertOk()->json();
        $this->assertSame(['invoices' => 2, 'credit_notes' => 1, 'cancelled' => 1, 'receipts' => 1, 'purchases' => 2, 'vendor_payments' => 2],
            array_intersect_key($preview['counts'], array_flip(['invoices', 'credit_notes', 'cancelled', 'receipts', 'purchases', 'vendor_payments'])));
        $this->assertSame('Books-2026-11.zip', $preview['filename']);

        $zip = $this->get('/admin/billing/export/download?from=2026-11-01&to=2026-11-30&parts[]=books&parts[]=gst&parts[]=tally&parts[]=pdfs')->assertOk();
        $this->assertSame('application/zip', $zip->headers->get('Content-Type'));
        $body = $zip->getContent();
        foreach (['Books-2026-11/Books-2026-11.xlsx', 'Books-2026-11/GST-report-2026-11.xlsx', 'Books-2026-11/Tally/1-Masters.xml', 'Books-2026-11/Tally/2-Vouchers.xml',
            'Books-2026-11/Invoices/LAL-26-27-0001.pdf', 'Books-2026-11/Credit notes/LALCN-26-27-0001.pdf', 'Books-2026-11/README.txt'] as $name) {
            $this->assertStringContainsString($name, $body);
        }

        $export = app(AccountingExport::class)->load(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
        [$masters, $vouchers] = $export->tally();
        foreach ([$masters, $vouchers] as $xml) {
            $this->assertNotFalse(simplexml_load_string($xml), 'Tally XML must be well-formed');
        }
        $this->assertStringContainsString('<LEDGER NAME="Acme &amp; Sons Pvt Ltd" ACTION="Create">', $masters);
        $this->assertStringContainsString('<PARENT>Sundry Debtors</PARENT>', $masters);
        $this->assertStringContainsString('<GSTDUTYHEAD>Integrated Tax</GSTDUTYHEAD>', $masters);
        $this->assertStringContainsString('<LEDGER NAME="Sales @ 18%"', $masters);

        // Every voucher balances (credits positive, debits negative) and the cancelled invoice is marked.
        $doc = simplexml_load_string($vouchers);
        $types = [];
        foreach ($doc->xpath('//VOUCHER') as $v) {
            $types[] = (string) $v['VCHTYPE'];
            $sum = 0.0;
            foreach ($v->xpath('ALLLEDGERENTRIES.LIST') as $e) {
                $sum += (float) $e->AMOUNT;
                $this->assertSame((float) $e->AMOUNT < 0 ? 'Yes' : 'No', (string) $e->ISDEEMEDPOSITIVE);
            }
            $this->assertEqualsWithDelta(0, $sum, 0.001, 'Voucher '.$v->VOUCHERNUMBER.' does not balance');
        }
        $this->assertSame(['Sales', 'Sales', 'Sales', 'Credit Note', 'Receipt', 'Purchase', 'Purchase', 'Payment', 'Payment'], $types);
        $sale = $doc->xpath('//VOUCHER[VOUCHERNUMBER="LAL/26-27/0001"]')[0];
        $this->assertSame('-11800.00', (string) $sale->xpath('ALLLEDGERENTRIES.LIST[LEDGERNAME="Acme & Sons Pvt Ltd"]')[0]->AMOUNT);
        $this->assertSame('1800.07', (string) $sale->xpath('ALLLEDGERENTRIES.LIST[LEDGERNAME="Output IGST"]')[0]->AMOUNT);
        $this->assertSame('-0.47', (string) $sale->xpath('ALLLEDGERENTRIES.LIST[LEDGERNAME="Round Off"]')[0]->AMOUNT);
        $this->assertSame('New Ref', (string) $sale->xpath('ALLLEDGERENTRIES.LIST/BILLALLOCATIONS.LIST/BILLTYPE')[0]);
        $this->assertCount(1, $doc->xpath('//VOUCHER[ISCANCELLED="Yes"]'));
        $receipt = $doc->xpath('//VOUCHER[@VCHTYPE="Receipt"]')[0];
        $this->assertSame('-1000.00', (string) $receipt->xpath('ALLLEDGERENTRIES.LIST[LEDGERNAME="TDS Receivable"]')[0]->AMOUNT);
        $this->assertSame('Agst Ref', (string) $receipt->xpath('.//BILLTYPE')[0]);
        // Reverse charge purchase: vendor gets the taxable value only, RCM payable is credited, ITC debited.
        $rcm = $doc->xpath('//VOUCHER[@VCHTYPE="Purchase"]')[1];
        $this->assertSame('2000.00', (string) $rcm->xpath('ALLLEDGERENTRIES.LIST[ISPARTYLEDGER="Yes"]')[0]->AMOUNT);
        $this->assertSame('360.00', (string) $rcm->xpath('ALLLEDGERENTRIES.LIST[LEDGERNAME="RCM Payable IGST"]')[0]->AMOUNT);
        $this->assertSame('-360.00', (string) $rcm->xpath('ALLLEDGERENTRIES.LIST[LEDGERNAME="Input IGST"]')[0]->AMOUNT);

        // Books open as a workbook with the expected sheets.
        $books = $export->books();
        $this->assertStringStartsWith('PK', $books);
        foreach (['Sales register', 'Credit notes', 'Receipts', 'Purchase register', 'Debtors 30-11-2026'] as $sheet) {
            $this->assertStringContainsString($sheet, $books);
        }

        $this->get('/admin/billing/export/download?from=2026-11-01&to=2026-11-30&parts[]=nope')->assertSessionHasErrors();
        $this->putJson('/api/billing/export/settings', ['tally_company' => 'LALogix Enterprises 26-27'])->assertOk();
        $this->assertStringContainsString('<SVCURRENTCOMPANY>LALogix Enterprises 26-27</SVCURRENTCOMPANY>', app(AccountingExport::class)->load(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'))->tally()[1]);
    }

    public function test_reminder_stages_and_safeguards(): void
    {
        $old = $this->invoice($this->acme, '2026-09-01', 10000, '2026-09-16');   // already 60 days late when switched on
        $soon = $this->invoice($this->local, '2026-11-15', 5000, '2026-11-20');
        $service = app(PaymentReminderService::class);

        $this->assertSame(0, $service->runDue()['sent']);                       // off by default
        $this->putJson('/api/billing/reminders/settings', ['reminders_enabled' => true, 'reminder_days' => '30, -3 ,7, 1, abc'])
            ->assertOk()->assertJsonPath('settings.reminder_days', '-3, 1, 7, 30')->assertJsonPath('settings.started_on', '2026-11-15');
        $this->assertSame(0, $service->runDue()['sent']);                       // no burst for stages before today

        Carbon::setTestNow('2026-11-17 09:00:00');                              // 3 days before $soon is due
        $r = $service->runDue();
        $this->assertSame(1, $r['sent']);
        $sent = InvoiceReminder::where('invoice_id', $soon->id)->first();
        $this->assertSame([-3, 'shop@example.test'], [$sent->stage, $sent->sent_to]);
        $this->assertStringContainsString('is due on 20 Nov 2026', $sent->subject);
        $this->assertSame(0, $service->runDue()['sent']);                       // once per stage

        // A gap: by the 28th the 1- and 7-day stages are both due; only the latest goes out.
        Carbon::setTestNow('2026-11-28 09:00:00');
        $this->assertSame(1, $service->runDue()['sent']);
        $this->assertSame([-3, 7], InvoiceReminder::where('invoice_id', $soon->id)->orderBy('id')->pluck('stage')->all());

        // The 60-day-late invoice gets its 30-day... no: that stage fell due before switch-on. Nothing until a manual reminder.
        $this->assertSame(0, InvoiceReminder::where('invoice_id', $old->id)->count());
        $preview = $this->getJson("/api/billing/invoices/{$old->id}/reminder")->assertOk()->json();
        $this->assertSame('accounts@acme.test', $preview['to']);
        $this->assertStringContainsString('was due on 16 Sep 2026 (73 days ago)', $preview['subject']);
        $this->assertStringContainsString('₹11,800.00', $preview['message']);
        $this->assertStringStartsWith('Dear Ravi,', $preview['message']);             // contact person, not the company
        $this->assertStringContainsString('UPI: lalogix@upi', $preview['message']);
        $this->postJson("/api/billing/invoices/{$old->id}/reminder", ['to' => 'ravi@acme.test', 'subject' => $preview['subject'], 'message' => $preview['message']])->assertOk();
        $this->assertNull(InvoiceReminder::where('invoice_id', $old->id)->first()->stage);

        // Quiet period after any reminder; paused invoices and opted-out clients are skipped.
        $this->assertNull($service->dueStage($soon->fresh('reminders'), BusinessProfile::current(), Carbon::parse('2026-11-29')));
        $this->postJson("/api/billing/invoices/{$soon->id}/reminders-paused", ['paused' => true])->assertOk();
        Client::whereKey($this->acme)->update(['no_reminders' => true]);
        Carbon::setTestNow('2026-12-25 09:00:00');                              // 30-day stage for $soon
        $this->assertSame(0, $service->runDue()['sent']);
        $this->postJson("/api/billing/invoices/{$soon->id}/reminders-paused", ['paused' => false])->assertOk();
        $this->assertSame(1, $service->runDue()['sent']);

        // Paid invoices stop getting reminders.
        $this->postJson("/api/billing/invoices/{$soon->id}/payments", ['payment_date' => '2026-12-25', 'amount' => 5900, 'method' => 'upi'])->assertOk();
        $this->assertCount(0, $service->openInvoices()->where('id', $soon->id));
    }

    public function test_receivables_ageing_and_triggers(): void
    {
        $this->invoice($this->acme, '2026-08-01', 10000, '2026-08-10');        // 97 days late → 90+
        $this->invoice($this->acme, '2026-10-20', 1000, '2026-11-01');         // 14 days late
        $this->invoice($this->local, '2026-11-15', 2000, '2026-11-30');        // not due

        $r = $this->getJson('/api/billing/receivables')->assertOk()->json();
        $this->assertSame(3, $r['summary']['count']);
        $this->assertEqualsWithDelta(11800, $r['summary']['d90_plus'], 0.001);
        $this->assertEqualsWithDelta(1180, $r['summary']['d1_30'], 0.001);
        $this->assertEqualsWithDelta(2360, $r['summary']['not_due'], 0.001);
        $this->assertEqualsWithDelta(12980, $r['summary']['overdue'], 0.001);
        $this->assertSame('Acme & Sons Pvt Ltd', $r['clients'][0]['name']);
        $this->assertSame(97, $r['clients'][0]['oldest_days']);
        $this->assertFalse($r['settings']['reminders_enabled']);

        $this->putJson('/api/billing/reminders/settings', ['reminders_enabled' => true, 'reminder_days' => '-3, 7'])->assertOk();
        $this->postJson('/api/billing/reminders/run')->assertOk()->assertJsonPath('result.sent', 0);
        $rows = collect($this->getJson('/api/billing/receivables')->json('invoices'))->keyBy('bucket');
        $this->assertSame('2026-11-27', $rows['not_due']['next_reminder']);    // due 30 Nov − 3

        // Cron URL and admin page loads also send reminders.
        $url = $this->getJson('/api/billing/recurring/cron-url')->json('url');
        Carbon::setTestNow('2026-11-27 08:00:00');
        auth()->logout();
        $this->getJson(parse_url($url, PHP_URL_PATH))->assertOk()->assertJsonPath('reminders_sent', 1);
        $this->actingAs(User::first());
        Carbon::setTestNow('2026-12-07 08:00:00');
        Cache::flush();
        $this->get('/admin/billing/receivables')->assertOk();
        $this->assertSame(2, InvoiceReminder::count());                         // 7-day stage for the November invoice

        $this->putJson('/api/billing/reminders/settings', ['reminders_enabled' => true, 'reminder_days' => 'none'])->assertStatus(422);
    }
}

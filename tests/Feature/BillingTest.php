<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use App\Support\Gstin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        $user = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => Hash::make('secret'), 'role' => 'admin']);
        $this->actingAs($user);
    }

    private function gstin(string $state, string $pan = 'ABCDE1234F'): string
    {
        $first = $state.$pan.'1Z';

        return $first.Gstin::checkDigit($first);
    }

    private function setUpProfile(string $state = '29'): void
    {
        $this->putJson('/api/billing/settings', [
            'legal_name' => 'LALogix Enterprises',
            'gstin' => $this->gstin($state),
            'state_code' => $state,
            'payment_terms_days' => 15,
            'round_off_total' => true,
        ])->assertOk();
    }

    private function createClient(array $overrides = []): int
    {
        return $this->postJson('/api/billing/clients', [
            'name' => 'Priya Sharma',
            'company' => 'Acme Pvt Ltd',
            'gstin' => $this->gstin('27', 'PQRST6789K'),
            'country' => 'India',
            ...$overrides,
        ])->assertCreated()->json('client.id');
    }

    private function draft(int $clientId, array $items = null, string $date = '2026-11-15'): array
    {
        return $this->postJson('/api/billing/invoices', [
            'product_id' => 1, // LALogix Services (prefix LAL), created by the migration
            'client_id' => $clientId,
            'invoice_date' => $date,
            'items' => $items ?? [['description' => 'Consulting', 'sac' => '998313', 'quantity' => 10, 'rate' => 1500, 'gst_rate' => 18]],
        ])->assertCreated()->json('invoice');
    }

    public function test_billing_api_requires_login(): void
    {
        $this->getJson('/api/billing/invoices')->assertStatus(401);
        $this->get('/admin/billing/invoices')->assertRedirect('/admin/login');
    }

    public function test_client_gstin_is_validated_and_sets_state_and_pan(): void
    {
        $this->login();

        $this->postJson('/api/billing/clients', ['name' => 'X', 'gstin' => '27AAPFU0939F1ZA', 'country' => 'India'])
            ->assertStatus(422)->assertJsonPath('error', 'That GSTIN is not valid (format or check digit).');

        $id = $this->createClient(['state_code' => '07']); // state is taken from the GSTIN, not the form
        $this->getJson("/api/billing/clients/{$id}")
            ->assertJsonPath('client.stateCode', '27')
            ->assertJsonPath('client.pan', 'PQRST6789K');
    }

    public function test_full_invoice_lifecycle(): void
    {
        $this->login();
        $this->setUpProfile('29');
        $clientId = $this->createClient(); // Maharashtra → inter-state from Karnataka

        // Draft: IGST, no number yet.
        $draft = $this->draft($clientId);
        $this->assertNull($draft['number']);
        $this->assertSame('inter', $draft['supplyType']);
        $this->assertEqualsWithDelta(15000, $draft['taxableTotal'], 0.001);
        $this->assertEqualsWithDelta(2700, $draft['igstTotal'], 0.001);
        $this->assertEqualsWithDelta(17700, $draft['grandTotal'], 0.001);
        $this->assertSame('2026-11-30', $draft['dueDate']);

        // Issue → first number in the FY series; a second invoice gets the next one.
        $issued = $this->postJson("/api/billing/invoices/{$draft['id']}/issue")->assertOk()->json('invoice');
        $this->assertSame('LAL/26-27/0001', $issued['number']);
        $this->assertSame('issued', $issued['status']);
        $second = $this->draft($clientId);
        $this->assertSame('LAL/26-27/0002', $this->postJson("/api/billing/invoices/{$second['id']}/issue")->json('invoice.number'));

        // Issued invoices are frozen.
        $this->putJson("/api/billing/invoices/{$draft['id']}", ['product_id' => 1, 'client_id' => $clientId, 'invoice_date' => '2026-11-15', 'items' => [['description' => 'x', 'quantity' => 1, 'rate' => 1, 'gst_rate' => 18]]])
            ->assertStatus(422);

        // Part payment with TDS.
        $this->postJson("/api/billing/invoices/{$draft['id']}/payments", ['payment_date' => '2026-11-20', 'amount' => 10000, 'tds_amount' => 1500, 'method' => 'bank'])
            ->assertOk()->assertJsonPath('invoice.paymentStatus', 'partial');
        $this->postJson("/api/billing/invoices/{$draft['id']}/payments", ['payment_date' => '2026-11-21', 'amount' => 99999, 'method' => 'bank'])
            ->assertStatus(422);

        // Credit note for the rest of the balance (6,200 incl. IGST → taxable 5,254.24…; use a simple line).
        $cn = $this->postJson("/api/billing/invoices/{$draft['id']}/credit-note")->assertCreated()->json('invoice');
        $this->assertSame('credit_note', $cn['type']);
        $this->putJson("/api/billing/invoices/{$cn['id']}", [
            'product_id' => 1, 'client_id' => $clientId, 'invoice_date' => '2026-11-25', 'reason' => 'Discount',
            'items' => [['description' => 'Discount on consulting', 'sac' => '998313', 'quantity' => 1, 'rate' => 5000, 'gst_rate' => 18]],
        ])->assertOk();
        $cnIssued = $this->postJson("/api/billing/invoices/{$cn['id']}/issue")->assertOk()->json('invoice');
        $this->assertSame('LALCN/26-27/0001', $cnIssued['number']);

        $invoice = $this->getJson("/api/billing/invoices/{$draft['id']}")->json('invoice');
        $this->assertEqualsWithDelta(5900, $invoice['amountCredited'], 0.001);
        $this->assertEqualsWithDelta(17700 - 11500 - 5900, $invoice['balanceDue'], 0.001);

        // Can't cancel while payments exist; the number stays used after cancelling.
        $this->postJson("/api/billing/invoices/{$draft['id']}/cancel", ['reason' => 'Error'])->assertStatus(422);
        $this->postJson("/api/billing/invoices/{$second['id']}/cancel", ['reason' => 'Duplicate'])->assertOk()->assertJsonPath('invoice.status', 'cancelled');
        $third = $this->draft($clientId);
        $this->assertSame('LAL/26-27/0003', $this->postJson("/api/billing/invoices/{$third['id']}/issue")->json('invoice.number'));

        // PDF renders.
        $pdf = $this->get("/admin/billing/invoices/{$draft['id']}/pdf");
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // List summary covers issued invoices only (cancelled excluded).
        $summary = $this->getJson('/api/billing/invoices')->json('summary');
        $this->assertEquals(2, $summary['count']);
    }

    public function test_intra_state_invoice_and_new_financial_year_series(): void
    {
        $this->login();
        $this->setUpProfile('27');
        $clientId = $this->createClient(); // also Maharashtra

        $march = $this->draft($clientId, null, '2027-03-31');
        $this->assertSame('intra', $march['supplyType']);
        $this->assertEqualsWithDelta(1350, $march['cgstTotal'], 0.001);
        $this->assertEqualsWithDelta(1350, $march['sgstTotal'], 0.001);
        $this->assertSame('LAL/26-27/0001', $this->postJson("/api/billing/invoices/{$march['id']}/issue")->json('invoice.number'));

        $april = $this->draft($clientId, null, '2027-04-01');
        $this->assertSame('LAL/27-28/0001', $this->postJson("/api/billing/invoices/{$april['id']}/issue")->json('invoice.number'));
    }

    public function test_work_items_link_to_invoice_and_are_released_on_cancel(): void
    {
        $this->login();
        $this->setUpProfile('29');
        $clientId = $this->createClient();

        $work = $this->postJson('/api/billing/work', [
            'client_id' => $clientId, 'product_id' => 1, 'title' => 'Website build', 'quantity' => 1, 'rate' => 40000, 'gst_rate' => 18,
        ])->assertCreated()->json('workItem');

        $draft = $this->draft($clientId, [['description' => 'Website build', 'quantity' => 1, 'rate' => 40000, 'gst_rate' => 18, 'work_item_id' => $work['id']]]);
        $this->getJson("/api/billing/work?status=unbilled")->assertJsonCount(0, 'workItems');
        $this->putJson("/api/billing/work/{$work['id']}", ['client_id' => $clientId, 'title' => 'x', 'quantity' => 1, 'rate' => 1, 'gst_rate' => 18])->assertStatus(422);

        $this->postJson("/api/billing/invoices/{$draft['id']}/issue")->assertOk();
        $this->postJson("/api/billing/invoices/{$draft['id']}/cancel", ['reason' => 'Wrong client'])->assertOk();
        $this->getJson("/api/billing/work?status=unbilled")->assertJsonCount(1, 'workItems');
    }

    public function test_issue_needs_gstin_and_product_prefix(): void
    {
        $this->login();
        $this->putJson('/api/billing/settings', ['legal_name' => 'LALogix', 'state_code' => '29', 'payment_terms_days' => 15])->assertOk();
        $clientId = $this->createClient();
        $draft = $this->draft($clientId);

        $this->postJson("/api/billing/invoices/{$draft['id']}/issue")->assertStatus(422)
            ->assertJsonPath('error', 'Add your GSTIN in Billing → Business profile before issuing.');

        $this->setUpProfile('29');
        $bivk = $this->postJson('/api/billing/invoices', [
            'product_id' => 2, 'client_id' => $clientId, 'invoice_date' => '2026-11-15', // BIVK.in has no prefix yet
            'items' => [['description' => 'Subscription', 'quantity' => 1, 'rate' => 999, 'gst_rate' => 18]],
        ])->json('invoice');
        $this->postJson("/api/billing/invoices/{$bivk['id']}/issue")->assertStatus(422)
            ->assertJsonPath('error', 'Set the invoice prefix for BIVK.in in Billing → Products first.');
        $this->assertSame(0, Invoice::whereNotNull('number')->count());
    }

    public function test_export_client_without_gstin(): void
    {
        $this->login();
        $this->setUpProfile('29');
        $this->putJson('/api/billing/settings', ['legal_name' => 'LALogix', 'gstin' => $this->gstin('29'), 'state_code' => '29', 'payment_terms_days' => 15, 'lut_number' => 'AD290000000000X'])->assertOk();
        $clientId = $this->createClient(['gstin' => null, 'country' => 'United States', 'company' => 'Globex Inc']);

        $draft = $this->draft($clientId);
        $this->assertSame('96', $draft['placeOfSupply']);
        $this->assertSame('export', $draft['supplyType']);
        $this->assertTrue($draft['exportWithLut']);
        $this->assertEqualsWithDelta(0, $draft['igstTotal'], 0.001);
    }
}

<?php

namespace Tests\Feature;

use App\Models\IngestLog;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Support\Gstin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IngestTest extends TestCase
{
    use RefreshDatabase;

    private string $key;

    private Product $vivk;

    private function gstin(string $state, string $pan): string
    {
        $first = $state.$pan.'1Z';

        return $first.Gstin::checkDigit($first);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::create(['name' => 'A', 'email' => 'a@example.test', 'password' => Hash::make('x'), 'role' => 'admin']);
        $this->actingAs($admin);
        $this->putJson('/api/billing/settings', ['legal_name' => 'LALogix', 'gstin' => $this->gstin('29', 'ABCDE1234F'), 'state_code' => '29', 'payment_terms_days' => 15])->assertOk();

        $this->vivk = Product::where('slug', 'vivk')->firstOrFail();
        $this->putJson("/api/billing/products/{$this->vivk->id}", ['name' => 'VIVK.in', 'kind' => 'saas', 'invoice_prefix' => 'VIVK', 'credit_note_prefix' => 'VIVCN', 'default_sac' => '998314', 'default_gst_rate' => 18])->assertOk();
        $this->key = $this->postJson("/api/billing/products/{$this->vivk->id}/api-key")->assertOk()->json('key');
        $this->assertStringStartsWith('lxk_', $this->key);

        // The ingest API is used without the admin session.
        auth()->logout();
        $this->flushSession();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'external_id' => 'ord_1001',
            'number' => 'VIVK/26-27/0001',
            'date' => '2026-11-05',
            'customer' => ['name' => 'Ravi Kumar', 'company' => 'Acme Pvt Ltd', 'gstin' => $this->gstin('27', 'PQRST6789K'), 'email' => 'ravi@example.com'],
            'items' => [['description' => 'Pro plan – annual', 'quantity' => 1, 'rate' => 9999, 'gst_rate' => 18]],
            'total' => 11798.82,
        ], $overrides);
    }

    private function ingest(string $path, array $body = [], ?string $key = null)
    {
        return $this->withHeader('Authorization', 'Bearer '.($key ?? $this->key))->postJson("/api/ingest/v1{$path}", $body);
    }

    public function test_api_key_is_required_and_hashed(): void
    {
        $this->postJson('/api/ingest/v1/invoices', $this->payload())->assertStatus(401);
        $this->ingest('/invoices', $this->payload(), 'lxk_wrong')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer '.$this->key)->getJson('/api/ingest/v1/ping')
            ->assertOk()->assertJsonPath('product', 'VIVK.in');
        $this->assertNotSame($this->key, $this->vivk->fresh()->api_key_hash);
        $this->assertSame(hash('sha256', $this->key), $this->vivk->fresh()->api_key_hash);
    }

    public function test_invoice_is_recorded_idempotently_and_conflicts_are_rejected(): void
    {
        $res = $this->ingest('/invoices', $this->payload())->assertCreated()
            ->assertJsonPath('outcome', 'created')->assertJsonPath('number', 'VIVK/26-27/0001')->assertJsonPath('supply_type', 'inter');
        $this->assertEqualsWithDelta(11798.82, $res->json('grand_total'), 0.001);

        $inv = Invoice::with('client')->findOrFail($res->json('id'));
        $this->assertSame('api', $inv->source);
        $this->assertSame('issued', $inv->status);
        $this->assertSame('VIVK', $inv->series);
        $this->assertSame(1, $inv->sequence);
        $this->assertSame('27', $inv->place_of_supply);
        $this->assertEqualsWithDelta(1799.82, $inv->igst_total, 0.001);
        $this->assertSame('Acme Pvt Ltd', $inv->bill_name);
        $this->assertSame($this->vivk->id, $inv->client->product_id);

        // Same document again: unchanged (safe retry), no duplicate.
        $this->ingest('/invoices', $this->payload())->assertOk()->assertJsonPath('outcome', 'unchanged');
        $this->assertSame(1, Invoice::count());

        // Same external_id, different amount: rejected.
        $this->ingest('/invoices', $this->payload(['items' => [['rate' => 5000]], 'total' => 5900]))->assertStatus(422)
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'already recorded as VIVK/26-27/0001'));

        // Second order for the same customer reuses the client (matched by GSTIN).
        $this->ingest('/invoices', $this->payload(['external_id' => 'ord_1002', 'number' => 'VIVK/26-27/0002']))->assertCreated();
        $this->assertSame(1, Invoice::distinct('client_id')->count('client_id'));

        $this->assertSame(['created', 'unchanged', 'rejected', 'created'], IngestLog::orderBy('id')->pluck('outcome')->all());
    }

    public function test_total_must_match_the_lines(): void
    {
        $this->ingest('/invoices', $this->payload(['total' => 9999]))->assertStatus(422)
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'does not match the lines'));

        // Within ₹1: accepted and stored exactly as the product issued it.
        $res = $this->ingest('/invoices', $this->payload(['total' => 11799]))->assertCreated();
        $inv = Invoice::find($res->json('id'));
        $this->assertEqualsWithDelta(11799, $inv->grand_total, 0.001);
        $this->assertEqualsWithDelta(0.18, $inv->round_off, 0.001);
    }

    public function test_b2c_customer_needs_a_state_and_number_must_be_unique(): void
    {
        $b2c = ['customer' => ['name' => 'Priya', 'gstin' => null, 'company' => null, 'email' => 'priya@example.com']];
        $this->ingest('/invoices', array_replace_recursive($this->payload($b2c), ['customer' => ['gstin' => null]]))->assertStatus(422)
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'place_of_supply'));

        $res = $this->ingest('/invoices', $this->payload(['external_id' => 'ord_2', 'customer' => ['gstin' => null, 'state_code' => '29'], 'total' => null]))->assertCreated();
        $this->assertSame('intra', $res->json('supply_type'));

        // A number already used (e.g. by an invoice issued in LALogix) is refused.
        $this->ingest('/invoices', $this->payload(['external_id' => 'ord_3', 'number' => 'VIVK/26-27/0001', 'total' => null]))->assertStatus(422)
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'already used'));
    }

    public function test_payment_credit_note_and_cancel(): void
    {
        $id = $this->ingest('/invoices', $this->payload(['payment' => ['date' => '2026-11-05', 'amount' => 11798.82, 'method' => 'upi', 'reference' => 'pay_1']]))
            ->assertCreated()->json('id');
        $this->assertSame('paid', Invoice::find($id)->payment_status);

        $note = [
            'external_id' => 'ref_1', 'number' => 'VIVCN/26-27/0001', 'date' => '2026-11-10', 'invoice_external_id' => 'ord_1001',
            'reason' => 'Partial refund', 'items' => [['description' => 'Refund', 'quantity' => 1, 'rate' => 1000, 'gst_rate' => 18]], 'total' => 1180,
        ];
        $this->ingest('/credit-notes', $note)->assertCreated()->assertJsonPath('number', 'VIVCN/26-27/0001');
        $this->assertEqualsWithDelta(1180, Invoice::find($id)->amount_credited, 0.001);
        $this->ingest('/credit-notes', ['external_id' => 'ref_2', 'number' => 'VIVCN/26-27/0002', 'invoice_external_id' => 'ord_1001', 'items' => [['description' => 'Refund', 'quantity' => 1, 'rate' => 99999, 'gst_rate' => 18]]] + $note)
            ->assertStatus(422);

        // Can't cancel an invoice with an active credit note; cancel the note first.
        $this->ingest('/invoices/ord_1001/cancel', ['reason' => 'Order cancelled'])->assertStatus(422);
        $this->ingest('/credit-notes/ref_1/cancel')->assertOk()->assertJsonPath('status', 'cancelled');
        $this->ingest('/invoices/ord_1001/cancel', ['reason' => 'Order cancelled'])->assertOk()->assertJsonPath('outcome', 'cancelled');
        $this->ingest('/invoices/ord_1001/cancel')->assertOk()->assertJsonPath('outcome', 'unchanged');
        $this->assertEqualsWithDelta(0, Invoice::find($id)->amount_paid, 0.001);
        $this->ingest('/invoices/nope/cancel')->assertStatus(422);
    }

    public function test_synced_invoices_are_read_only_in_admin_and_appear_in_gst_report(): void
    {
        $id = $this->ingest('/invoices', $this->payload())->json('id');

        $this->actingAs(User::first());
        $this->postJson("/api/billing/invoices/{$id}/cancel", ['reason' => 'x'])->assertStatus(422)
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'issued by VIVK.in'));
        $this->postJson("/api/billing/invoices/{$id}/credit-note")->assertStatus(422);
        $this->get("/admin/billing/invoices/{$id}/pdf")->assertNotFound();

        $report = $this->getJson('/api/billing/reports/gst?from=2026-11-01&to=2026-11-30')->assertOk()->json();
        $this->assertSame(['VIVK/26-27/0001'], array_column($report['b2b'], 'number'));
        $this->assertSame('VIVK', $report['docs'][0]['series']);
        $this->assertSame('VIVK.in', $report['by_product'][0]['product']);

        // Revoking the key stops the product.
        $this->deleteJson("/api/billing/products/{$this->vivk->id}/api-key")->assertOk();
        $this->ingest('/invoices', $this->payload(['external_id' => 'x']))->assertStatus(401);
    }

    public function test_csv_import(): void
    {
        $this->actingAs(User::first());
        $gstin = $this->gstin('27', 'PQRST6789K');
        $csv = "\xEF\xBB\xBFinvoice_number,invoice_date,customer_name,customer_company,customer_gstin,customer_state,description,sac,quantity,rate,gst_rate,invoice_total,paid_amount\n"
            ."VIVK/26-27/0101,01-11-2026,Ravi,Acme,{$gstin},,Pro plan,998314,1,9999,18%,11798.82,11798.82\n"
            ."VIVK/26-27/0101,01-11-2026,Ravi,Acme,{$gstin},,Setup fee,998314,1,1000,18,,\n"
            ."VIVK/26-27/0102,2026-11-02,Priya,,,Karnataka,Basic plan,998314,1,499,18,,\n"
            ."VIVK/26-27/0103,31/02/2026,Bad Date,,,29,Plan,998314,1,1,18,,\n"
            ."VIVK/26-27/0104,2026-11-03,No State,,,,Plan,998314,1,1,18,,\n";
        $file = UploadedFile::fake()->createWithContent('vivk.csv', $csv);

        $res = $this->post('/api/billing/import', ['product_id' => $this->vivk->id, 'file' => $file], ['Accept' => 'application/json'])->assertOk();
        // Two lines → one invoice; the stated total only covered the first line, so 0101 is rejected for the mismatch.
        $this->assertSame(1, $res->json('created'));
        $this->assertSame(3, $res->json('rejected'));
        $byNumber = collect($res->json('results'))->keyBy('number');
        $this->assertStringContainsString('does not match', $byNumber['VIVK/26-27/0101']['message']);
        $this->assertSame('created', $byNumber['VIVK/26-27/0102']['outcome']);
        $this->assertStringContainsString('not a date', $byNumber['VIVK/26-27/0103']['message']);
        $this->assertSame('29', Invoice::where('number', 'VIVK/26-27/0102')->value('place_of_supply'));
        $this->assertSame('import', Invoice::where('number', 'VIVK/26-27/0102')->value('source'));

        // Re-importing is safe.
        $again = $this->post('/api/billing/import', ['product_id' => $this->vivk->id, 'file' => UploadedFile::fake()->createWithContent('vivk.csv', $csv)], ['Accept' => 'application/json']);
        $this->assertSame(1, $again->json('unchanged'));

        $this->get('/admin/billing/import/template')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }
}

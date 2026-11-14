<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Gstin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GstReportTest extends TestCase
{
    use RefreshDatabase;

    private function gstin(string $state, string $pan): string
    {
        $first = $state.$pan.'1Z';

        return $first.Gstin::checkDigit($first);
    }

    private function client(array $data): int
    {
        return $this->postJson('/api/billing/clients', $data + ['country' => 'India'])->assertCreated()->json('client.id');
    }

    /** Draft + issue; returns the issued invoice. */
    private function invoice(int $clientId, float $rate, int $gst = 18, string $date = '2026-11-10', string $sac = '998313'): array
    {
        $id = $this->postJson('/api/billing/invoices', [
            'product_id' => 1, 'client_id' => $clientId, 'invoice_date' => $date,
            'items' => [['description' => 'Service', 'sac' => $sac, 'quantity' => 1, 'rate' => $rate, 'gst_rate' => $gst]],
        ])->assertCreated()->json('invoice.id');

        return $this->postJson("/api/billing/invoices/{$id}/issue")->assertOk()->json('invoice');
    }

    private function creditNote(int $invoiceId, float $rate, string $date = '2026-11-20'): array
    {
        $note = $this->postJson("/api/billing/invoices/{$invoiceId}/credit-note")->assertCreated()->json('invoice');
        $this->putJson("/api/billing/invoices/{$note['id']}", [
            'product_id' => 1, 'client_id' => $note['clientId'], 'invoice_date' => $date, 'reason' => 'Discount',
            'items' => [['description' => 'Discount', 'sac' => '998313', 'quantity' => 1, 'rate' => $rate, 'gst_rate' => 18]],
        ])->assertOk();

        return $this->postJson("/api/billing/invoices/{$note['id']}/issue")->assertOk()->json('invoice');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create(['name' => 'A', 'email' => 'a@example.test', 'password' => Hash::make('x'), 'role' => 'admin']));
        $this->putJson('/api/billing/settings', [
            'legal_name' => 'LALogix Enterprises', 'gstin' => $this->gstin('29', 'ABCDE1234F'), 'state_code' => '29',
            'payment_terms_days' => 15, 'lut_number' => 'AD2900000000001',
        ])->assertOk();
    }

    public function test_gstr1_classification_3b_and_documents(): void
    {
        $b2bClient = $this->client(['name' => 'Acme', 'gstin' => $this->gstin('27', 'PQRST6789K')]);
        $bigB2c = $this->client(['name' => 'Delhi Individual', 'state_code' => '07']);
        $localB2c = $this->client(['name' => 'Local Shop', 'state_code' => '29']);
        $foreign = $this->client(['name' => 'Globex', 'country' => 'United States']);

        $b2b = $this->invoice($b2bClient, 10000);            // inter-state B2B: IGST 1800
        $b2cl = $this->invoice($bigB2c, 100000);             // 1,18,000 > 1 lakh, inter-state → B2CL
        $b2csInter = $this->invoice($bigB2c, 1000);          // small inter-state → B2CS (07, 18%)
        $b2csIntra = $this->invoice($localB2c, 2000);        // intra → B2CS (29, CGST/SGST 180 each)
        $exp = $this->invoice($foreign, 5000);               // LUT export, no tax
        $cancelled = $this->invoice($localB2c, 999);
        $this->postJson("/api/billing/invoices/{$cancelled['id']}/cancel", ['reason' => 'Duplicate'])->assertOk();
        $this->invoice($localB2c, 700, 18, '2026-12-01');     // next month: excluded

        $this->creditNote($b2b['id'], 1000);                  // CDNR
        $this->creditNote($b2csIntra['id'], 500);             // reduces B2CS 29
        $this->creditNote($exp['id'], 500);                   // CDNUR EXPWOP

        // An undated-in-period draft triggers a warning.
        $this->postJson('/api/billing/invoices', ['product_id' => 1, 'client_id' => $localB2c, 'invoice_date' => '2026-11-15',
            'items' => [['description' => 'x', 'quantity' => 1, 'rate' => 1, 'gst_rate' => 18]]])->assertCreated();

        $r = $this->getJson('/api/billing/reports/gst?from=2026-11-01&to=2026-11-30')->assertOk()->json();

        $this->assertSame('November 2026', $r['period']['label']);
        $this->assertSame([$b2b['number']], array_column($r['b2b'], 'number'));
        $this->assertEqualsWithDelta(1800, $r['b2b'][0]['igst'], 0.001);
        $this->assertSame([$b2cl['number']], array_column($r['b2cl'], 'number'));
        $this->assertSame([$exp['number']], array_column($r['exp'], 'number'));
        $this->assertSame('WOPAY', $r['exp'][0]['export_type']);

        $b2cs = collect($r['b2cs'])->keyBy('place_of_supply');
        $this->assertEqualsWithDelta(1000, $b2cs['07']['taxable'], 0.001);
        $this->assertEqualsWithDelta(180, $b2cs['07']['igst'], 0.001);
        $this->assertEqualsWithDelta(1500, $b2cs['29']['taxable'], 0.001);   // 2000 - 500 credit note
        $this->assertEqualsWithDelta(135, $b2cs['29']['cgst'], 0.001);

        $this->assertCount(1, $r['cdnr']);
        $this->assertSame($b2b['number'], $r['cdnr'][0]['original_number']);
        $this->assertCount(1, $r['cdnur']);
        $this->assertSame('EXPWOP', $r['cdnur'][0]['ur_type']);

        // Documents: one invoice series (6 numbers in Nov, 1 cancelled), one credit-note series (3).
        $docs = collect($r['docs'])->keyBy('nature');
        $this->assertSame('LAL/26-27/0001', $docs['Invoices for outward supply']['from']);
        $this->assertSame('LAL/26-27/0006', $docs['Invoices for outward supply']['to']);
        $this->assertSame(6, $docs['Invoices for outward supply']['total']);
        $this->assertSame(1, $docs['Invoices for outward supply']['cancelled']);
        $this->assertSame(3, $docs['Credit Note']['total']);

        // GSTR-3B 3.1: taxable domestic = 10000+100000+1000+2000 - 1000 - 500 = 111500
        $g = $r['gstr3b'];
        $this->assertEqualsWithDelta(111500, $g['taxable_outward']['taxable'], 0.001);
        $this->assertEqualsWithDelta(1800 + 18000 + 180 - 180, $g['taxable_outward']['igst'], 0.001);
        $this->assertEqualsWithDelta(180 - 45, $g['taxable_outward']['cgst'], 0.001);
        $this->assertEqualsWithDelta(4500, $g['zero_rated']['taxable'], 0.001);
        $this->assertEqualsWithDelta(0, $g['zero_rated']['igst'], 0.001);
        $unreg = collect($g['interstate_unregistered'])->keyBy('place_of_supply');
        $this->assertEqualsWithDelta(101000, $unreg['07']['taxable'], 0.001);
        $this->assertEqualsWithDelta(19800 + 135 + 135, $g['tax_payable']['total'], 0.001);

        // HSN split: B2B (net of CDNR) vs B2C.
        $this->assertEqualsWithDelta(9000, $r['hsn_b2b'][0]['taxable'], 0.001);
        $this->assertSame('998313', $r['hsn_b2c'][0]['hsn']);

        $this->assertSame(5, $r['counts']['invoices']);
        $this->assertSame(3, $r['counts']['credit_notes']);
        $this->assertTrue(collect($r['warnings'])->contains(fn ($w) => str_contains($w['message'], '1 draft')));
        $this->assertSame('LALogix Services', $r['by_product'][0]['product']);
    }

    public function test_excel_downloads_are_valid_workbooks(): void
    {
        $clientId = $this->client(['name' => 'Acme', 'gstin' => $this->gstin('27', 'PQRST6789K')]);
        $this->invoice($clientId, 10000);

        foreach (['gstr1' => ['b2b,sez,de', 'hsn(b2b)', 'docs'], 'full' => ['Summary', 'b2b,sez,de']] as $format => $sheets) {
            $res = $this->get("/admin/billing/reports/download?from=2026-11-01&to=2026-11-30&format={$format}");
            $res->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $bin = $res->getContent();
            $this->assertStringStartsWith("PK\x03\x04", $bin);
            $this->assertStringContainsString('xl/workbook.xml', $bin);
            foreach ($sheets as $sheet) {
                $this->assertStringContainsString('name="'.htmlspecialchars($sheet, ENT_XML1).'"', $bin);
            }
            $this->assertStringContainsString('GSTIN/UIN of Recipient', $bin);
            $this->assertStringContainsString('27-Maharashtra', $bin);
        }
        $this->assertStringNotContainsString('name="Summary"', $this->get('/admin/billing/reports/download?from=2026-11-01&to=2026-11-30&format=gstr1')->getContent());
    }

    public function test_period_validation(): void
    {
        $this->getJson('/api/billing/reports/gst?from=2026-11-30&to=2026-11-01')->assertStatus(422);
        $this->getJson('/api/billing/reports/gst?from=2025-01-01&to=2026-11-01')->assertStatus(422)
            ->assertJsonPath('error', 'Choose a period of at most one year.');
    }
}

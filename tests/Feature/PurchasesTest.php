<?php

namespace Tests\Feature;

use App\Models\Gstr2bEntry;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Gstin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurchasesTest extends TestCase
{
    use RefreshDatabase;

    private function gstin(string $state, string $pan): string
    {
        $first = $state.$pan.'1Z';

        return $first.Gstin::checkDigit($first);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create(['name' => 'A', 'email' => 'a@example.test', 'password' => Hash::make('x'), 'role' => 'admin']));
        $this->putJson('/api/billing/settings', ['legal_name' => 'LALogix', 'gstin' => $this->gstin('29', 'ABCDE1234F'), 'state_code' => '29', 'payment_terms_days' => 15])->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function purchase(array $data): array
    {
        return $this->postJson('/api/billing/purchases', $data + ['category' => 'software', 'bill_date' => '2026-11-05', 'gst_rate' => 18])
            ->assertCreated()->json('purchase');
    }

    public function test_gst_split_itc_rules_and_reverse_charge(): void
    {
        // Same state (29): CGST + SGST, claimable.
        $local = $this->purchase(['vendor_name' => 'Local Hosting', 'vendor_gstin' => $this->gstin('29', 'HOSTS1234K'), 'bill_number' => 'H-1', 'taxable_value' => 1000]);
        $this->assertEquals(['intra', 90.0, 90.0, 0.0, 1180.0, true], [$local['supplyType'], $local['cgst'], $local['sgst'], $local['igst'], $local['total'], $local['itcEligible']]);

        // Other state: IGST.
        $inter = $this->purchase(['vendor_name' => 'Delhi Freelancer', 'vendor_gstin' => $this->gstin('07', 'FREEL1234K'), 'bill_number' => 'F/9', 'taxable_value' => 5000, 'category' => 'professional']);
        $this->assertEquals(['inter', 900.0, 5900.0], [$inter['supplyType'], $inter['igst'], $inter['total']]);

        // Foreign supplier: import under reverse charge; GST not paid to the supplier but claimable.
        $import = $this->purchase(['vendor_name' => 'Cloud Inc', 'vendor_country' => 'United States', 'bill_number' => 'INV-77', 'taxable_value' => 2000]);
        $this->assertEquals(['import', true, 360.0, 2000.0, true], [$import['supplyType'], $import['reverseCharge'], $import['igst'], $import['total'], $import['itcEligible']]);

        // No GSTIN: GST is paid but can't be claimed.
        $noGst = $this->purchase(['vendor_name' => 'Cafe', 'bill_number' => '12', 'taxable_value' => 500, 'gst_rate' => 5, 'category' => 'travel']);
        $this->assertFalse($noGst['itcEligible']);
        $this->assertEqualsWithDelta(525, $noGst['total'], 0.001);

        // Exact figures from the bill.
        $manual = $this->purchase(['vendor_name' => 'Local Hosting', 'bill_number' => 'H-2', 'taxable_value' => 999, 'tax_mode' => 'manual', 'cgst' => 89.91, 'sgst' => 89.91]);
        $this->assertEqualsWithDelta(1178.82, $manual['total'], 0.001);
        $this->assertSame(1, Vendor::where('name', 'Local Hosting')->count());     // reused, not duplicated

        // Same bill twice is refused; bad GSTIN too.
        $this->postJson('/api/billing/purchases', ['vendor_name' => 'Local Hosting', 'bill_number' => 'H-1', 'category' => 'software', 'bill_date' => '2026-11-06', 'taxable_value' => 1, 'gst_rate' => 18])
            ->assertStatus(422)->assertJsonPath('error', 'Bill H-1 from Local Hosting is already recorded.');
        $this->postJson('/api/billing/purchases', ['vendor_name' => 'X', 'vendor_gstin' => '29ABCDE1234F1ZA', 'category' => 'software', 'bill_date' => '2026-11-06', 'taxable_value' => 1, 'gst_rate' => 18])
            ->assertStatus(422);

        $summary = $this->getJson('/api/billing/purchases?from=2026-11-01&to=2026-11-30')->assertOk()->json('summary');
        $this->assertSame(5, $summary['count']);
        $this->assertEqualsWithDelta(180 + 900 + 360 + 179.82, $summary['itc'], 0.001);
    }

    public function test_attachment_upload_download_and_delete(): void
    {
        Storage::fake('local');
        $p = $this->post('/api/billing/purchases', [
            'vendor_name' => 'Landlord', 'category' => 'rent', 'bill_date' => '2026-11-01', 'taxable_value' => 20000, 'gst_rate' => 0,
            'attachment' => UploadedFile::fake()->create('rent-nov.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('purchase');
        $this->assertTrue($p['hasAttachment']);
        $this->assertArrayNotHasKey('attachmentPath', $p);
        $path = Purchase::find($p['id'])->attachment_path;
        Storage::disk('local')->assertExists($path);

        $this->get("/admin/billing/purchases/{$p['id']}/attachment")->assertOk();
        $this->post('/api/billing/purchases', ['vendor_name' => 'Landlord', 'category' => 'rent', 'bill_date' => '2026-11-01', 'taxable_value' => 1, 'gst_rate' => 0,
            'attachment' => UploadedFile::fake()->create('virus.exe', 10)], ['Accept' => 'application/json'])->assertStatus(422);

        $this->deleteJson("/api/billing/purchases/{$p['id']}")->assertOk();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_gstr3b_itc_net_payable_and_growth_profit(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        // Sales: intra-state ₹10,000 → CGST 900 + SGST 900.
        $client = $this->postJson('/api/billing/clients', ['name' => 'Acme', 'state_code' => '29', 'country' => 'India'])->json('client.id');
        $inv = $this->postJson('/api/billing/invoices', ['product_id' => 1, 'client_id' => $client, 'invoice_date' => '2026-11-10',
            'items' => [['description' => 'Work', 'quantity' => 1, 'rate' => 10000, 'gst_rate' => 18]]])->json('invoice.id');
        $this->postJson("/api/billing/invoices/{$inv}/issue")->assertOk();

        $this->purchase(['vendor_name' => 'Local Hosting', 'vendor_gstin' => $this->gstin('29', 'HOSTS1234K'), 'bill_number' => 'H-1', 'taxable_value' => 1000]); // ITC 90+90
        $this->purchase(['vendor_name' => 'Cloud Inc', 'vendor_country' => 'United States', 'bill_number' => 'INV-77', 'taxable_value' => 2000]);       // RCM IGST 360
        $this->purchase(['vendor_name' => 'Cafe', 'bill_number' => '12', 'taxable_value' => 500, 'gst_rate' => 5, 'category' => 'travel']);            // no ITC: 25 GST is a cost

        $g = $this->getJson('/api/billing/reports/gst?from=2026-11-01&to=2026-11-30')->assertOk()->json('gstr3b');
        $this->assertEqualsWithDelta(360, $g['inward_reverse_charge']['igst'], 0.001);
        $this->assertEqualsWithDelta(360, $g['itc_reverse_charge']['igst'], 0.001);
        $this->assertEqualsWithDelta(90, $g['itc_other']['cgst'], 0.001);
        $this->assertEqualsWithDelta(540, $g['itc_total']['total'], 0.001);
        // IGST: 0 output + 360 RCM − 360 ITC = 0; CGST/SGST: 900 − 90 = 810 each.
        $this->assertEqualsWithDelta(0, $g['net_payable']['igst'], 0.001);
        $this->assertEqualsWithDelta(810, $g['net_payable']['cgst'], 0.001);
        $this->assertEqualsWithDelta(1620, $g['net_payable']['total'], 0.001);
        $this->assertEqualsWithDelta(180, $g['itc_not_in_2b'], 0.001);
        $this->assertSame(3, $g['purchases_count']);

        $k = $this->getJson('/api/billing/growth?range=12m')->assertOk()->json();
        // Expenses: 1000 + 2000 + (500 + 25 unclaimable GST) = 3525; profit 10000 − 3525.
        $this->assertEqualsWithDelta(3525, $k['kpis']['range_expenses'], 0.001);
        $this->assertEqualsWithDelta(6475, $k['kpis']['range_profit'], 0.001);
        $this->assertEqualsWithDelta(6475, collect($k['months'])->firstWhere('month', '2026-11')['profit'], 0.001);
        $this->assertSame('software', $k['expenses_by_category'][0]['category']);
    }

    private function gstr2bJson(): string
    {
        $hosting = $this->gstin('29', 'HOSTS1234K');
        $freelancer = $this->gstin('07', 'FREEL1234K');
        $newSupplier = $this->gstin('27', 'NEWSP1234K');

        return json_encode(['chksum' => 'x', 'data' => ['gstin' => $this->gstin('29', 'ABCDE1234F'), 'rtnprd' => '112026', 'docdata' => [
            'b2b' => [
                ['ctin' => $hosting, 'trdnm' => 'Local Hosting', 'inv' => [
                    ['inum' => 'H/1', 'dt' => '05-11-2026', 'val' => 1180, 'pos' => '29', 'rev' => 'N', 'itcavl' => 'Y',
                        'items' => [['num' => 1, 'rt' => 18, 'txval' => 1000, 'igst' => 0, 'cgst' => 90, 'sgst' => 90, 'cess' => 0]]],
                ]],
                ['ctin' => $freelancer, 'trdnm' => 'Delhi Freelancer', 'inv' => [
                    ['inum' => 'F-9', 'dt' => '06-11-2026', 'val' => 5310, 'pos' => '29', 'rev' => 'N', 'itcavl' => 'Y',
                        'items' => [['num' => 1, 'rt' => 18, 'txval' => 4500, 'igst' => 810, 'cgst' => 0, 'sgst' => 0, 'cess' => 0]]],
                ]],
                ['ctin' => $newSupplier, 'trdnm' => 'New Supplier', 'inv' => [
                    ['inum' => 'NS-5', 'dt' => '08-11-2026', 'val' => 590, 'pos' => '29', 'rev' => 'N', 'itcavl' => 'Y',
                        'items' => [['num' => 1, 'rt' => 18, 'txval' => 500, 'igst' => 90, 'cgst' => 0, 'sgst' => 0, 'cess' => 0]]],
                ]],
            ],
            'cdnr' => [
                ['ctin' => $hosting, 'trdnm' => 'Local Hosting', 'nt' => [
                    ['ntnum' => 'CN1', 'dt' => '15-11-2026', 'val' => 118, 'typ' => 'C', 'rev' => 'N', 'itcavl' => 'Y',
                        'items' => [['txval' => 100, 'igst' => 0, 'cgst' => 9, 'sgst' => 9, 'cess' => 0]]],
                ]],
            ],
        ]]]);
    }

    public function test_gstr2b_import_and_reconciliation(): void
    {
        $this->purchase(['vendor_name' => 'Local Hosting', 'vendor_gstin' => $this->gstin('29', 'HOSTS1234K'), 'bill_number' => 'H-1', 'taxable_value' => 1000]);          // matches "H/1"
        $this->purchase(['vendor_name' => 'Delhi Freelancer', 'vendor_gstin' => $this->gstin('07', 'FREEL1234K'), 'bill_number' => 'F/9', 'taxable_value' => 5000]);       // 2B says 4500 → mismatch
        $this->purchase(['vendor_name' => 'Slow Supplier', 'vendor_gstin' => $this->gstin('29', 'SLOWS1234K'), 'bill_number' => 'S-1', 'taxable_value' => 300]);          // not in 2B → at risk

        $file = UploadedFile::fake()->createWithContent('returns_R2B.json', $this->gstr2bJson());
        $r = $this->post('/api/billing/gstr2b/import', ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json();

        $this->assertSame('112026', $r['period']);
        $this->assertSame(4, $r['result']['imported']);
        $byDoc = collect($r['entries'])->keyBy('docNumber');
        $this->assertSame('matched', $byDoc['H/1']['matchStatus']);
        $this->assertSame('mismatch', $byDoc['F-9']['matchStatus']);
        $this->assertSame('unmatched', $byDoc['NS-5']['matchStatus']);
        $this->assertSame('credit_note', $byDoc['CN1']['docType']);
        $this->assertSame(['S-1'], array_column($r['books_only'], 'billNumber'));
        $this->assertEqualsWithDelta(180 + 810 + 90 - 18, $r['summary']['itc_in_2b'], 0.001);
        $this->assertEqualsWithDelta(180, $r['summary']['itc_matched'], 0.001);
        $this->assertEqualsWithDelta(54, $r['summary']['itc_at_risk'], 0.001);
        $this->assertSame('matched', Purchase::where('bill_number', 'H-1')->value('gstr2b_status'));
        $this->assertSame('mismatch', Purchase::where('bill_number', 'F/9')->value('gstr2b_status'));

        // Add the missing supplier bill from 2B.
        $entry = Gstr2bEntry::where('doc_number', 'NS-5')->first();
        $this->postJson("/api/billing/gstr2b/entries/{$entry->id}/purchase", ['category' => 'office'])->assertCreated();
        $added = Purchase::where('bill_number', 'NS-5')->first();
        $this->assertSame(['New Supplier', 'inter', 'unpaid', 18.0], [$added->vendor->name, $added->supply_type, $added->payment_status, $added->gst_rate]);
        $this->assertEqualsWithDelta(590, $added->total, 0.001);
        $this->postJson("/api/billing/gstr2b/entries/{$entry->id}/purchase")->assertStatus(422);

        // Re-importing the period replaces it (no duplicates).
        $this->post('/api/billing/gstr2b/import', ['file' => UploadedFile::fake()->createWithContent('r.json', $this->gstr2bJson())], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(4, Gstr2bEntry::count());
        $this->assertSame('matched', Gstr2bEntry::where('doc_number', 'NS-5')->value('match_status'));   // now in books

        $this->post('/api/billing/gstr2b/import', ['file' => UploadedFile::fake()->createWithContent('x.json', '{"foo":1}')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error', fn ($e) => str_contains($e, "doesn't look like a GSTR-2B"));
        $this->getJson('/api/billing/gstr2b')->assertOk()->assertJsonPath('period', '112026');
    }
}

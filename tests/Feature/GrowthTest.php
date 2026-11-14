<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Gstin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GrowthTest extends TestCase
{
    use RefreshDatabase;

    private function gstin(string $state, string $pan): string
    {
        $first = $state.$pan.'1Z';

        return $first.Gstin::checkDigit($first);
    }

    private function issue(int $productId, int $clientId, string $date, float $rate): array
    {
        $id = $this->postJson('/api/billing/invoices', [
            'product_id' => $productId, 'client_id' => $clientId, 'invoice_date' => $date,
            'items' => [['description' => 'Work', 'sac' => '998313', 'quantity' => 1, 'rate' => $rate, 'gst_rate' => 18]],
        ])->assertCreated()->json('invoice.id');

        return $this->postJson("/api/billing/invoices/{$id}/issue")->assertOk()->json('invoice');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_growth_metrics_across_products(): void
    {
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->actingAs(User::create(['name' => 'A', 'email' => 'a@example.test', 'password' => Hash::make('x'), 'role' => 'admin']));
        $this->putJson('/api/billing/settings', ['legal_name' => 'LALogix', 'gstin' => $this->gstin('29', 'ABCDE1234F'), 'state_code' => '29', 'payment_terms_days' => 15])->assertOk();
        $this->putJson('/api/billing/products/2', ['name' => 'VIVK.in', 'kind' => 'saas', 'invoice_prefix' => 'VIVK', 'default_gst_rate' => 18])->assertOk();
        $a = $this->postJson('/api/billing/clients', ['name' => 'Acme', 'gstin' => $this->gstin('27', 'PQRST6789K'), 'country' => 'India'])->json('client.id');
        $b = $this->postJson('/api/billing/clients', ['name' => 'Priya', 'state_code' => '29', 'country' => 'India'])->json('client.id');

        $vivkOld = $this->issue(2, $b, '2025-11-10', 2000);        // comparison period only
        $oct = $this->issue(1, $a, '2026-10-10', 10000);            // due 25 Oct → 21 days late on 15 Nov
        $nov = $this->issue(1, $a, '2026-11-05', 5000);             // due 20 Nov → not yet due
        $this->issue(2, $b, '2026-11-02', 1000);                     // VIVK, due 17 Nov

        // Credit note today against the October invoice (counts in November), and a November payment.
        $cn = $this->postJson("/api/billing/invoices/{$oct['id']}/credit-note")->json('invoice');
        $this->putJson("/api/billing/invoices/{$cn['id']}", ['product_id' => 1, 'client_id' => $a, 'invoice_date' => '2026-11-15', 'reason' => 'Discount',
            'items' => [['description' => 'Discount', 'sac' => '998313', 'quantity' => 1, 'rate' => 1000, 'gst_rate' => 18]]])->assertOk();
        $this->postJson("/api/billing/invoices/{$cn['id']}/issue")->assertOk();
        $this->postJson("/api/billing/invoices/{$nov['id']}/payments", ['payment_date' => '2026-11-10', 'amount' => 5400, 'tds_amount' => 500, 'method' => 'bank'])->assertOk();
        $this->postJson("/api/billing/invoices/{$vivkOld['id']}/payments", ['payment_date' => '2025-11-10', 'amount' => 2360, 'method' => 'upi'])->assertOk();

        $g = $this->getJson('/api/billing/growth?range=12m')->assertOk()->json();

        $this->assertSame('2025-12-01', $g['range']['from']);
        $this->assertSame('2024-12-01', $g['range']['compare_from']);
        $this->assertCount(12, $g['months']);
        $months = collect($g['months'])->keyBy('month');
        $this->assertEqualsWithDelta(10000, $months['2026-10']['revenue'], 0.001);
        $this->assertEqualsWithDelta(5000, $months['2026-11']['revenue'], 0.001);            // 5000 + 1000 − 1000 credit note
        $this->assertEqualsWithDelta(1000, $months['2026-11']['by_product'][2], 0.001);
        $this->assertEqualsWithDelta(5900, $months['2026-11']['collected'], 0.001);           // incl. TDS
        $this->assertSame(1, $months['2026-10']['new_customers']);                            // Acme's first invoice
        $this->assertSame(0, $months['2026-11']['new_customers']);                            // Priya was first billed in 2025
        $this->assertSame(1, $months['2026-11']['saas_customers']);

        $k = $g['kpis'];
        $this->assertEqualsWithDelta(5000, $k['month_revenue'], 0.001);
        $this->assertEqualsWithDelta(10000, $k['last_month_revenue_to_date'], 0.001);        // 1–15 Oct
        $this->assertEqualsWithDelta(-50.0, $k['month_change_pct'], 0.001);
        $this->assertEqualsWithDelta(15000, $k['range_revenue'], 0.001);
        $this->assertEqualsWithDelta(2000, $k['prev_range_revenue'], 0.001);
        $this->assertEqualsWithDelta(650.0, $k['range_change_pct'], 0.001);
        $this->assertEqualsWithDelta(5900, $k['month_collected'], 0.001);
        // Owed: Oct 11800 − 1180 credit = 10620 (overdue) + VIVK Nov 1180 (due 17th). Nov invoice is paid.
        $this->assertEqualsWithDelta(11800, $k['outstanding'], 0.001);
        $this->assertEqualsWithDelta(10620, $k['overdue'], 0.001);
        $this->assertSame(2, $k['customers']);

        $byProduct = collect($g['by_product'])->keyBy('name');
        $this->assertEqualsWithDelta(14000, $byProduct['LALogix Services']['revenue'], 0.001);
        $this->assertEqualsWithDelta(93.3, $byProduct['LALogix Services']['share_pct'], 0.001);
        $this->assertEqualsWithDelta(-50.0, $byProduct['VIVK.in']['change_pct'], 0.001);      // 1000 vs 2000
        $this->assertNull($byProduct['LALogix Services']['change_pct']);                      // nothing to compare with

        $this->assertSame('Acme', $g['top_clients'][0]['name']);
        $this->assertEqualsWithDelta(14000, $g['top_clients'][0]['revenue'], 0.001);
        $this->assertEqualsWithDelta(10620, $g['aging']['d1_30'], 0.001);
        $this->assertEqualsWithDelta(1180, $g['aging']['not_due'], 0.001);

        // Financial year compares with the same dates a year earlier.
        $fy = $this->getJson('/api/billing/growth?range=fy')->assertOk()->json();
        $this->assertSame(['2026-04-01', '2026-11-15', '2025-04-01', '2025-11-15'],
            [$fy['range']['from'], $fy['range']['to'], $fy['range']['compare_from'], $fy['range']['compare_to']]);
        $this->assertEqualsWithDelta(2000, $fy['kpis']['prev_range_revenue'], 0.001);
        $this->assertCount(8, $fy['months']);

        $this->getJson('/api/billing/growth?range=nope')->assertStatus(422);
    }

    public function test_requires_login_and_handles_no_data(): void
    {
        $this->getJson('/api/billing/growth')->assertStatus(401);
        $this->actingAs(User::create(['name' => 'A', 'email' => 'a@example.test', 'password' => Hash::make('x'), 'role' => 'admin']));
        $g = $this->getJson('/api/billing/growth')->assertOk()->json();
        $this->assertSame(0, $g['kpis']['customers']);
        $this->assertNull($g['kpis']['range_change_pct']);
        $this->assertSame([], $g['by_product']);
        $this->get('/admin/growth')->assertOk();
    }
}

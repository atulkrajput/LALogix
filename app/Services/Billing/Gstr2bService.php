<?php

namespace App\Services\Billing;

use App\Models\Gstr2bEntry;
use App\Models\Purchase;
use App\Models\Vendor;
use App\Support\Gstin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * GSTR-2B: the statement the GST portal builds from what suppliers reported.
 * Import the JSON download (Returns → GSTR-2B → Download → JSON), then reconcile
 * against the purchase bills recorded here:
 *   matched   – same supplier GSTIN + bill number, taxable value and GST within ₹1
 *   mismatch  – same document, different amounts
 *   2B only   – the supplier reported it, but it isn't in your books
 *   books only – in your books, but the supplier hasn't reported it (ITC at risk)
 */
class Gstr2bService
{
    private const TOLERANCE = 1.0;

    /** @return array{period: string, imported: int} */
    public function import(string $json): array
    {
        $doc = json_decode($json, true);
        if (! is_array($doc)) {
            throw ValidationException::withMessages(['file' => 'That file is not valid JSON. Download GSTR-2B as JSON from the GST portal.']);
        }
        $data = $doc['data'] ?? $doc;
        $period = (string) ($data['rtnprd'] ?? '');
        $docdata = $data['docdata'] ?? null;
        if (! preg_match('/^(0[1-9]|1[0-2])\d{4}$/', $period) || ! is_array($docdata)) {
            throw ValidationException::withMessages(['file' => "This doesn't look like a GSTR-2B JSON (no return period / document data)."]);
        }

        $rows = [];
        foreach ((array) ($docdata['b2b'] ?? []) as $supplier) {
            foreach ((array) ($supplier['inv'] ?? []) as $inv) {
                $rows[] = $this->row($period, $supplier, $inv, 'invoice', (string) ($inv['inum'] ?? ''));
            }
        }
        foreach ((array) ($docdata['cdnr'] ?? []) as $supplier) {
            foreach ((array) ($supplier['nt'] ?? []) as $note) {
                $type = strtoupper((string) ($note['typ'] ?? 'C')) === 'D' ? 'debit_note' : 'credit_note';
                $rows[] = $this->row($period, $supplier, $note, $type, (string) ($note['ntnum'] ?? ''));
            }
        }
        $rows = array_filter($rows, fn ($r) => $r['doc_number'] !== '' && $r['supplier_gstin'] !== '');

        DB::transaction(function () use ($period, $rows) {
            // Re-importing a period replaces it (the portal regenerates 2B).
            Gstr2bEntry::where('return_period', $period)->delete();
            foreach ($rows as $r) {
                Gstr2bEntry::create($r);
            }
        });
        $this->reconcile($period);

        return ['period' => $period, 'imported' => count($rows)];
    }

    /** Match a period's 2B entries with purchases; updates both sides. */
    public function reconcile(string $period): void
    {
        $entries = Gstr2bEntry::where('return_period', $period)->get();
        $gstins = $entries->pluck('supplier_gstin')->unique()->all();
        $candidates = Purchase::whereIn('vendor_gstin', $gstins ?: ['-'])->whereNotNull('bill_number')->get()
            ->groupBy(fn ($p) => $p->vendor_gstin.'|'.self::normalize($p->bill_number));

        foreach ($entries as $e) {
            $purchase = $candidates->get($e->supplier_gstin.'|'.self::normalize($e->doc_number))?->first();
            if (! $purchase || $e->doc_type !== 'invoice') {
                $e->update(['purchase_id' => null, 'match_status' => 'unmatched']);
                continue;
            }
            $same = abs($purchase->taxable_value - $e->taxable_value) <= self::TOLERANCE
                && abs($purchase->tax_total - $e->tax_total) <= self::TOLERANCE;
            $status = $same ? 'matched' : 'mismatch';
            $e->update(['purchase_id' => $purchase->id, 'match_status' => $status]);
            $purchase->update(['gstr2b_status' => $status]);
        }
    }

    /** @return array<string, mixed> */
    public function report(string $period): array
    {
        $entries = Gstr2bEntry::with('purchase:id,bill_number,bill_date,taxable_value,cgst,sgst,igst,vendor_id')
            ->where('return_period', $period)->orderBy('supplier_name')->orderBy('doc_date')->get();

        // Books-only: ITC-claimable bills from GSTIN suppliers dated in that month, not found in this 2B.
        $month = Carbon::createFromFormat('!mY', $period);
        $matchedIds = $entries->pluck('purchase_id')->filter()->all();
        $booksOnly = Purchase::with('vendor:id,name')
            ->whereNotNull('vendor_gstin')->where('itc_eligible', true)->where('reverse_charge', false)
            ->whereDate('bill_date', '>=', $month->copy()->startOfMonth()->toDateString())
            ->whereDate('bill_date', '<=', $month->copy()->endOfMonth()->toDateString())
            ->whereNotIn('id', $matchedIds ?: [0])
            ->orderBy('bill_date')->get();

        $tax = fn ($rows) => round($rows->sum(fn ($r) => $r->igst + $r->cgst + $r->sgst), 2);
        $available = $entries->where('itc_available', true)->where('doc_type', 'invoice');

        return [
            'period' => $period,
            'label' => $month->format('F Y'),
            'entries' => $entries,
            'books_only' => $booksOnly,
            'summary' => [
                'documents' => $entries->count(),
                'matched' => $entries->where('match_status', 'matched')->count(),
                'mismatch' => $entries->where('match_status', 'mismatch')->count(),
                'unmatched' => $entries->where('match_status', 'unmatched')->count(),
                'books_only' => $booksOnly->count(),
                'itc_in_2b' => $tax($available) - $tax($entries->where('doc_type', 'credit_note')),
                'itc_matched' => $tax($entries->where('match_status', 'matched')->where('itc_available', true)),
                'itc_at_risk' => $tax($booksOnly),
            ],
            'periods' => Gstr2bEntry::query()->select('return_period')->distinct()->pluck('return_period')
                ->sortByDesc(fn ($p) => substr($p, 2).substr($p, 0, 2))->values(),
        ];
    }

    /** Record a 2B document that is missing from the books as a purchase. */
    public function createPurchase(Gstr2bEntry $e, string $category): Purchase
    {
        $vendor = Vendor::firstOrCreate(['gstin' => $e->supplier_gstin], [
            'name' => $e->supplier_name ?: $e->supplier_gstin,
            'state_code' => Gstin::stateCode($e->supplier_gstin),
            'country' => 'India',
            'default_category' => $category,
        ]);
        $taxable = $e->taxable_value;
        $tax = $e->tax_total;

        $purchase = Purchase::create([
            'vendor_id' => $vendor->id,
            'category' => $vendor->default_category ?: $category,
            'bill_number' => $e->doc_number,
            'bill_date' => $e->doc_date ?? Carbon::createFromFormat('!mY', $e->return_period)->toDateString(),
            'description' => 'Added from GSTR-2B '.$e->return_period,
            'vendor_gstin' => $e->supplier_gstin,
            'supply_type' => $e->igst > 0 ? 'inter' : ($tax > 0 ? 'intra' : 'none'),
            'reverse_charge' => $e->reverse_charge,
            'taxable_value' => $taxable,
            'gst_rate' => $taxable > 0 ? $this->nearestRate($tax / $taxable * 100) : 0,
            'cgst' => $e->cgst,
            'sgst' => $e->sgst,
            'igst' => $e->igst,
            'total' => round($taxable + ($e->reverse_charge ? 0 : $tax), 2),
            'itc_eligible' => $e->itc_available,
            'payment_status' => 'unpaid',
            'gstr2b_status' => 'matched',
        ]);
        $e->update(['purchase_id' => $purchase->id, 'match_status' => 'matched']);

        return $purchase;
    }

    /** "INV/001", "inv-001" and "INV 001" are the same bill. */
    public static function normalize(?string $number): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $number));
    }

    private function row(string $period, array $supplier, array $doc, string $type, string $number): array
    {
        $items = (array) ($doc['items'] ?? []);
        $sum = fn (string $k) => $items ? array_sum(array_map(fn ($i) => (float) ($i[$k] ?? 0), $items)) : (float) ($doc[$k] ?? 0);
        $date = null;
        if (! empty($doc['dt'])) {
            try {
                $date = Carbon::createFromFormat('!d-m-Y', $doc['dt'])->toDateString();
            } catch (\Throwable) {
            }
        }

        return [
            'return_period' => $period,
            'supplier_gstin' => strtoupper(trim((string) ($supplier['ctin'] ?? ''))),
            'supplier_name' => isset($supplier['trdnm']) ? mb_substr((string) $supplier['trdnm'], 0, 255) : null,
            'doc_type' => $type,
            'doc_number' => mb_substr(trim($number), 0, 50),
            'doc_date' => $date,
            'doc_value' => round((float) ($doc['val'] ?? 0), 2),
            'taxable_value' => round($sum('txval'), 2),
            'igst' => round($sum('igst'), 2),
            'cgst' => round($sum('cgst'), 2),
            'sgst' => round($sum('sgst'), 2),
            'cess' => round($sum('cess'), 2),
            'reverse_charge' => strtoupper((string) ($doc['rev'] ?? 'N')) === 'Y',
            'itc_available' => strtoupper((string) ($doc['itcavl'] ?? 'Y')) !== 'N',
        ];
    }

    private function nearestRate(float $rate): float
    {
        $rates = [0, 5, 12, 18, 28];
        usort($rates, fn ($a, $b) => abs($a - $rate) <=> abs($b - $rate));

        return (float) $rates[0];
    }
}

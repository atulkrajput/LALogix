<?php

namespace App\Services\Billing;

use App\Models\BusinessProfile;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Support\GstStates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Consolidated GSTR-1 / GSTR-3B data for a period, across every product
 * (all business lines file under the one GSTIN).
 *
 * Classification (per invoice, using the buyer snapshot on the invoice):
 *  - B2B   : buyer has a GSTIN
 *  - EXP   : place of supply 96 (outside India): WPAY with IGST, WOPAY under LUT
 *  - B2CL  : unregistered, inter-state, invoice value above B2CL_LIMIT
 *  - B2CS  : every other unregistered supply, summarised by place of supply + rate
 * Credit notes go to CDNR (registered) / CDNUR (against B2CL or exports); notes
 * against B2CS invoices reduce the B2CS summary, as the portal expects.
 */
class GstReportService
{
    /** Inter-state B2C invoices above this value are reported invoice-wise (B2CL). Rs 1 lakh since Aug 2024. */
    public const B2CL_LIMIT = 100000;

    /** @return array<string, mixed> */
    public function build(Carbon $from, Carbon $to): array
    {
        $docs = Invoice::with(['items', 'product:id,name', 'originalInvoice.items'])
            ->whereIn('status', ['issued', 'cancelled'])
            ->whereDate('invoice_date', '>=', $from->toDateString())
            ->whereDate('invoice_date', '<=', $to->toDateString())
            ->orderBy('invoice_date')->orderBy('id')
            ->get();

        $issued = $docs->where('status', 'issued');
        $invoices = $issued->where('type', Invoice::TYPE_INVOICE)->values();
        $notes = $issued->where('type', Invoice::TYPE_CREDIT_NOTE)->values();

        $b2b = $b2cl = $exp = $cdnr = $cdnur = [];
        $b2cs = [];
        $hsn = ['b2b' => [], 'b2c' => []];

        foreach ($invoices as $inv) {
            $category = $this->category($inv);
            foreach ($this->rateRows($inv) as $row) {
                $line = $this->invoiceLine($inv) + $row;
                match ($category) {
                    'b2b' => $b2b[] = $line,
                    'b2cl' => $b2cl[] = $line,
                    'exp' => $exp[] = $line + ['export_type' => $inv->export_with_lut ? 'WOPAY' : 'WPAY'],
                    default => $this->addB2cs($b2cs, $inv, $row, 1),
                };
            }
            $this->addHsn($hsn[$category === 'b2b' ? 'b2b' : 'b2c'], $inv, 1);
        }

        foreach ($notes as $note) {
            $original = $note->originalInvoice;
            $category = $original ? $this->category($original) : ($note->bill_gstin ? 'b2b' : 'b2cs');
            foreach ($this->rateRows($note) as $row) {
                $line = $this->invoiceLine($note) + $row + [
                    'original_number' => $original?->number,
                    'original_date' => $original?->invoice_date?->toDateString(),
                ];
                if ($category === 'b2b') {
                    $cdnr[] = $line;
                } elseif ($category === 'b2cs') {
                    $this->addB2cs($b2cs, $note, $row, -1);
                } else {
                    $cdnur[] = $line + ['ur_type' => $category === 'exp' ? ($note->export_with_lut ? 'EXPWOP' : 'EXPWP') : 'B2CL'];
                }
            }
            $this->addHsn($hsn[$category === 'b2b' ? 'b2b' : 'b2c'], $note, -1);
        }

        $b2csRows = collect($b2cs)->sortBy(['place_of_supply', 'rate'])->values()->map(fn ($r) => $this->rounded($r))->all();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'label' => $this->label($from, $to)],
            'seller' => $this->seller(),
            'b2b' => $b2b,
            'b2cl' => $b2cl,
            'b2cs' => $b2csRows,
            'exp' => $exp,
            'cdnr' => $cdnr,
            'cdnur' => $cdnur,
            'hsn_b2b' => $this->hsnRows($hsn['b2b']),
            'hsn_b2c' => $this->hsnRows($hsn['b2c']),
            'docs' => $this->documents($docs),
            'gstr3b' => ($g = $this->gstr3b($invoices, $notes)) + $this->itc($from, $to, $g['tax_payable']),
            'by_product' => $this->byProduct($invoices, $notes),
            'counts' => [
                'invoices' => $invoices->count(),
                'credit_notes' => $notes->count(),
                'cancelled' => $docs->where('status', 'cancelled')->count(),
            ],
            'warnings' => $this->warnings($from, $to, $issued),
            'states' => GstStates::ALL + [GstStates::OUTSIDE_INDIA => 'Outside India'],
        ];
    }

    /** b2b | b2cl | b2cs | exp */
    private function category(Invoice $inv): string
    {
        if ($inv->place_of_supply === GstStates::OUTSIDE_INDIA || $inv->supply_type === 'export') {
            return 'exp';
        }
        if ($inv->bill_gstin) {
            return 'b2b';
        }
        if ($inv->supply_type === 'inter' && $inv->grand_total > self::B2CL_LIMIT) {
            return 'b2cl';
        }

        return 'b2cs';
    }

    /** Taxable value and tax per GST rate for one document. @return list<array<string, float>> */
    private function rateRows(Invoice $inv): array
    {
        return $inv->items->groupBy(fn ($i) => (string) (float) $i->gst_rate)
            ->map(fn (Collection $rows, $rate) => [
                'rate' => (float) $rate,
                'taxable' => round($rows->sum('taxable_value'), 2),
                'igst' => round($rows->sum('igst'), 2),
                'cgst' => round($rows->sum('cgst'), 2),
                'sgst' => round($rows->sum('sgst'), 2),
                'cess' => 0.0,
            ])->sortKeys()->values()->all();
    }

    /** @return array<string, mixed> */
    private function invoiceLine(Invoice $inv): array
    {
        return [
            'id' => $inv->id,
            'number' => $inv->number,
            'date' => $inv->invoice_date->toDateString(),
            'gstin' => $inv->bill_gstin,
            'name' => $inv->bill_name,
            'value' => (float) $inv->grand_total,
            'place_of_supply' => $inv->place_of_supply,
            'reverse_charge' => (bool) $inv->reverse_charge,
            'product' => $inv->product?->name,
        ];
    }

    private function addB2cs(array &$b2cs, Invoice $inv, array $row, int $sign): void
    {
        $key = $inv->place_of_supply.'|'.$row['rate'];
        $b2cs[$key] ??= ['type' => 'OE', 'place_of_supply' => $inv->place_of_supply, 'supply_type' => $inv->supply_type === 'intra' ? 'INTRA' : 'INTER', 'rate' => $row['rate'], 'taxable' => 0.0, 'igst' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'cess' => 0.0];
        foreach (['taxable', 'igst', 'cgst', 'sgst'] as $k) {
            $b2cs[$key][$k] += $sign * $row[$k];
        }
    }

    private function addHsn(array &$hsn, Invoice $inv, int $sign): void
    {
        foreach ($inv->items as $item) {
            $rate = (float) $item->gst_rate;
            $key = ($item->sac ?: 'NA').'|'.$rate;
            $hsn[$key] ??= ['hsn' => $item->sac ?: '', 'description' => '', 'uqc' => 'NA', 'quantity' => 0.0, 'value' => 0.0, 'rate' => $rate, 'taxable' => 0.0, 'igst' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'cess' => 0.0];
            $hsn[$key]['description'] = $hsn[$key]['description'] ?: mb_substr(strtok($item->description, "\n"), 0, 30);
            $hsn[$key]['value'] += $sign * $item->amount;
            $hsn[$key]['taxable'] += $sign * $item->taxable_value;
            $hsn[$key]['igst'] += $sign * $item->igst;
            $hsn[$key]['cgst'] += $sign * $item->cgst;
            $hsn[$key]['sgst'] += $sign * $item->sgst;
        }
    }

    /** @return list<array<string, mixed>> */
    private function hsnRows(array $hsn): array
    {
        return collect($hsn)->sortBy(['hsn', 'rate'])->values()->map(fn ($r) => $this->rounded($r))->all();
    }

    /** Table 13: one row per series, with the number range, total and cancelled count. */
    private function documents(Collection $docs): array
    {
        return $docs->whereNotNull('number')->groupBy(fn ($d) => $d->type.'|'.$d->series)->map(function (Collection $rows) {
            $sorted = $rows->sortBy('sequence');
            $first = $sorted->first();

            return [
                'nature' => $first->type === Invoice::TYPE_CREDIT_NOTE ? 'Credit Note' : 'Invoices for outward supply',
                'series' => $first->series,
                'from' => $first->number,
                'to' => $sorted->last()->number,
                'total' => $rows->count(),
                'cancelled' => $rows->where('status', 'cancelled')->count(),
                'net_issued' => $rows->where('status', 'issued')->count(),
            ];
        })->sortBy([fn ($a, $b) => strcmp($b['nature'], $a['nature']), fn ($a, $b) => strcmp($a['series'], $b['series'])])->values()->all(); // invoices first
    }

    /** GSTR-3B tables 3.1 and 3.2, net of credit notes. */
    private function gstr3b(Collection $invoices, Collection $notes): array
    {
        $blank = fn () => ['taxable' => 0.0, 'igst' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'cess' => 0.0];
        $t = ['taxable_outward' => $blank(), 'zero_rated' => $blank(), 'nil_exempt' => $blank(), 'reverse_charge_outward' => $blank()];
        $unregistered = [];

        $add = function (Invoice $doc, int $sign) use (&$t, &$unregistered) {
            foreach ($doc->items as $item) {
                $bucket = match (true) {
                    $doc->supply_type === 'export' => 'zero_rated',
                    (float) $item->gst_rate === 0.0 => 'nil_exempt',
                    (bool) $doc->reverse_charge => 'reverse_charge_outward',
                    default => 'taxable_outward',
                };
                $t[$bucket]['taxable'] += $sign * $item->taxable_value;
                $t[$bucket]['igst'] += $sign * $item->igst;
                $t[$bucket]['cgst'] += $sign * $item->cgst;
                $t[$bucket]['sgst'] += $sign * $item->sgst;

                if (! $doc->bill_gstin && $doc->supply_type === 'inter') {
                    $pos = $doc->place_of_supply;
                    $unregistered[$pos] ??= ['place_of_supply' => $pos, 'taxable' => 0.0, 'igst' => 0.0];
                    $unregistered[$pos]['taxable'] += $sign * $item->taxable_value;
                    $unregistered[$pos]['igst'] += $sign * $item->igst;
                }
            }
        };
        $invoices->each(fn ($i) => $add($i, 1));
        $notes->each(fn ($n) => $add($n, -1));

        $t = array_map(fn ($r) => $this->rounded($r), $t);
        $payable = [
            'igst' => round($t['taxable_outward']['igst'] + $t['zero_rated']['igst'], 2),
            'cgst' => $t['taxable_outward']['cgst'],
            'sgst' => $t['taxable_outward']['sgst'],
        ];

        return [
            ...$t,
            'interstate_unregistered' => collect($unregistered)->sortKeys()->values()->map(fn ($r) => $this->rounded($r))->all(),
            'tax_payable' => $payable + ['total' => round(array_sum($payable), 2)],
        ];
    }

    /**
     * Purchases side of GSTR-3B for the period (bills dated in it):
     *  3.1(d) inward supplies liable to reverse charge – tax we owe on RCM purchases
     *  4A(3)  ITC on those RCM purchases;  4A(5) all other eligible ITC
     *  net    output tax + RCM tax − ITC, per head (negative = credit to carry forward;
     *         the portal's cross-utilisation order IGST→CGST/SGST is not applied here).
     */
    private function itc(Carbon $from, Carbon $to, array $outputTax): array
    {
        $purchases = Purchase::query()->whereDate('bill_date', '>=', $from->toDateString())->whereDate('bill_date', '<=', $to->toDateString())
            ->get(['taxable_value', 'cgst', 'sgst', 'igst', 'reverse_charge', 'itc_eligible', 'gstr2b_status']);
        $sum = fn ($rows) => $this->rounded([
            'taxable' => (float) $rows->sum('taxable_value'),
            'igst' => (float) $rows->sum('igst'), 'cgst' => (float) $rows->sum('cgst'), 'sgst' => (float) $rows->sum('sgst'), 'cess' => 0.0,
        ]);
        $rcm = $purchases->where('reverse_charge', true);
        $rcmItc = $rcm->where('itc_eligible', true);
        $otherItc = $purchases->where('reverse_charge', false)->where('itc_eligible', true);

        $liability = $sum($rcm);
        $credit = ['igst' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0];
        foreach ([$sum($rcmItc), $sum($otherItc)] as $part) {
            foreach ($credit as $head => $_) {
                $credit[$head] += $part[$head];
            }
        }
        $net = [];
        foreach ($credit as $head => $c) {
            $net[$head] = round(($outputTax[$head] ?? 0) + $liability[$head] - $c, 2);
        }

        return [
            'inward_reverse_charge' => $liability,
            'itc_reverse_charge' => $sum($rcmItc),
            'itc_other' => $sum($otherItc),
            'itc_total' => array_map(fn ($v) => round($v, 2), $credit) + ['total' => round(array_sum($credit), 2)],
            'itc_not_in_2b' => round($otherItc->filter(fn ($p) => $p->gstr2b_status !== 'matched')->sum(fn ($p) => $p->cgst + $p->sgst + $p->igst), 2),
            'net_payable' => $net + ['total' => round(array_sum($net), 2)],
            'purchases_count' => $purchases->count(),
        ];
    }

    private function byProduct(Collection $invoices, Collection $notes): array
    {
        $rows = [];
        foreach ([[1, $invoices], [-1, $notes]] as [$sign, $set]) {
            foreach ($set as $doc) {
                $name = $doc->product?->name ?? '—';
                $rows[$name] ??= ['product' => $name, 'invoices' => 0, 'credit_notes' => 0, 'taxable' => 0.0, 'tax' => 0.0, 'total' => 0.0];
                $rows[$name][$sign > 0 ? 'invoices' : 'credit_notes']++;
                $rows[$name]['taxable'] += $sign * $doc->taxable_total;
                $rows[$name]['tax'] += $sign * ($doc->cgst_total + $doc->sgst_total + $doc->igst_total);
                $rows[$name]['total'] += $sign * $doc->grand_total;
            }
        }

        return collect($rows)->sortByDesc('total')->values()->map(fn ($r) => $this->rounded($r))->all();
    }

    /** Things to fix before filing. @return list<array{level: string, message: string}> */
    private function warnings(Carbon $from, Carbon $to, Collection $issued): array
    {
        $w = [];
        $profile = BusinessProfile::current();
        if (! $profile->gstin) {
            $w[] = ['level' => 'error', 'message' => 'Your GSTIN is not set in Business profile.'];
        }
        $drafts = Invoice::where('status', 'draft')->whereDate('invoice_date', '>=', $from->toDateString())->whereDate('invoice_date', '<=', $to->toDateString())->count();
        if ($drafts) {
            $w[] = ['level' => 'warning', 'message' => "{$drafts} draft document(s) dated in this period are not issued and are left out of the report."];
        }
        $noSac = $issued->filter(fn ($d) => $d->items->contains(fn ($i) => ! $i->sac))->pluck('number')->filter()->values();
        if ($noSac->isNotEmpty()) {
            $w[] = ['level' => 'warning', 'message' => 'Lines without SAC/HSN (the HSN summary needs one): '.$noSac->take(10)->implode(', ').($noSac->count() > 10 ? '…' : '')];
        }
        $rcm = $issued->where('reverse_charge', true)->count();
        if ($rcm) {
            $w[] = ['level' => 'info', 'message' => "{$rcm} document(s) are under reverse charge: the recipient pays that tax, so it is not in your tax payable."];
        }

        return $w;
    }

    private function seller(): array
    {
        $p = BusinessProfile::current();

        return ['legal_name' => $p->legal_name, 'trade_name' => $p->trade_name, 'gstin' => $p->gstin, 'state_code' => $p->state_code];
    }

    private function label(Carbon $from, Carbon $to): string
    {
        if ($from->isSameMonth($to) && $from->day === 1 && $to->isLastOfMonth()) {
            return $from->format('F Y');
        }

        return $from->format('d M Y').' – '.$to->format('d M Y');
    }

    private function rounded(array $row): array
    {
        return array_map(fn ($v) => is_float($v) ? round($v, 2) + 0.0 : $v, $row);
    }
}

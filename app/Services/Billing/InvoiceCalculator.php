<?php

namespace App\Services\Billing;

/**
 * GST maths for one document. Works in paise (integers) so totals always add up.
 *
 *  - intra-state supply: CGST + SGST, each half the rate
 *  - inter-state supply: IGST at the full rate
 *  - export under LUT:   zero-rated (no tax); export without LUT: IGST
 */
class InvoiceCalculator
{
    /**
     * @param  list<array{quantity: float|int|string, rate: float|int|string, discount?: float|int|string|null, gst_rate: float|int|string}>  $lines
     * @return array{lines: list<array<string, float>>, totals: array<string, float>}
     */
    public static function calculate(array $lines, string $supplyType, bool $exportWithLut = false, bool $roundOff = true): array
    {
        $out = [];
        $sum = ['taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];

        foreach ($lines as $line) {
            $qty = (float) $line['quantity'];
            $rate = (float) $line['rate'];
            $gstRate = (float) $line['gst_rate'];
            $discount = self::paise($line['discount'] ?? 0);

            $taxable = max(0, (int) round($qty * $rate * 100) - $discount);
            $cgst = $sgst = $igst = 0;

            if ($supplyType === 'intra') {
                $cgst = $sgst = (int) round($taxable * $gstRate / 200);
            } elseif ($supplyType === 'inter' || ($supplyType === 'export' && ! $exportWithLut)) {
                $igst = (int) round($taxable * $gstRate / 100.0);
            }

            $sum['taxable'] += $taxable;
            $sum['cgst'] += $cgst;
            $sum['sgst'] += $sgst;
            $sum['igst'] += $igst;

            $out[] = [
                'taxable_value' => $taxable / 100.0,
                'discount' => $discount / 100.0,
                'cgst' => $cgst / 100.0,
                'sgst' => $sgst / 100.0,
                'igst' => $igst / 100.0,
                'amount' => ($taxable + $cgst + $sgst + $igst) / 100.0,
            ];
        }

        $gross = $sum['taxable'] + $sum['cgst'] + $sum['sgst'] + $sum['igst'];
        $grand = $roundOff ? (int) round($gross / 100.0) * 100 : $gross;

        return [
            'lines' => $out,
            'totals' => [
                'taxable_total' => $sum['taxable'] / 100.0,
                'cgst_total' => $sum['cgst'] / 100.0,
                'sgst_total' => $sum['sgst'] / 100.0,
                'igst_total' => $sum['igst'] / 100.0,
                'round_off' => ($grand - $gross) / 100.0,
                'grand_total' => $grand / 100.0,
            ],
        ];
    }

    /** intra / inter / export from the seller's state and the place of supply. */
    public static function supplyType(string $sellerState, string $placeOfSupply): string
    {
        if ($placeOfSupply === \App\Support\GstStates::OUTSIDE_INDIA) {
            return 'export';
        }

        return $placeOfSupply === $sellerState ? 'intra' : 'inter';
    }

    /** Indian financial year (April-March) for a date, e.g. 2026-27. */
    public static function financialYear(\DateTimeInterface $date): string
    {
        $year = (int) $date->format('Y');
        $start = (int) $date->format('n') >= 4 ? $year : $year - 1;

        return $start.'-'.substr((string) ($start + 1), -2);
    }

    private static function paise(float|int|string|null $rupees): int
    {
        return (int) round(((float) $rupees) * 100);
    }
}

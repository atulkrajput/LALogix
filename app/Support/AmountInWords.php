<?php

namespace App\Support;

/** Rupees in words using the Indian system (thousand, lakh, crore). */
class AmountInWords
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function rupees(float $amount): string
    {
        $paise = (int) round(abs($amount) * 100);
        $rupees = intdiv($paise, 100);
        $paise %= 100;

        $words = 'Indian Rupees '.($rupees === 0 ? 'Zero' : self::number($rupees));
        if ($paise > 0) {
            $words .= ' and '.self::number($paise).' Paise';
        }

        return $words.' Only';
    }

    private static function number(int $n): string
    {
        $parts = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$size, $name]) {
            if ($n >= $size) {
                $count = intdiv($n, $size);
                // Crores can exceed 99 (e.g. 150 crore), so recurse for the count.
                $parts[] = ($size === 10000000 ? self::number($count) : self::belowHundred($count)).' '.$name;
                $n %= $size;
            }
        }
        if ($n > 0) {
            $parts[] = self::belowHundred($n);
        }

        return implode(' ', $parts);
    }

    private static function belowHundred(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }

        return trim(self::TENS[intdiv($n, 10)].' '.self::ONES[$n % 10]);
    }
}

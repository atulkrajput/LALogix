<?php

namespace App\Support;

/** GSTIN format + check-digit validation (offline; it doesn't confirm the GSTIN is registered). */
class Gstin
{
    private const CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public static function normalize(?string $gstin): ?string
    {
        $gstin = strtoupper(preg_replace('/\s+/', '', (string) $gstin));

        return $gstin === '' ? null : $gstin;
    }

    public static function isValid(?string $gstin): bool
    {
        $gstin = self::normalize($gstin);
        if ($gstin === null || ! preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gstin)) {
            return false;
        }
        if (! GstStates::valid(substr($gstin, 0, 2))) {
            return false;
        }

        return $gstin[14] === self::checkDigit(substr($gstin, 0, 14));
    }

    public static function checkDigit(string $first14): string
    {
        $sum = 0;
        for ($i = 0; $i < 14; $i++) {
            $product = strpos(self::CHARS, $first14[$i]) * ($i % 2 === 0 ? 1 : 2);
            $sum += intdiv($product, 36) + ($product % 36);
        }

        return self::CHARS[(36 - ($sum % 36)) % 36];
    }

    public static function stateCode(?string $gstin): ?string
    {
        $gstin = self::normalize($gstin);

        return $gstin ? substr($gstin, 0, 2) : null;
    }

    /** PAN is characters 3-12 of the GSTIN. */
    public static function pan(?string $gstin): ?string
    {
        $gstin = self::normalize($gstin);

        return $gstin ? substr($gstin, 2, 10) : null;
    }
}

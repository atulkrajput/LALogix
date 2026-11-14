<?php

namespace App\Support;

/** GST state / union territory codes (first two digits of a GSTIN, and the place-of-supply codes). */
class GstStates
{
    public const OUTSIDE_INDIA = '96';

    public const ALL = [
        '01' => 'Jammu and Kashmir',
        '02' => 'Himachal Pradesh',
        '03' => 'Punjab',
        '04' => 'Chandigarh',
        '05' => 'Uttarakhand',
        '06' => 'Haryana',
        '07' => 'Delhi',
        '08' => 'Rajasthan',
        '09' => 'Uttar Pradesh',
        '10' => 'Bihar',
        '11' => 'Sikkim',
        '12' => 'Arunachal Pradesh',
        '13' => 'Nagaland',
        '14' => 'Manipur',
        '15' => 'Mizoram',
        '16' => 'Tripura',
        '17' => 'Meghalaya',
        '18' => 'Assam',
        '19' => 'West Bengal',
        '20' => 'Jharkhand',
        '21' => 'Odisha',
        '22' => 'Chhattisgarh',
        '23' => 'Madhya Pradesh',
        '24' => 'Gujarat',
        '26' => 'Dadra and Nagar Haveli and Daman and Diu',
        '27' => 'Maharashtra',
        '29' => 'Karnataka',
        '30' => 'Goa',
        '31' => 'Lakshadweep',
        '32' => 'Kerala',
        '33' => 'Tamil Nadu',
        '34' => 'Puducherry',
        '35' => 'Andaman and Nicobar Islands',
        '36' => 'Telangana',
        '37' => 'Andhra Pradesh',
        '38' => 'Ladakh',
        '97' => 'Other Territory',
    ];

    public static function name(?string $code): string
    {
        if ($code === self::OUTSIDE_INDIA) {
            return 'Outside India';
        }

        return self::ALL[$code] ?? (string) $code;
    }

    public static function valid(?string $code): bool
    {
        return $code !== null && isset(self::ALL[$code]);
    }

    /** "29 - Karnataka" style label used on invoices. */
    public static function label(?string $code): string
    {
        return $code ? $code.' - '.self::name($code) : '';
    }

    /** @return list<array{code: string, name: string}> */
    public static function options(): array
    {
        return array_map(fn ($code, $name) => ['code' => (string) $code, 'name' => $name], array_keys(self::ALL), self::ALL);
    }
}

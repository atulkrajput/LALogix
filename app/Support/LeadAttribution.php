<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Reads the ad attribution the website sends with a form (captured in the browser
 * from utm_* / fbclid / gclid on the landing URL; see resources/js/lib/attribution.ts).
 */
class LeadAttribution
{
    private const FIELDS = ['utm_source' => 191, 'utm_medium' => 191, 'utm_campaign' => 191, 'utm_content' => 191, 'utm_term' => 191,
        'click_id' => 255, 'landing_page' => 500, 'referrer' => 500];

    /** @return array<string, string> only the fields present */
    public static function fromRequest(Request $request): array
    {
        $raw = (array) $request->input('attribution', []);
        $out = [];
        foreach (self::FIELDS as $field => $max) {
            $value = trim((string) ($raw[$field] ?? ''));
            if ($value !== '') {
                $out[$field] = mb_substr(strip_tags($value), 0, $max);
            }
        }

        return $out;
    }
}

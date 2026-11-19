<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Reads the ad attribution sent with a website form.
 *
 * The browser keeps utm_* / fbclid / gclid from the landing URL (see
 * resources/js/lib/attribution.ts) and sends them as `attribution`. The first
 * tagged visit is also stored in the `lx_utm` cookie; it is used when the form
 * did not send any attribution (e.g. localStorage blocked).
 */
class LeadAttribution
{
    private const FIELDS = ['utm_source' => 191, 'utm_medium' => 191, 'utm_campaign' => 191, 'utm_content' => 191, 'utm_term' => 191,
        'click_id' => 255, 'landing_page' => 500, 'referrer' => 500];

    /** @return array<string, string> only the fields present */
    public static function fromRequest(Request $request): array
    {
        $raw = (array) $request->input('attribution', []);
        if (! array_filter($raw)) {
            $cookie = json_decode((string) $request->cookie('lx_utm', ''), true);
            $raw = is_array($cookie) ? $cookie : [];
        }

        $out = [];
        foreach (self::FIELDS as $field => $max) {
            $value = trim((string) (is_scalar($raw[$field] ?? null) ? $raw[$field] : ''));
            if ($value !== '') {
                $out[$field] = mb_substr(strip_tags($value), 0, $max);
            }
        }

        return $out;
    }
}

<?php

namespace App\Models;

/** Ad spend per platform, campaign and day (from the Meta API or a CSV export). */
class AdSpend extends Model
{
    protected $table = 'ad_spend';

    protected $casts = [
        'date' => 'date:Y-m-d',
        'spend' => 'float',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'platform_leads' => 'integer',
    ];

    /** Normalised campaign key used to match ad campaigns with leads' utm_campaign. */
    public static function key(?string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $value)));
    }
}

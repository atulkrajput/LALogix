<?php

namespace App\Services\Marketing;

use App\Models\AdSpend;
use App\Models\BusinessProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Pulls daily campaign spend from the Meta Marketing API (Facebook / Instagram ads):
 *   GET /{version}/act_{ad account}/insights?level=campaign&time_increment=1
 * Needs an ad account id and an access token with ads_read (e.g. a Business Manager
 * system-user token). Rows are upserted, so re-syncing a period is safe.
 */
class MetaAdsService
{
    public const GRAPH = 'https://graph.facebook.com';

    /** Action types Meta reports for lead campaigns, in order of preference. */
    private const LEAD_ACTIONS = ['lead', 'onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead'];

    /** @return array{days: int, rows: int, spend: float} */
    public function sync(Carbon $from, Carbon $to): array
    {
        $profile = BusinessProfile::current();
        $account = preg_replace('/^act_/', '', (string) $profile->meta_ad_account_id);
        if (! $account || ! $profile->has_meta_token) {
            throw new RuntimeException('Save the Meta ad account id and access token first.');
        }

        $version = config('services.meta.graph_version', 'v23.0');
        $url = self::GRAPH."/{$version}/act_{$account}/insights";
        $query = [
            'level' => 'campaign',
            'time_increment' => 1,
            'fields' => 'campaign_id,campaign_name,spend,impressions,clicks,actions',
            'time_range' => json_encode(['since' => $from->toDateString(), 'until' => $to->toDateString()]),
            'limit' => 500,
        ];

        $rows = 0;
        $spend = 0.0;
        $days = [];
        for ($page = 0; $url && $page < 50; $page++) {
            $response = Http::withToken($profile->meta_access_token)->acceptJson()->timeout(30)->get($url, $query);
            if (! $response->successful()) {
                $message = $response->json('error.message') ?: "Meta answered {$response->status()}";
                throw new RuntimeException('Meta Marketing API: '.$message);
            }
            foreach ((array) $response->json('data', []) as $row) {
                $name = (string) ($row['campaign_name'] ?? 'Unnamed campaign');
                $id = isset($row['campaign_id']) ? (string) $row['campaign_id'] : null;
                $date = $row['date_start'] ?? null;
                if (! $date) {
                    continue;
                }
                $value = round((float) ($row['spend'] ?? 0), 2);
                AdSpend::updateOrCreate(
                    ['platform' => 'meta', 'date' => $date, 'campaign_key' => AdSpend::key($id ?: $name)],
                    [
                        'campaign_id' => $id,
                        'campaign_name' => mb_substr($name, 0, 255),
                        'spend' => $value,
                        'impressions' => (int) ($row['impressions'] ?? 0),
                        'clicks' => (int) ($row['clicks'] ?? 0),
                        'platform_leads' => $this->leads($row['actions'] ?? []),
                        'source' => 'api',
                    ],
                );
                $rows++;
                $spend += $value;
                $days[$date] = true;
            }
            // Follow Meta's paging cursor (the "next" URL already carries the query).
            $url = $response->json('paging.next');
            $query = [];
        }

        $profile->forceFill(['meta_synced_at' => now()])->save();

        return ['days' => count($days), 'rows' => $rows, 'spend' => round($spend, 2)];
    }

    private function leads(array $actions): int
    {
        $byType = collect($actions)->mapWithKeys(fn ($a) => [($a['action_type'] ?? '') => (int) round((float) ($a['value'] ?? 0))]);
        foreach (self::LEAD_ACTIONS as $type) {
            if ($byType->has($type)) {
                return $byType[$type];
            }
        }

        return 0;
    }
}

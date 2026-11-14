<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdSpend;
use App\Models\BusinessProfile;
use App\Services\Marketing\MarketingService;
use App\Services\Marketing\MetaAdsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Marketing: campaign performance, Meta ads connection, ad-spend CSV import. */
class MarketingController extends Controller
{
    public function report(Request $request, MarketingService $marketing): JsonResponse
    {
        $data = $request->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);
        if ($from->diffInDays($to) > 400) {
            return response()->json(['error' => 'Choose a period of at most one year.'], 422);
        }
        $profile = BusinessProfile::current();

        return response()->json([
            ...$marketing->build($from, $to),
            'meta' => [
                'ad_account_id' => $profile->meta_ad_account_id,
                'has_token' => $profile->has_meta_token,
                'synced_at' => $profile->meta_synced_at?->toIso8601String(),
            ],
        ]);
    }

    public function saveMeta(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ad_account_id' => ['nullable', 'regex:/^(act_)?[0-9]{5,25}$/'],
            'access_token' => 'nullable|string|max:1000',
            'clear_token' => 'boolean',
        ], ['ad_account_id.regex' => 'The ad account id is the number shown in Ads Manager (optionally starting with act_).']);

        $profile = BusinessProfile::current();
        $profile->meta_ad_account_id = $data['ad_account_id'] ?? null;
        if ($request->boolean('clear_token')) {
            $profile->meta_access_token = null;
        } elseif (! empty($data['access_token'])) {
            $profile->meta_access_token = trim($data['access_token']);
        }
        $profile->save();

        return response()->json(['meta' => ['ad_account_id' => $profile->meta_ad_account_id, 'has_token' => $profile->has_meta_token]]);
    }

    public function syncMeta(Request $request, MetaAdsService $meta): JsonResponse
    {
        $data = $request->validate(['days' => 'nullable|integer|min:1|max:365']);
        @set_time_limit(120);
        $to = Carbon::today();
        $from = $to->copy()->subDays(($data['days'] ?? 30) - 1);
        try {
            return response()->json(['result' => $meta->sync($from, $to)]);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    /**
     * Ad spend from a CSV export (Meta Ads Manager, Google Ads or a simple sheet), one row per
     * campaign per day. Header names are matched loosely; re-importing the same days replaces them.
     */
    public function importSpend(Request $request): JsonResponse
    {
        $request->validate([
            'platform' => ['required', Rule::in(['meta', 'google', 'other'])],
            'file' => 'required|file|max:5120',
        ]);
        $platform = $request->input('platform');

        $fh = fopen($request->file('file')->getRealPath(), 'r');
        $headerLine = preg_replace('/^\xEF\xBB\xBF/', '', (string) fgets($fh));
        $delimiter = substr_count($headerLine, ';') > substr_count($headerLine, ',') ? ';' : (substr_count($headerLine, "\t") > substr_count($headerLine, ',') ? "\t" : ',');
        $header = array_map(fn ($h) => mb_strtolower(trim($h, " \"'\t\r\n")), str_getcsv($headerLine, $delimiter));
        // Names in priority order; exact header matches beat prefix matches; ratio columns
        // ("Cost per result", "CPC (cost per link click)") are never used.
        $find = function (array $names) use ($header): ?int {
            $usable = array_filter($header, fn ($h) => ! str_contains($h, ' per ') && ! str_starts_with($h, 'cp'));
            foreach ($names as $n) {
                foreach ($usable as $i => $h) {
                    if ($h === $n) {
                        return $i;
                    }
                }
            }
            foreach ($names as $n) {
                foreach ($usable as $i => $h) {
                    if (str_starts_with($h, $n)) {
                        return $i;
                    }
                }
            }

            return null;
        };
        $col = [
            'date' => $find(['date', 'day', 'reporting starts']),
            'campaign' => $find(['campaign name', 'campaign']),
            'campaign_id' => $find(['campaign id']),
            'spend' => $find(['amount spent', 'spend', 'cost']),
            'impressions' => $find(['impressions', 'impr']),
            'clicks' => $find(['link clicks', 'clicks']),
            'leads' => $find(['leads', 'results', 'conversions']),
        ];
        if ($col['campaign'] !== null && $col['campaign'] === $col['campaign_id']) {
            $col['campaign'] = null; // only an id column: use it as the name too
        }
        $col['campaign'] ??= $col['campaign_id'];
        foreach (['date', 'campaign', 'spend'] as $required) {
            if ($col[$required] === null) {
                fclose($fh);

                return response()->json(['error' => "Couldn't find a \"{$required}\" column. Export one row per campaign per day (e.g. Ads Manager → Breakdown → By time → Day)."], 422);
            }
        }

        $num = fn ($v) => (float) preg_replace('/[^0-9.\-]/', '', (string) $v);
        $saved = 0;
        $skipped = [];
        $line = 1;
        while (($cells = fgetcsv($fh, 0, $delimiter)) !== false) {
            $line++;
            $campaign = trim((string) ($cells[$col['campaign']] ?? ''));
            $dateRaw = trim((string) ($cells[$col['date']] ?? ''));
            if ($campaign === '' && $dateRaw === '') {
                continue;
            }
            $date = $this->date($dateRaw);
            if (! $date || $campaign === '' || in_array(mb_strtolower($campaign), ['total', 'totals', '--'], true)) {
                $skipped[] = "row {$line}";
                continue;
            }
            $id = $col['campaign_id'] !== null ? trim((string) ($cells[$col['campaign_id']] ?? '')) : '';
            AdSpend::updateOrCreate(
                ['platform' => $platform, 'date' => $date, 'campaign_key' => AdSpend::key($id ?: $campaign)],
                [
                    'campaign_id' => $id ?: null,
                    'campaign_name' => mb_substr($campaign, 0, 255),
                    'spend' => round($num($cells[$col['spend']] ?? 0), 2),
                    'impressions' => $col['impressions'] !== null ? (int) $num($cells[$col['impressions']] ?? 0) : 0,
                    'clicks' => $col['clicks'] !== null ? (int) $num($cells[$col['clicks']] ?? 0) : 0,
                    'platform_leads' => $col['leads'] !== null ? (int) $num($cells[$col['leads']] ?? 0) : 0,
                    'source' => 'import',
                ],
            );
            $saved++;
        }
        fclose($fh);

        return response()->json(['saved' => $saved, 'skipped' => $skipped]);
    }

    private function date(string $value): ?string
    {
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'd-M-Y', 'd M Y', 'M j, Y', 'j M Y'] as $format) {
            try {
                $d = Carbon::createFromFormat('!'.$format, $value);
                if ($d && $d->format($format) === $value) {
                    return $d->toDateString();
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }
}

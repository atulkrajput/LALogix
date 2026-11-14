<?php

namespace App\Services\Marketing;

use App\Models\AdSpend;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Lead;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Campaign performance: ad spend → website leads → billing clients → revenue.
 *
 * Joins on the campaign key: a lead's utm_campaign (lower-cased) matches an ad
 * campaign's id or name. Customers are clients created from those leads ("Create
 * billing client" on a lead) or synced from a product with utm_campaign. Revenue is
 * the taxable value (net of credit notes) those customers were invoiced in the period.
 */
class MarketingService
{
    /** @return array<string, mixed> */
    public function build(Carbon $from, Carbon $to): array
    {
        $spend = AdSpend::query()->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->get();
        $leads = Lead::query()->where('created_at', '>=', $from->copy()->startOfDay())->where('created_at', '<=', $to->copy()->endOfDay())
            ->get(['id', 'source', 'utm_source', 'utm_medium', 'utm_campaign', 'click_id', 'status']);

        // Customers: from these leads, or synced with campaign tags and created in the period.
        $clients = Client::query()
            ->where(fn ($q) => $q->whereIn('lead_id', $leads->pluck('id')->all() ?: [0])
                ->orWhere(fn ($w) => $w->whereNotNull('utm_campaign')->where('created_at', '>=', $from->copy()->startOfDay())->where('created_at', '<=', $to->copy()->endOfDay())))
            ->get(['id', 'lead_id', 'utm_source', 'utm_campaign']);
        $leadById = $leads->keyBy('id');
        $revenueByClient = $this->revenueByClient($clients->pluck('id')->all(), $from, $to);

        // Campaign key and source for each customer (own tags first, then its lead's).
        $clientCampaign = $clients->mapWithKeys(fn (Client $c) => [$c->id => [
            'key' => AdSpend::key($c->utm_campaign ?: $leadById[$c->lead_id]->utm_campaign ?? null),
            'source' => $this->sourceLabel($c->utm_source ?: $leadById[$c->lead_id]->utm_source ?? null, $leadById[$c->lead_id]->source ?? 'product'),
        ]]);

        // Ad campaigns by key; ids and names both resolve to the same row.
        $campaigns = [];
        $alias = [];
        foreach ($spend->groupBy(fn (AdSpend $s) => $s->platform.'|'.$s->campaign_key) as $rows) {
            $first = $rows->sortByDesc('date')->first();
            $key = $first->platform.'|'.$first->campaign_key;
            $campaigns[$key] = [
                'key' => $key,
                'name' => $first->campaign_name,
                'platform' => $first->platform,
                'spend' => round($rows->sum('spend'), 2),
                'impressions' => (int) $rows->sum('impressions'),
                'clicks' => (int) $rows->sum('clicks'),
                'platform_leads' => (int) $rows->sum('platform_leads'),
                'leads' => 0, 'customers' => 0, 'revenue' => 0.0,
            ];
            foreach ([$first->campaign_key, AdSpend::key($first->campaign_name), AdSpend::key($first->campaign_id)] as $a) {
                if ($a !== '') {
                    $alias[$a] ??= $key;
                }
            }
        }
        $resolve = function (string $utmKey) use (&$campaigns, $alias): ?string {
            if ($utmKey === '') {
                return null;
            }
            if (isset($alias[$utmKey])) {
                return $alias[$utmKey];
            }
            $key = 'utm|'.$utmKey; // tagged traffic with no spend recorded (e.g. organic posts, email)
            $campaigns[$key] ??= ['key' => $key, 'name' => $utmKey, 'platform' => null, 'spend' => 0.0, 'impressions' => 0,
                'clicks' => 0, 'platform_leads' => 0, 'leads' => 0, 'customers' => 0, 'revenue' => 0.0];

            return $key;
        };

        foreach ($leads as $lead) {
            if ($k = $resolve(AdSpend::key($lead->utm_campaign))) {
                $campaigns[$k]['leads']++;
            }
        }
        foreach ($clients as $client) {
            if ($k = $resolve($clientCampaign[$client->id]['key'])) {
                $campaigns[$k]['customers']++;
                $campaigns[$k]['revenue'] += $revenueByClient[$client->id] ?? 0;
            }
        }

        $rows = collect($campaigns)->map(fn ($c) => $this->withRatios($c))
            ->sortByDesc(fn ($c) => [$c['spend'], $c['revenue'], $c['leads']])->values()->all();

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => $this->totals($spend, $leads, $clients, $clientCampaign, $revenueByClient),
            'campaigns' => $rows,
            'sources' => $this->bySource($leads, $clients, $clientCampaign, $revenueByClient),
            'daily_spend' => $spend->groupBy(fn ($s) => $s->date->toDateString())->map(fn ($d) => round($d->sum('spend'), 2))->sortKeys()->all(),
        ];
    }

    /** @return array<int, float> */
    private function revenueByClient(array $clientIds, Carbon $from, Carbon $to): array
    {
        if (! $clientIds) {
            return [];
        }

        return Invoice::query()->whereIn('client_id', $clientIds)->where('status', 'issued')
            ->whereDate('invoice_date', '>=', $from->toDateString())->whereDate('invoice_date', '<=', $to->toDateString())
            ->get(['client_id', 'type', 'taxable_total'])
            ->groupBy('client_id')
            ->map(fn (Collection $rows) => round($rows->sum(fn ($i) => ($i->type === Invoice::TYPE_CREDIT_NOTE ? -1 : 1) * $i->taxable_total), 2))
            ->all();
    }

    private function totals(Collection $spend, Collection $leads, Collection $clients, Collection $clientCampaign, array $revenueByClient): array
    {
        $paidLeads = $leads->filter(fn ($l) => $l->utm_campaign || $l->click_id)->count();
        $campaignClients = $clients->filter(fn ($c) => $clientCampaign[$c->id]['key'] !== '');
        $revenue = round($campaignClients->sum(fn ($c) => $revenueByClient[$c->id] ?? 0), 2);
        $total = round($spend->sum('spend'), 2);

        return [
            'spend' => $total,
            'impressions' => (int) $spend->sum('impressions'),
            'clicks' => (int) $spend->sum('clicks'),
            'leads' => $leads->count(),
            'campaign_leads' => $paidLeads,
            'customers' => $campaignClients->count(),
            'revenue' => $revenue,
            'cost_per_lead' => $paidLeads > 0 && $total > 0 ? round($total / $paidLeads, 2) : null,
            'roas' => $total > 0 ? round($revenue / $total, 2) : null,
        ];
    }

    private function bySource(Collection $leads, Collection $clients, Collection $clientCampaign, array $revenueByClient): array
    {
        $rows = [];
        foreach ($leads as $lead) {
            $label = $this->sourceLabel($lead->utm_source, $lead->source);
            $rows[$label] ??= ['source' => $label, 'leads' => 0, 'customers' => 0, 'revenue' => 0.0];
            $rows[$label]['leads']++;
        }
        foreach ($clients as $client) {
            $label = $clientCampaign[$client->id]['source'];
            $rows[$label] ??= ['source' => $label, 'leads' => 0, 'customers' => 0, 'revenue' => 0.0];
            $rows[$label]['customers']++;
            $rows[$label]['revenue'] += $revenueByClient[$client->id] ?? 0;
        }

        return collect($rows)->map(fn ($r) => [...$r, 'revenue' => round($r['revenue'], 2)])
            ->sortByDesc('leads')->values()->all();
    }

    /** "facebook", "google", or the lead's own source ("website", "imported"). */
    private function sourceLabel(?string $utmSource, ?string $fallback): string
    {
        $s = mb_strtolower(trim((string) $utmSource));

        return match (true) {
            in_array($s, ['fb', 'facebook', 'instagram', 'ig', 'meta'], true) => 'facebook / instagram',
            in_array($s, ['google', 'adwords', 'gads'], true) => 'google',
            $s !== '' => $s,
            default => $fallback ?: 'website',
        };
    }

    private function withRatios(array $c): array
    {
        return [
            ...$c,
            'revenue' => round($c['revenue'], 2),
            'ctr' => $c['impressions'] > 0 ? round($c['clicks'] / $c['impressions'] * 100, 2) : null,
            'cost_per_lead' => $c['leads'] > 0 && $c['spend'] > 0 ? round($c['spend'] / $c['leads'], 2) : null,
            'roas' => $c['spend'] > 0 ? round($c['revenue'] / $c['spend'], 2) : null,
        ];
    }
}

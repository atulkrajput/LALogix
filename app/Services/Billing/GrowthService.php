<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Company growth numbers across every product (manual + synced invoices).
 *
 * Revenue = taxable value (before GST) of issued invoices minus issued credit
 * notes, by document date. Collected = payments received (incl. TDS) by payment date.
 */
class GrowthService
{
    /** @return array<string, mixed> */
    public function build(string $range = '12m', ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        [$from, $to, $label] = $this->period($range, $today);
        // Comparison period: financial years against the same dates a year earlier;
        // rolling ranges against the equally long span just before.
        if (in_array($range, ['fy', 'prev_fy'], true)) {
            [$prevFrom, $prevTo] = [$from->copy()->subYear(), $to->copy()->subYear()];
        } else {
            $months = ($to->year - $from->year) * 12 + $to->month - $from->month + 1;
            [$prevFrom, $prevTo] = [$from->copy()->subMonths($months), $from->copy()->subDay()];
        }

        // Every issued document from the start of the comparison period (or the previous month) to today.
        $docsFrom = $prevFrom->copy()->min($today->copy()->subMonthNoOverflow()->startOfMonth());
        $docs = Invoice::query()
            ->where('status', 'issued')
            ->whereDate('invoice_date', '>=', $docsFrom->toDateString())
            ->whereDate('invoice_date', '<=', $to->toDateString())
            ->get(['id', 'type', 'product_id', 'client_id', 'invoice_date', 'bill_name', 'taxable_total',
                'cgst_total', 'sgst_total', 'igst_total', 'grand_total', 'amount_paid', 'amount_credited', 'due_date']);

        $sign = fn (Invoice $d) => $d->type === Invoice::TYPE_CREDIT_NOTE ? -1 : 1;
        $inRange = fn (Invoice $d) => $d->invoice_date->between($from, $to);
        $current = $docs->filter($inRange);
        $previous = $docs->filter(fn (Invoice $d) => $d->invoice_date->between($prevFrom, $prevTo));
        $revenue = fn (Collection $set) => round($set->sum(fn (Invoice $d) => $sign($d) * $d->taxable_total), 2);
        $tax = fn (Collection $set) => round($set->sum(fn (Invoice $d) => $sign($d) * ($d->cgst_total + $d->sgst_total + $d->igst_total)), 2);

        $payments = Payment::query()
            ->whereDate('payment_date', '>=', $docsFrom->toDateString())
            ->whereDate('payment_date', '<=', $to->toDateString())
            ->get(['payment_date', 'amount', 'tds_amount']);

        $products = Product::orderBy('sort')->orderBy('id')->get(['id', 'name', 'kind', 'is_active']);
        $firstInvoice = $this->firstInvoiceMonths();

        return [
            'range' => ['key' => $range, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'label' => $label,
                'compare_from' => $prevFrom->toDateString(), 'compare_to' => $prevTo->toDateString()],
            'products' => $products->values()->map(fn (Product $p, $i) => [
                'id' => $p->id, 'name' => $p->name, 'kind' => $p->kind, 'slot' => $i + 1,
            ])->all(),
            ...$this->withExpenses(
                $this->months($from, $to, $current, $payments, $products, $firstInvoice, $sign),
                $this->kpis($today, $docs, $current, $previous, $payments, $revenue, $tax, $firstInvoice),
                $from, $to, $today,
            ),
            'by_product' => $this->byProduct($products, $current, $previous, $sign),
            'top_clients' => $this->topClients($current, $sign),
            'aging' => $this->aging($today),
        ];
    }

    /**
     * Expenses from recorded purchases: value before GST plus any GST that can't be claimed
     * (blocked or without a GST invoice), since that GST is a real cost. Profit = revenue − expenses.
     */
    private function withExpenses(array $months, array $kpis, Carbon $from, Carbon $to, Carbon $today): array
    {
        $purchases = Purchase::query()->whereDate('bill_date', '>=', $from->toDateString())->whereDate('bill_date', '<=', $to->toDateString())
            ->get(['bill_date', 'category', 'taxable_value', 'cgst', 'sgst', 'igst', 'itc_eligible']);
        $cost = fn (Purchase $p) => $p->taxable_value + ($p->itc_eligible ? 0 : $p->cgst + $p->sgst + $p->igst);
        $byMonth = $purchases->groupBy(fn ($p) => $p->bill_date->format('Y-m'))->map(fn ($rows) => $rows->sum($cost));

        $months = array_map(fn ($m) => [...$m, 'expenses' => round((float) ($byMonth[$m['month']] ?? 0), 2),
            'profit' => round($m['revenue'] - (float) ($byMonth[$m['month']] ?? 0), 2)], $months);
        $rangeExpenses = round($purchases->sum($cost), 2);
        $monthExpenses = round($purchases->filter(fn ($p) => $p->bill_date->gte($today->copy()->startOfMonth()))->sum($cost), 2);

        return [
            'months' => $months,
            'kpis' => [
                ...$kpis,
                'range_expenses' => $rangeExpenses,
                'range_profit' => round($kpis['range_revenue'] - $rangeExpenses, 2),
                'month_expenses' => $monthExpenses,
                'month_profit' => round($kpis['month_revenue'] - $monthExpenses, 2),
            ],
            'expenses_by_category' => $purchases->groupBy('category')
                ->map(fn ($rows, $cat) => ['category' => $cat, 'label' => Purchase::CATEGORIES[$cat] ?? $cat, 'amount' => round($rows->sum($cost), 2)])
                ->sortByDesc('amount')->values()->all(),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon, 2: string} */
    private function period(string $range, Carbon $today): array
    {
        $fyStart = Carbon::create($today->month >= 4 ? $today->year : $today->year - 1, 4, 1);

        return match ($range) {
            'fy' => [$fyStart, $today->copy(), 'FY '.$fyStart->year.'-'.substr((string) ($fyStart->year + 1), -2).' to date'],
            'prev_fy' => [$fyStart->copy()->subYear(), $fyStart->copy()->subDay(), 'FY '.($fyStart->year - 1).'-'.substr((string) $fyStart->year, -2)],
            '6m' => [$today->copy()->startOfMonth()->subMonths(5), $today->copy(), 'Last 6 months'],
            default => [$today->copy()->startOfMonth()->subMonths(11), $today->copy(), 'Last 12 months'],
        };
    }

    /** Month (Y-m) of each client's first issued invoice. @return array<int, string> */
    private function firstInvoiceMonths(): array
    {
        return Invoice::query()->where('type', Invoice::TYPE_INVOICE)->where('status', 'issued')
            ->selectRaw('client_id, MIN(invoice_date) as first_date')->groupBy('client_id')
            ->pluck('first_date', 'client_id')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m'))->all();
    }

    private function months(Carbon $from, Carbon $to, Collection $docs, Collection $payments, Collection $products, array $firstInvoice, callable $sign): array
    {
        $saasIds = $products->where('kind', 'saas')->pluck('id')->all();
        $rows = [];
        for ($m = $from->copy()->startOfMonth(); $m->lte($to); $m->addMonth()) {
            $key = $m->format('Y-m');
            $rows[$key] = [
                'month' => $key,
                'label' => $m->format('M y'),
                'by_product' => [],
                'revenue' => 0.0,
                'tax' => 0.0,
                'collected' => 0.0,
                'invoices' => 0,
                'new_customers' => 0,
                'saas_revenue' => 0.0,
                'saas_customers' => [],
            ];
        }

        foreach ($docs as $d) {
            $key = $d->invoice_date->format('Y-m');
            if (! isset($rows[$key])) {
                continue;
            }
            $amount = $sign($d) * $d->taxable_total;
            $rows[$key]['by_product'][$d->product_id] = ($rows[$key]['by_product'][$d->product_id] ?? 0) + $amount;
            $rows[$key]['revenue'] += $amount;
            $rows[$key]['tax'] += $sign($d) * ($d->cgst_total + $d->sgst_total + $d->igst_total);
            if ($d->type === Invoice::TYPE_INVOICE) {
                $rows[$key]['invoices']++;
            }
            if (in_array($d->product_id, $saasIds, true)) {
                $rows[$key]['saas_revenue'] += $amount;
                if ($d->type === Invoice::TYPE_INVOICE) {
                    $rows[$key]['saas_customers'][$d->client_id] = true;
                }
            }
        }
        foreach ($payments as $p) {
            $key = $p->payment_date->format('Y-m');
            if (isset($rows[$key])) {
                $rows[$key]['collected'] += $p->amount + $p->tds_amount;
            }
        }
        foreach ($firstInvoice as $month) {
            if (isset($rows[$month])) {
                $rows[$month]['new_customers']++;
            }
        }

        return array_values(array_map(fn ($r) => [
            ...$r,
            'by_product' => array_map(fn ($v) => round($v, 2), $r['by_product']),
            'revenue' => round($r['revenue'], 2),
            'tax' => round($r['tax'], 2),
            'collected' => round($r['collected'], 2),
            'saas_revenue' => round($r['saas_revenue'], 2),
            'saas_customers' => count($r['saas_customers']),
        ], $rows));
    }

    private function kpis(Carbon $today, Collection $docs, Collection $current, Collection $previous, Collection $payments, callable $revenue, callable $tax, array $firstInvoice): array
    {
        $monthStart = $today->copy()->startOfMonth();
        $lastMonthStart = $monthStart->copy()->subMonthNoOverflow();
        // Same number of days into last month, for a fair month-to-date comparison.
        $lastMonthSameDay = $lastMonthStart->copy()->addDays(min($today->day, $lastMonthStart->daysInMonth) - 1);
        $thisMonth = $docs->filter(fn (Invoice $d) => $d->invoice_date->gte($monthStart));
        $lastMonthToDate = $docs->filter(fn (Invoice $d) => $d->invoice_date->between($lastMonthStart, $lastMonthSameDay));

        $openInvoices = Invoice::query()->where('type', Invoice::TYPE_INVOICE)->where('status', 'issued')
            ->whereRaw('grand_total - amount_paid - amount_credited > 0.004')
            ->get(['grand_total', 'amount_paid', 'amount_credited', 'due_date']);
        $balance = fn ($i) => $i->grand_total - $i->amount_paid - $i->amount_credited;

        $leads = fn (Carbon $a, Carbon $b) => Lead::query()->where('created_at', '>=', $a)->where('created_at', '<', $b)->count();

        return [
            'month_revenue' => $revenue($thisMonth),
            'last_month_revenue_to_date' => $revenue($lastMonthToDate),
            'month_change_pct' => $this->change($revenue($thisMonth), $revenue($lastMonthToDate)),
            'range_revenue' => $revenue($current),
            'prev_range_revenue' => $revenue($previous),
            'range_change_pct' => $this->change($revenue($current), $revenue($previous)),
            'range_tax' => $tax($current),
            'month_tax' => $tax($thisMonth),
            'month_collected' => round($payments->filter(fn ($p) => $p->payment_date->gte($monthStart))->sum(fn ($p) => $p->amount + $p->tds_amount), 2),
            'outstanding' => round($openInvoices->sum($balance), 2),
            'overdue' => round($openInvoices->filter(fn ($i) => $i->due_date && $i->due_date->lt($today))->sum($balance), 2),
            'customers' => $current->where('type', Invoice::TYPE_INVOICE)->pluck('client_id')->unique()->count(),
            'new_customers_month' => count(array_filter($firstInvoice, fn ($m) => $m === $monthStart->format('Y-m'))),
            'leads_month' => $leads($monthStart, $today->copy()->addDay()),
            'leads_last_month' => $leads($lastMonthStart, $monthStart),
        ];
    }

    private function byProduct(Collection $products, Collection $current, Collection $previous, callable $sign): array
    {
        $total = $current->sum(fn (Invoice $d) => $sign($d) * $d->taxable_total);

        return $products->map(function (Product $p) use ($current, $previous, $sign, $total) {
            $mine = $current->where('product_id', $p->id);
            $rev = round($mine->sum(fn (Invoice $d) => $sign($d) * $d->taxable_total), 2);
            $prev = round($previous->where('product_id', $p->id)->sum(fn (Invoice $d) => $sign($d) * $d->taxable_total), 2);

            return [
                'product_id' => $p->id,
                'name' => $p->name,
                'kind' => $p->kind,
                'revenue' => $rev,
                'share_pct' => $total > 0 ? round($rev / $total * 100, 1) : 0.0,
                'invoices' => $mine->where('type', Invoice::TYPE_INVOICE)->count(),
                'customers' => $mine->where('type', Invoice::TYPE_INVOICE)->pluck('client_id')->unique()->count(),
                'prev_revenue' => $prev,
                'change_pct' => $this->change($rev, $prev),
            ];
        })->filter(fn ($r) => $r['revenue'] != 0 || $r['prev_revenue'] != 0)->values()->all();
    }

    private function topClients(Collection $current, callable $sign): array
    {
        return $current->groupBy('client_id')->map(fn (Collection $rows, $clientId) => [
            'client_id' => (int) $clientId,
            'name' => $rows->sortByDesc('invoice_date')->first()->bill_name,
            'revenue' => round($rows->sum(fn (Invoice $d) => $sign($d) * $d->taxable_total), 2),
            'invoices' => $rows->where('type', Invoice::TYPE_INVOICE)->count(),
            'outstanding' => round($rows->where('type', Invoice::TYPE_INVOICE)->sum(fn ($i) => max(0, $i->grand_total - $i->amount_paid - $i->amount_credited)), 2),
        ])->sortByDesc('revenue')->take(10)->values()->all();
    }

    /** Unpaid balances by how late they are. */
    private function aging(Carbon $today): array
    {
        $buckets = ['not_due' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0];
        $open = Invoice::query()->where('type', Invoice::TYPE_INVOICE)->where('status', 'issued')
            ->whereRaw('grand_total - amount_paid - amount_credited > 0.004')
            ->get(['grand_total', 'amount_paid', 'amount_credited', 'due_date', 'invoice_date']);
        foreach ($open as $i) {
            $due = $i->due_date ?? $i->invoice_date;
            $late = $due->lt($today) ? (int) $due->diffInDays($today) : 0;
            $key = match (true) {
                $late === 0 => 'not_due',
                $late <= 30 => 'd1_30',
                $late <= 60 => 'd31_60',
                $late <= 90 => 'd61_90',
                default => 'd90_plus',
            };
            $buckets[$key] += $i->grand_total - $i->amount_paid - $i->amount_credited;
        }

        return array_map(fn ($v) => round($v, 2), $buckets);
    }

    private function change(float $now, float $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
    }
}

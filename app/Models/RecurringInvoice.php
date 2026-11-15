<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** A schedule that creates an invoice every month / quarter / half-year / year. */
class RecurringInvoice extends Model
{
    protected $table = 'recurring_invoices';

    public const FREQUENCIES = ['monthly' => 1, 'quarterly' => 3, 'half_yearly' => 6, 'yearly' => 12];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'next_run_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'auto_issue' => 'boolean',
        'auto_email' => 'boolean',
        'items' => 'array',
        'invoices_created' => 'integer',
        'last_run_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** The period after $date, keeping the start date's day of month (31st → last day of shorter months). */
    public function periodAfter(Carbon $date): Carbon
    {
        $months = self::FREQUENCIES[$this->frequency] ?? 1;
        $anchorDay = $this->start_date->day;
        $next = $date->copy()->startOfMonth()->addMonthsNoOverflow($months);

        return $next->day(min($anchorDay, $next->daysInMonth));
    }

    /** Pre-tax amount of one invoice from this schedule. */
    public function amount(): float
    {
        return round(collect($this->items)->sum(fn ($i) => (float) $i['quantity'] * (float) $i['rate'] - (float) ($i['discount'] ?? 0)), 2);
    }
}

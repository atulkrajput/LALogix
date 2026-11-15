<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tax invoice or credit note. Drafts have no number; issuing assigns the next
 * number in the product's series for the financial year and freezes the
 * document (it can then only be cancelled or adjusted with a credit note).
 */
class Invoice extends Model
{
    protected $table = 'invoices';

    public const TYPE_INVOICE = 'invoice';
    public const TYPE_CREDIT_NOTE = 'credit_note';

    protected $attributes = [
        'type' => self::TYPE_INVOICE,
        'status' => 'draft',
    ];

    protected $casts = [
        'invoice_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'export_with_lut' => 'boolean',
        'reverse_charge' => 'boolean',
        'seller' => 'array',
        'taxable_total' => 'float',
        'cgst_total' => 'float',
        'sgst_total' => 'float',
        'igst_total' => 'float',
        'round_off' => 'float',
        'grand_total' => 'float',
        'amount_paid' => 'float',
        'amount_credited' => 'float',
        'sequence' => 'integer',
        'recurring_invoice_id' => 'integer',
        'recurring_period' => 'date:Y-m-d',
        'reminders_paused' => 'boolean',
        'issued_at' => 'datetime',
        'emailed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected $appends = ['balance_due', 'payment_status', 'tax_total'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('payment_date')->orderBy('id');
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_invoice_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'original_invoice_id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(InvoiceReminder::class)->orderBy('created_at');
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    public function scopeInvoices(Builder $q): Builder
    {
        return $q->where('type', self::TYPE_INVOICE);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isCreditNote(): bool
    {
        return $this->type === self::TYPE_CREDIT_NOTE;
    }

    public function getTaxTotalAttribute(): float
    {
        return round($this->cgst_total + $this->sgst_total + $this->igst_total, 2);
    }

    public function getBalanceDueAttribute(): float
    {
        if ($this->isCreditNote() || $this->status !== 'issued') {
            return 0.0;
        }

        return max(0, round($this->grand_total - $this->amount_paid - $this->amount_credited, 2));
    }

    /** draft | unpaid | partial | paid | overdue | cancelled (credit notes: issued) */
    public function getPaymentStatusAttribute(): string
    {
        if ($this->status !== 'issued') {
            return (string) $this->status; // also safe when loaded without the status column
        }
        if ($this->isCreditNote()) {
            return 'issued';
        }
        if ($this->balance_due <= 0) {
            return 'paid';
        }
        if ($this->due_date && $this->due_date->isPast() && ! $this->due_date->isToday()) {
            return 'overdue';
        }

        return $this->amount_paid > 0 || $this->amount_credited > 0 ? 'partial' : 'unpaid';
    }

    public function documentTitle(): string
    {
        return $this->isCreditNote() ? 'Credit Note' : 'Tax Invoice';
    }
}

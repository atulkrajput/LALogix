<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A quotation / estimate. Not a GST document: it only becomes one when converted to an invoice. */
class Quotation extends Model
{
    protected $table = 'quotations';

    protected $casts = [
        'quote_date' => 'date:Y-m-d',
        'valid_until' => 'date:Y-m-d',
        'export_with_lut' => 'boolean',
        'taxable_total' => 'float',
        'cgst_total' => 'float',
        'sgst_total' => 'float',
        'igst_total' => 'float',
        'round_off' => 'float',
        'grand_total' => 'float',
        'invoice_id' => 'integer',
        'sent_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    protected $appends = ['tax_total', 'display_status'];

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
        return $this->hasMany(QuotationItem::class)->orderBy('sort')->orderBy('id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function getTaxTotalAttribute(): float
    {
        return round($this->cgst_total + $this->sgst_total + $this->igst_total, 2);
    }

    /** status, or "expired" for an open quote past its validity date */
    public function getDisplayStatusAttribute(): string
    {
        if (in_array($this->status, ['draft', 'sent'], true) && $this->valid_until && $this->valid_until->endOfDay()->isPast()) {
            return 'expired';
        }

        return $this->status;
    }
}

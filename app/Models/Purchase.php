<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A purchase bill or expense, with the GST paid on it and whether that GST can be claimed (ITC). */
class Purchase extends Model
{
    protected $table = 'purchases';

    public const CATEGORIES = [
        'software' => 'Software & subscriptions',
        'hosting' => 'Hosting & domains',
        'ads' => 'Advertising',
        'professional' => 'Professional & freelancers',
        'rent' => 'Rent & utilities',
        'equipment' => 'Equipment',
        'office' => 'Office & supplies',
        'travel' => 'Travel',
        'salary' => 'Salaries',
        'bank' => 'Bank & payment charges',
        'other' => 'Other',
    ];

    protected $casts = [
        'bill_date' => 'date:Y-m-d',
        'paid_on' => 'date:Y-m-d',
        'reverse_charge' => 'boolean',
        'itc_eligible' => 'boolean',
        'taxable_value' => 'float',
        'gst_rate' => 'float',
        'cgst' => 'float',
        'sgst' => 'float',
        'igst' => 'float',
        'total' => 'float',
    ];

    protected $hidden = ['attachment_path'];

    protected $appends = ['tax_total', 'itc_amount', 'has_attachment'];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getTaxTotalAttribute(): float
    {
        return round($this->cgst + $this->sgst + $this->igst, 2);
    }

    /** GST that can be claimed as input tax credit. */
    public function getItcAmountAttribute(): float
    {
        return $this->itc_eligible ? $this->tax_total : 0.0;
    }

    public function getHasAttachmentAttribute(): bool
    {
        return ! empty($this->attributes['attachment_path'] ?? null);
    }
}

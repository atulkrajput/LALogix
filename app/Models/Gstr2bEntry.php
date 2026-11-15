<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One document from a GSTR-2B statement (what a supplier reported on the GST portal). */
class Gstr2bEntry extends Model
{
    protected $table = 'gstr2b_entries';

    protected $casts = [
        'doc_date' => 'date:Y-m-d',
        'doc_value' => 'float',
        'taxable_value' => 'float',
        'igst' => 'float',
        'cgst' => 'float',
        'sgst' => 'float',
        'cess' => 'float',
        'reverse_charge' => 'boolean',
        'itc_available' => 'boolean',
        'purchase_id' => 'integer',
    ];

    protected $appends = ['tax_total'];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function getTaxTotalAttribute(): float
    {
        return round($this->igst + $this->cgst + $this->sgst, 2);
    }
}

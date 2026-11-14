<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $table = 'invoice_items';

    public $timestamps = false;

    protected $casts = [
        'quantity' => 'float',
        'rate' => 'float',
        'discount' => 'float',
        'taxable_value' => 'float',
        'gst_rate' => 'float',
        'cgst' => 'float',
        'sgst' => 'float',
        'igst' => 'float',
        'amount' => 'float',
        'work_item_id' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}

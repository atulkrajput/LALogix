<?php

namespace App\Models;

class QuotationItem extends Model
{
    protected $table = 'quotation_items';

    public $timestamps = false;

    protected $casts = [
        'quantity' => 'float', 'rate' => 'float', 'discount' => 'float', 'taxable_value' => 'float',
        'gst_rate' => 'float', 'cgst' => 'float', 'sgst' => 'float', 'igst' => 'float', 'amount' => 'float',
    ];
}

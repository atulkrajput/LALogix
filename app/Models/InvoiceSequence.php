<?php

namespace App\Models;

class InvoiceSequence extends Model
{
    protected $table = 'invoice_sequences';

    protected $casts = ['last_number' => 'integer'];
}

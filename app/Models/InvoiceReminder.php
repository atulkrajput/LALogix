<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A payment reminder e-mailed for an invoice (stage = days from the due date; null when sent by hand). */
class InvoiceReminder extends Model
{
    protected $table = 'invoice_reminders';

    protected $casts = [
        'stage' => 'integer',
        'balance_due' => 'float',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}

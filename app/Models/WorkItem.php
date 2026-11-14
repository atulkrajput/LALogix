<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Billable work done for a client; becomes an invoice line. */
class WorkItem extends Model
{
    protected $table = 'work_items';

    protected $casts = [
        'quantity' => 'float',
        'rate' => 'float',
        'gst_rate' => 'float',
        'work_date' => 'date:Y-m-d',
        'invoice_id' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngestLog extends Model
{
    protected $table = 'ingest_logs';

    const UPDATED_AT = null;

    protected $casts = ['invoice_id' => 'integer', 'product_id' => 'integer'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

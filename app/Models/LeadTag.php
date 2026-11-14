<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadTag extends Model
{
    protected $table = 'lead_tags';

    const UPDATED_AT = null;

    public $timestamps = false;

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}

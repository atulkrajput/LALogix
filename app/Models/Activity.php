<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activity extends Model
{
    protected $table = 'activities';

    const UPDATED_AT = null;

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoBooking extends Model
{
    protected $table = 'demo_bookings';

    const UPDATED_AT = null;

    protected $attributes = ['status' => 'scheduled'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}

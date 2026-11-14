<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappLog extends Model
{
    protected $table = 'whatsapp_logs';

    const UPDATED_AT = null;

    protected $attributes = ['status' => 'sent'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}

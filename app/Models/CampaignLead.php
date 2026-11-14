<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignLead extends Model
{
    protected $table = 'campaign_leads';

    const UPDATED_AT = null;

    // Only an assigned_at column, filled by the database default.
    public $timestamps = false;

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}

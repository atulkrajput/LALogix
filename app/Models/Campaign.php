<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    protected $table = 'campaigns';

    const UPDATED_AT = null;

    protected $attributes = ['status' => 'active'];

    protected function countableRelations(): array
    {
        return ['campaignLeads'];
    }

    public function campaignLeads(): HasMany
    {
        return $this->hasMany(CampaignLead::class);
    }
}

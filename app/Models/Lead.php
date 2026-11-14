<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    protected $table = 'leads';

    protected $attributes = [
        'source' => 'website',
        'status' => 'new',
        'score' => 0,
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'next_followup_date' => 'datetime',
        ];
    }

    protected function countableRelations(): array
    {
        return ['demoBookings', 'activities', 'campaignLeads'];
    }

    public function demoBookings(): HasMany
    {
        return $this->hasMany(DemoBooking::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(LeadTag::class);
    }

    public function campaignLeads(): HasMany
    {
        return $this->hasMany(CampaignLead::class);
    }

    public function whatsappLogs(): HasMany
    {
        return $this->hasMany(WhatsappLog::class);
    }
}

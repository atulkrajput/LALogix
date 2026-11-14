<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** A business line under the one GSTIN (services, SaaS products, e-commerce). */
class Product extends Model
{
    protected $table = 'products';

    protected $hidden = ['api_key_hash'];

    protected $appends = ['is_connected'];

    protected $casts = [
        'api_key_created_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'default_gst_rate' => 'float',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    protected function countableRelations(): array
    {
        return ['invoices'];
    }

    /** Has an ingest API key (the product sends its own invoices here). */
    public function getIsConnectedAttribute(): bool
    {
        return $this->api_key_hash !== null;
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}

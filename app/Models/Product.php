<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** A business line under the one GSTIN (services, SaaS products, e-commerce). */
class Product extends Model
{
    protected $table = 'products';

    protected $casts = [
        'default_gst_rate' => 'float',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    protected function countableRelations(): array
    {
        return ['invoices'];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}

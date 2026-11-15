<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** A supplier (software, hosting, ad platform, landlord, freelancer…). */
class Vendor extends Model
{
    protected $table = 'vendors';

    protected function countableRelations(): array
    {
        return ['purchases'];
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }
}

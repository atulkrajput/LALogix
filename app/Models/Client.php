<?php

namespace App\Models;

use App\Support\GstStates;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $table = 'clients';

    protected $casts = ['lead_id' => 'integer'];

    protected function countableRelations(): array
    {
        return ['invoices', 'workItems'];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    public function isIndian(): bool
    {
        return strcasecmp(trim($this->country ?: 'India'), 'India') === 0;
    }

    /** Multi-line billing address as printed on invoices. */
    public function addressText(): string
    {
        $stateLine = trim(implode(' ', array_filter([
            $this->city,
            $this->state_code ? GstStates::name($this->state_code) : null,
            $this->pincode,
        ])));

        return implode("\n", array_filter([
            $this->address_line1,
            $this->address_line2,
            $stateLine,
            $this->isIndian() ? null : $this->country,
        ]));
    }
}

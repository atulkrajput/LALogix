<?php

namespace App\Models;

/** The seller (LALogix Enterprises) details printed on invoices. Single row. */
class BusinessProfile extends Model
{
    protected $table = 'business_profiles';

    protected $casts = [
        'payment_terms_days' => 'integer',
        'round_off_total' => 'boolean',
    ];

    public static function current(): self
    {
        return static::query()->first() ?? static::create(['legal_name' => 'LALogix Enterprises']);
    }

    /** One-line address for invoices. */
    public function addressText(): string
    {
        return implode(', ', array_filter([
            $this->address_line1, $this->address_line2, $this->city,
            trim(($this->state_code ? \App\Support\GstStates::name($this->state_code) : '').' '.($this->pincode ?? '')),
        ]));
    }
}

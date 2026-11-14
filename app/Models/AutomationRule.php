<?php

namespace App\Models;

class AutomationRule extends Model
{
    protected $table = 'automation_rules';

    const UPDATED_AT = null;

    protected $attributes = ['enabled' => true];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'template_id' => 'integer'];
    }
}

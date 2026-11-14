<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Str;

/**
 * Base model for the LALogix tables.
 *
 * The React admin was written against Prisma, whose JSON uses camelCase keys
 * (e.g. `productInterest`, `createdAt`) and a `_count` object for relation counts.
 * This base class serialises Eloquent models in that same shape so the frontend
 * contract stays identical.
 */
abstract class Model extends EloquentModel
{
    // Prisma created DATETIME(3) columns (millisecond precision).
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $guarded = ['id'];

    public function toArray(): array
    {
        $out = [];
        $counts = [];

        foreach (parent::toArray() as $key => $value) {
            if (str_ends_with($key, '_count')
                && in_array(Str::camel(substr($key, 0, -6)), $this->countableRelations(), true)) {
                $counts[Str::camel(substr($key, 0, -6))] = (int) $value;
                continue;
            }
            $out[Str::camel($key)] = $value;
        }

        if ($counts) {
            $out['_count'] = $counts;
        }

        return $out;
    }

    /** Relation names that may appear as `withCount()` columns. */
    protected function countableRelations(): array
    {
        return [];
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The product is VIVK.in, not BIVK.in. Renames the row created by the billing
 * migration on databases that already ran it. Only the seeded values are
 * changed; an invoice prefix set in the admin is left as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename('bivk', 'vivk', 'BIVK.in', 'VIVK.in', 'https://bivk.in', 'https://vivk.in');
    }

    public function down(): void
    {
        $this->rename('vivk', 'bivk', 'VIVK.in', 'BIVK.in', 'https://vivk.in', 'https://bivk.in');
    }

    private function rename(string $fromSlug, string $toSlug, string $fromName, string $toName, string $fromSite, string $toSite): void
    {
        $product = DB::table('products')->where('slug', $fromSlug)->first();
        if (! $product || DB::table('products')->where('slug', $toSlug)->exists()) {
            return;
        }

        DB::table('products')->where('id', $product->id)->update(array_filter([
            'slug' => $toSlug,
            'name' => $product->name === $fromName ? $toName : null,
            'website' => $product->website === $fromSite ? $toSite : null,
            'updated_at' => now(),
        ]));
    }
};

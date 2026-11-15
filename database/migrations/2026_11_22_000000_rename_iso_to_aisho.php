<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The product is AISHO (aisho.live, Android app com.aisho), not ISO. Renames the
 * row created by the billing migration on databases that already ran it. Only the
 * seeded values are changed; a name, website or prefix set in the admin is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename('iso', 'aisho', 'ISO', 'AISHO', null, 'https://aisho.live');
    }

    public function down(): void
    {
        $this->rename('aisho', 'iso', 'AISHO', 'ISO', 'https://aisho.live', null);
    }

    private function rename(string $fromSlug, string $toSlug, string $fromName, string $toName, ?string $fromSite, ?string $toSite): void
    {
        $product = DB::table('products')->where('slug', $fromSlug)->first();
        if (! $product || DB::table('products')->where('slug', $toSlug)->exists()) {
            return;
        }

        $update = ['slug' => $toSlug, 'updated_at' => now()];
        if ($product->name === $fromName) {
            $update['name'] = $toName;
        }
        if ($product->website === $fromSite) {
            $update['website'] = $toSite;
        }
        DB::table('products')->where('id', $product->id)->update($update);
    }
};

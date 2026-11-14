<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Sync now" button: LALogix can ask a product (e.g. VIVK.in's /api/cron/lalogix-sync) to send
 * the invoices it hasn't sent yet. The product's secret is stored encrypted (APP_KEY).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('sync_url', 500)->nullable()->after('last_synced_at');
            $table->text('sync_secret')->nullable()->after('sync_url');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['sync_url', 'sync_secret']);
        });
    }
};

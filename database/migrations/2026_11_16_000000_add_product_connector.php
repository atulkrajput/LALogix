<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: products (VIVK.in, Amritix, …) push their own invoices here through
 * the ingest API or a CSV import, so the GST reports cover every business line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('api_key_hash', 64)->nullable()->unique();   // sha256 of the key; the key itself is shown once
            $table->string('api_key_hint', 12)->nullable();             // last characters, to recognise it
            $table->dateTime('api_key_created_at', 3)->nullable();
            $table->dateTime('last_synced_at', 3)->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('source', 10)->default('manual')->after('type'); // manual | api | import
            $table->string('external_id', 100)->nullable()->after('source');
            $table->unique(['product_id', 'type', 'external_id']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('lead_id')->constrained('products')->nullOnDelete(); // first product that sent this customer
        });

        Schema::create('ingest_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->string('channel', 10);                  // api | import
            $table->string('action', 20);                   // invoice | credit_note | cancel | ping
            $table->string('external_id', 100)->nullable();
            $table->string('number', 16)->nullable();
            $table->string('outcome', 12);                  // created | unchanged | cancelled | rejected
            $table->string('message', 500)->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_logs');
        if (Schema::hasColumn('clients', 'product_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropConstrainedForeignId('product_id');
            });
        }
        if (Schema::hasColumn('invoices', 'external_id')) {
            if (! Schema::hasIndex('invoices', 'invoices_product_id_index')) {
                Schema::table('invoices', function (Blueprint $table) {
                    // MySQL uses the composite unique index for the product_id foreign key; give it its own first.
                    $table->index('product_id', 'invoices_product_id_index');
                });
            }
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropUnique(['product_id', 'type', 'external_id']);
                $table->dropColumn(['source', 'external_id']);
            });
        }
        if (Schema::hasColumn('products', 'api_key_hash')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropUnique(['api_key_hash']);
                $table->dropColumn(['api_key_hash', 'api_key_hint', 'api_key_created_at', 'last_synced_at']);
            });
        }
    }
};

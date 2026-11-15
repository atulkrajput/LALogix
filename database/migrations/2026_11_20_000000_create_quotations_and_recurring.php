<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7: quotations (estimates, not GST documents, so kept out of the invoice tables
 * and GST reports) and recurring invoice schedules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('client_id')->constrained('clients');
            $table->string('number', 20)->unique();
            $table->string('status', 12)->default('draft');      // draft | sent | accepted | declined | converted
            $table->date('quote_date');
            $table->date('valid_until')->nullable();
            $table->string('place_of_supply', 2);
            $table->string('supply_type', 6);
            $table->boolean('export_with_lut')->default(false);
            $table->string('bill_name');
            $table->string('bill_company')->nullable();
            $table->string('bill_gstin', 15)->nullable();
            $table->text('bill_address')->nullable();
            $table->string('bill_state_code', 2)->nullable();
            $table->string('bill_country')->default('India');
            $table->string('bill_email')->nullable();
            $table->decimal('taxable_total', 14, 2)->default(0);
            $table->decimal('cgst_total', 14, 2)->default(0);
            $table->decimal('sgst_total', 14, 2)->default(0);
            $table->decimal('igst_total', 14, 2)->default(0);
            $table->decimal('round_off', 8, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete(); // once converted
            $table->dateTime('sent_at', 3)->nullable();
            $table->dateTime('decided_at', 3)->nullable();
            $table->timestamps(3);
            $table->index(['status', 'quote_date']);
        });

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->string('description', 1000);
            $table->string('sac', 8)->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->string('unit', 10)->default('NOS');
            $table->decimal('rate', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('taxable_value', 14, 2)->default(0);
            $table->decimal('gst_rate', 5, 2)->default(18);
            $table->decimal('cgst', 14, 2)->default(0);
            $table->decimal('sgst', 14, 2)->default(0);
            $table->decimal('igst', 14, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
        });

        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('name');                                // e.g. "Acme – monthly AMC"
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('frequency', 10);                       // monthly | quarterly | half_yearly | yearly
            $table->date('start_date');
            $table->date('next_run_date');
            $table->date('end_date')->nullable();
            $table->string('status', 10)->default('active');       // active | paused | ended
            $table->boolean('auto_issue')->default(true);          // issue with a number, or leave as draft
            $table->boolean('auto_email')->default(false);         // e-mail the PDF to the client (needs auto_issue)
            $table->json('items');
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->unsignedInteger('invoices_created')->default(0);
            $table->dateTime('last_run_at', 3)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps(3);
            $table->index(['status', 'next_run_date']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('recurring_invoice_id')->nullable()->after('original_invoice_id')->constrained('recurring_invoices')->nullOnDelete();
            $table->date('recurring_period')->nullable()->after('recurring_invoice_id');
            $table->unique(['recurring_invoice_id', 'recurring_period']);  // one invoice per schedule period
        });

        Schema::table('business_profiles', function (Blueprint $table) {
            $table->string('quotation_prefix', 5)->default('QT');
            $table->unsignedSmallInteger('quotation_valid_days')->default(15);
            $table->string('cron_token', 64)->nullable();           // secret for the external "run schedules" URL
        });
    }

    public function down(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->dropColumn(['quotation_prefix', 'quotation_valid_days', 'cron_token']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['recurring_invoice_id']);
            $table->dropUnique(['recurring_invoice_id', 'recurring_period']);
            $table->dropColumn(['recurring_invoice_id', 'recurring_period']);
        });
        Schema::dropIfExists('recurring_invoices');
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
    }
};

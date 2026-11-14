<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GST billing (Phase 1): business profile, products (each with its own invoice
 * series), clients, billable work, invoices / credit notes, payments.
 *
 * Money columns are DECIMAL(14,2) rupees; all tax maths happens in paise in
 * App\Services\Billing\InvoiceCalculator.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Single row: the seller details printed on every invoice.
        Schema::create('business_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name');
            $table->string('trade_name')->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('pan', 10)->nullable();
            $table->string('state_code', 2)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('pincode', 10)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('website')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number', 40)->nullable();
            $table->string('bank_ifsc', 15)->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('upi_id')->nullable();
            $table->string('lut_number')->nullable();          // for zero-rated exports
            $table->unsignedSmallInteger('payment_terms_days')->default(15);
            $table->boolean('round_off_total')->default(true);
            $table->text('invoice_notes')->nullable();
            $table->text('invoice_terms')->nullable();
            $table->mediumText('logo')->nullable();            // data: URI (works without storage:link on FTP hosts)
            $table->mediumText('signature')->nullable();       // data: URI
            $table->timestamps(3);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('kind', 20)->default('services');    // services | saas | ecommerce
            $table->string('website')->nullable();
            $table->string('invoice_prefix', 5)->nullable()->unique();
            $table->string('credit_note_prefix', 5)->nullable()->unique();
            $table->string('default_sac', 8)->nullable();
            $table->decimal('default_gst_rate', 5, 2)->default(18);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps(3);
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');                              // billing / contact name
            $table->string('company')->nullable();
            $table->string('gstin', 15)->nullable()->index();
            $table->string('pan', 10)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state_code', 2)->nullable();
            $table->string('pincode', 10)->nullable();
            $table->string('country')->default('India');
            $table->text('notes')->nullable();
            $table->unsignedInteger('lead_id')->nullable()->index(); // optional link to a CRM lead
            $table->timestamps(3);
        });

        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('series', 5);
            $table->string('financial_year', 7);                 // e.g. 2026-27
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps(3);
            $table->unique(['series', 'financial_year']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('type', 12)->default('invoice');     // invoice | credit_note
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('original_invoice_id')->nullable()->constrained('invoices');
            $table->string('number', 16)->nullable()->unique(); // assigned when issued
            $table->string('series', 5)->nullable();
            $table->string('financial_year', 7)->nullable();
            $table->unsignedInteger('sequence')->nullable();
            $table->string('status', 12)->default('draft');     // draft | issued | cancelled
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('place_of_supply', 2);                // GST state code, 96 = outside India
            $table->string('supply_type', 6);                    // intra | inter | export
            $table->boolean('export_with_lut')->default(false);
            $table->boolean('reverse_charge')->default(false);
            // Buyer details as printed (copied from the client so later edits don't change issued invoices)
            $table->string('bill_name');
            $table->string('bill_company')->nullable();
            $table->string('bill_gstin', 15)->nullable();
            $table->text('bill_address')->nullable();
            $table->string('bill_state_code', 2)->nullable();
            $table->string('bill_country')->default('India');
            $table->string('bill_email')->nullable();
            $table->json('seller')->nullable();                  // business profile snapshot at issue time
            $table->decimal('taxable_total', 14, 2)->default(0);
            $table->decimal('cgst_total', 14, 2)->default(0);
            $table->decimal('sgst_total', 14, 2)->default(0);
            $table->decimal('igst_total', 14, 2)->default(0);
            $table->decimal('round_off', 8, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0);   // payments incl. TDS
            $table->decimal('amount_credited', 14, 2)->default(0); // issued credit notes against this invoice
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->string('reason')->nullable();                // credit note reason
            $table->dateTime('issued_at', 3)->nullable();
            $table->dateTime('emailed_at', 3)->nullable();
            $table->dateTime('cancelled_at', 3)->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps(3);
            $table->index(['type', 'status', 'invoice_date']);
        });

        Schema::create('work_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('sac', 8)->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->string('unit', 10)->default('NOS');
            $table->decimal('rate', 14, 2)->default(0);
            $table->decimal('gst_rate', 5, 2)->default(18);
            $table->date('work_date')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamps(3);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('work_item_id')->nullable()->constrained('work_items')->nullOnDelete();
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

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 14, 2);                    // received in bank / cash
            $table->decimal('tds_amount', 14, 2)->default(0);    // deducted by the client
            $table->string('method', 12)->default('bank');      // bank | upi | cash | cheque | card | other
            $table->string('reference')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps(3);
        });

        $now = now();
        DB::table('business_profiles')->insert([
            'legal_name' => 'LALogix Enterprises',
            'website' => 'https://lalogix.com',
            'payment_terms_days' => 15,
            'round_off_total' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $products = [
            ['LALogix Services', 'lalogix-services', 'services', 'https://lalogix.com', 'LAL', 'LALCN', '998313'],
            ['BIVK.in', 'bivk', 'saas', 'https://bivk.in', null, null, '998314'],
            ['ISO', 'iso', 'saas', null, null, null, '998314'],
            ['Amritix', 'amritix', 'saas', null, null, null, '998314'],
            ['Ayurved Dhara', 'ayurved-dhara', 'ecommerce', null, null, null, null],
        ];
        foreach ($products as $i => [$name, $slug, $kind, $website, $prefix, $cnPrefix, $sac]) {
            DB::table('products')->insert([
                'name' => $name, 'slug' => $slug, 'kind' => $kind, 'website' => $website,
                'invoice_prefix' => $prefix, 'credit_note_prefix' => $cnPrefix,
                'default_sac' => $sac, 'default_gst_rate' => 18, 'is_active' => true, 'sort' => $i,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('work_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('products');
        Schema::dropIfExists('business_profiles');
    }
};

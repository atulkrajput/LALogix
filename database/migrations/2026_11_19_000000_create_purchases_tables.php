<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6: purchases & expenses with input tax credit (ITC), and GSTR-2B
 * reconciliation (what suppliers reported on the GST portal vs. the bills recorded here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('gstin', 15)->nullable()->index();
            $table->string('state_code', 2)->nullable();
            $table->string('country')->default('India');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('default_category', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps(3);
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete(); // business line it was for
            $table->string('category', 30);                       // software, ads, hosting, rent, professional, travel, equipment, office, salary, other
            $table->string('bill_number', 50)->nullable();
            $table->date('bill_date');
            $table->string('description', 500)->nullable();
            $table->string('vendor_gstin', 15)->nullable();       // snapshot (2B matching)
            $table->string('place_of_supply', 2)->nullable();
            $table->string('supply_type', 6)->default('intra');   // intra | inter | import | none
            $table->boolean('reverse_charge')->default(false);    // tax paid by us (e.g. foreign ads / software)
            $table->decimal('taxable_value', 14, 2)->default(0);
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('cgst', 14, 2)->default(0);
            $table->decimal('sgst', 14, 2)->default(0);
            $table->decimal('igst', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);          // amount payable to the vendor
            $table->boolean('itc_eligible')->default(true);
            $table->string('itc_note')->nullable();               // e.g. blocked credit u/s 17(5)
            $table->string('payment_status', 10)->default('paid'); // paid | unpaid
            $table->date('paid_on')->nullable();
            $table->string('payment_method', 12)->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('gstr2b_status', 12)->nullable();      // matched | mismatch (set by reconciliation)
            $table->timestamps(3);
            $table->index(['bill_date']);
            $table->index(['vendor_gstin', 'bill_number']);
        });

        Schema::create('gstr2b_entries', function (Blueprint $table) {
            $table->id();
            $table->string('return_period', 6);                   // MMYYYY, e.g. 112026
            $table->string('supplier_gstin', 15);
            $table->string('supplier_name')->nullable();
            $table->string('doc_type', 10)->default('invoice');   // invoice | credit_note | debit_note
            $table->string('doc_number', 50);
            $table->date('doc_date')->nullable();
            $table->decimal('doc_value', 14, 2)->default(0);
            $table->decimal('taxable_value', 14, 2)->default(0);
            $table->decimal('igst', 14, 2)->default(0);
            $table->decimal('cgst', 14, 2)->default(0);
            $table->decimal('sgst', 14, 2)->default(0);
            $table->decimal('cess', 14, 2)->default(0);
            $table->boolean('reverse_charge')->default(false);
            $table->boolean('itc_available')->default(true);
            $table->foreignId('purchase_id')->nullable()->constrained('purchases')->nullOnDelete();
            $table->string('match_status', 12)->default('unmatched'); // matched | mismatch | unmatched
            $table->timestamps(3);
            $table->unique(['return_period', 'supplier_gstin', 'doc_type', 'doc_number'], 'gstr2b_doc_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gstr2b_entries');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('vendors');
    }
};

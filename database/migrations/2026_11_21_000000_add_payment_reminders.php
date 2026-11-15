<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Payment reminders: settings on the business profile, opt-outs, and a log of reminders sent. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->boolean('reminders_enabled')->default(false);
            $table->string('reminder_days', 60)->default('-3,1,7,15,30');   // days relative to the due date
            $table->date('reminders_started_on')->nullable();               // stages before this are never sent automatically
            $table->string('reminder_subject')->nullable();
            $table->text('reminder_message')->nullable();
            $table->dateTime('reminders_last_run_at', 3)->nullable();
            $table->string('reminders_last_error', 500)->nullable();
            $table->string('tally_company')->nullable();                    // SVCURRENTCOMPANY in Tally exports
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('no_reminders')->default(false);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('reminders_paused')->default(false);
        });

        Schema::create('invoice_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->smallInteger('stage')->nullable();                      // days from due date; null = sent by hand
            $table->string('sent_to');
            $table->string('subject');
            $table->decimal('balance_due', 14, 2)->default(0);
            $table->timestamps(3);
            $table->unique(['invoice_id', 'stage']);                        // one automatic reminder per stage (NULLs repeat)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_reminders');
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('reminders_paused'));
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('no_reminders'));
        Schema::table('business_profiles', fn (Blueprint $table) => $table->dropColumn([
            'reminders_enabled', 'reminder_days', 'reminders_started_on', 'reminder_subject', 'reminder_message',
            'reminders_last_run_at', 'reminders_last_error', 'tally_company',
        ]));
    }
};

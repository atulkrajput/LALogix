<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website lead capture (short form, quiz, chat, WhatsApp, exit popup).
 *
 * Re-uses existing columns instead of duplicating them: name, phone, email,
 * city, status, utm_source / utm_medium / utm_campaign, and product_interest
 * (= the "product" field, holding the product's lead_key from config/lalogix.php).
 * Statuses gain `trial` and `paid` (shown as New / Contacted / Demo booked /
 * Trial / Paid / Lost; `demo_scheduled` is "Demo booked").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // The short form asks for a mobile number, not an e-mail.
            $table->string('email', 191)->nullable()->change();
            $table->string('product_interest', 191)->default('general')->change();

            $table->string('business_type', 100)->nullable();
            $table->string('size', 50)->nullable();
            $table->string('source_widget', 20)->nullable()->index(); // form | quiz | chat | whatsapp | exit
            $table->string('page_url', 500)->nullable();
            $table->dateTime('consent_at', 3)->nullable();
        });

        // Clicks that are not (yet) a lead, e.g. the floating WhatsApp button.
        Schema::create('lead_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30)->index();             // whatsapp_click
            $table->string('product', 50)->nullable()->index();
            $table->string('location', 50)->nullable();       // floating, header, footer, ...
            $table->string('page_url', 500)->nullable();
            $table->string('utm_source', 191)->nullable();
            $table->string('utm_medium', 191)->nullable();
            $table->string('utm_campaign', 191)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->integer('lead_id')->nullable()->index();
            $table->dateTime('created_at', 3)->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_events');

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['source_widget']);
            $table->dropColumn(['business_type', 'size', 'source_widget', 'page_url', 'consent_at']);
        });
    }
};

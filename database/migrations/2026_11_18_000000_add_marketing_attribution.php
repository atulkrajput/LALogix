<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: campaign attribution. Website leads remember which ad brought them
 * (UTM tags / click ids), clients keep it when created from a lead or synced from
 * a product, and ad spend is stored per platform, campaign and day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('utm_source', 191)->nullable();
            $table->string('utm_medium', 191)->nullable();
            $table->string('utm_campaign', 191)->nullable()->index();
            $table->string('utm_content', 191)->nullable();
            $table->string('utm_term', 191)->nullable();
            $table->string('click_id', 255)->nullable();       // fbclid / gclid
            $table->string('landing_page', 500)->nullable();
            $table->string('referrer', 500)->nullable();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('utm_source', 191)->nullable()->after('product_id');
            $table->string('utm_campaign', 191)->nullable()->after('utm_source')->index();
        });

        Schema::create('ad_spend', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 20);                       // meta | google | other
            $table->date('date');
            $table->string('campaign_id', 100)->nullable();
            $table->string('campaign_name');
            $table->string('campaign_key');                       // lower-cased id or name; joins with utm_campaign
            $table->decimal('spend', 12, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('platform_leads')->default(0); // leads as counted by the ad platform
            $table->string('source', 10)->default('api');         // api | import
            $table->timestamps(3);
            $table->unique(['platform', 'date', 'campaign_key']);
            $table->index('date');
        });

        Schema::table('business_profiles', function (Blueprint $table) {
            $table->string('meta_ad_account_id', 40)->nullable();
            $table->text('meta_access_token')->nullable();        // encrypted cast
            $table->dateTime('meta_synced_at', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->dropColumn(['meta_ad_account_id', 'meta_access_token', 'meta_synced_at']);
        });
        Schema::dropIfExists('ad_spend');
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['utm_campaign']);
            $table->dropColumn(['utm_source', 'utm_campaign']);
        });
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['utm_campaign']);
            $table->dropColumn(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'click_id', 'landing_page', 'referrer']);
        });
    }
};

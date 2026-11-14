<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema ported from the old Prisma schema (old/prisma/schema.prisma).
 *
 * Every table is created only if it does not exist yet, so this migration can run
 * against the existing `dp` database that Prisma already created without touching
 * its data, and also against a brand-new empty database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('name', 191);
                $table->string('email', 191)->unique('users_email_key');
                $table->string('password', 191);
                $table->string('role', 191)->default('sales');
                $table->dateTime('created_at', 3)->useCurrent();
            });
        }

        if (! Schema::hasTable('leads')) {
            Schema::create('leads', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('name', 191);
                $table->string('organization', 191)->nullable();
                $table->string('city', 191)->nullable();
                $table->string('state', 191)->nullable();
                $table->string('phone', 191)->nullable();
                $table->string('email', 191);
                $table->text('message')->nullable();
                $table->string('product_interest', 191);
                $table->string('source', 191)->default('website');
                $table->string('status', 191)->default('new');
                $table->integer('score')->default(0);
                $table->dateTime('next_followup_date', 3)->nullable();
                $table->string('followup_status', 191)->nullable();
                $table->dateTime('created_at', 3)->useCurrent();
                $table->dateTime('updated_at', 3);

                $table->index('email', 'leads_email_idx');
                $table->index('phone', 'leads_phone_idx');
                $table->index('status', 'leads_status_idx');
                $table->index('city', 'leads_city_idx');
                $table->index('created_at', 'leads_created_at_idx');
            });
        }

        if (! Schema::hasTable('demo_bookings')) {
            Schema::create('demo_bookings', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('lead_id');
                $table->string('scheduled_date', 191);
                $table->string('scheduled_time', 191);
                $table->string('meeting_link', 191)->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 191)->default('scheduled');
                $table->dateTime('created_at', 3)->useCurrent();

                $table->index('lead_id', 'demo_bookings_lead_id_idx');
                $table->index('status', 'demo_bookings_status_idx');
                $table->index('scheduled_date', 'demo_bookings_scheduled_date_idx');
                $table->foreign('lead_id', 'demo_bookings_lead_id_fkey')
                    ->references('id')->on('leads')->cascadeOnDelete()->cascadeOnUpdate();
            });
        }

        if (! Schema::hasTable('activities')) {
            Schema::create('activities', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('lead_id');
                $table->string('type', 191);
                $table->text('notes')->nullable();
                $table->dateTime('created_at', 3)->useCurrent();

                $table->index('lead_id', 'activities_lead_id_idx');
                $table->foreign('lead_id', 'activities_lead_id_fkey')
                    ->references('id')->on('leads')->cascadeOnDelete()->cascadeOnUpdate();
            });
        }

        if (! Schema::hasTable('lead_tags')) {
            Schema::create('lead_tags', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('lead_id');
                $table->string('tag_name', 191);

                $table->index('lead_id', 'lead_tags_lead_id_idx');
                $table->index('tag_name', 'lead_tags_tag_name_idx');
                $table->unique(['lead_id', 'tag_name'], 'lead_tags_lead_id_tag_name_key');
                $table->foreign('lead_id', 'lead_tags_lead_id_fkey')
                    ->references('id')->on('leads')->cascadeOnDelete()->cascadeOnUpdate();
            });
        }

        if (! Schema::hasTable('campaigns')) {
            Schema::create('campaigns', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('name', 191);
                $table->string('type', 191);    // email / whatsapp / ads / manual
                $table->string('product', 191); // school / inventory / dairy
                $table->string('status', 191)->default('active'); // active / paused / completed
                $table->dateTime('created_at', 3)->useCurrent();

                $table->index('status', 'campaigns_status_idx');
                $table->index('type', 'campaigns_type_idx');
            });
        }

        if (! Schema::hasTable('campaign_leads')) {
            Schema::create('campaign_leads', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('campaign_id');
                $table->integer('lead_id');
                $table->dateTime('assigned_at', 3)->useCurrent();

                $table->index('campaign_id', 'campaign_leads_campaign_id_idx');
                $table->index('lead_id', 'campaign_leads_lead_id_idx');
                $table->unique(['campaign_id', 'lead_id'], 'campaign_leads_campaign_id_lead_id_key');
                $table->foreign('campaign_id', 'campaign_leads_campaign_id_fkey')
                    ->references('id')->on('campaigns')->cascadeOnDelete()->cascadeOnUpdate();
                $table->foreign('lead_id', 'campaign_leads_lead_id_fkey')
                    ->references('id')->on('leads')->cascadeOnDelete()->cascadeOnUpdate();
            });
        }

        if (! Schema::hasTable('email_templates')) {
            Schema::create('email_templates', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('name', 191);
                $table->string('subject', 191);
                $table->text('body');
                $table->dateTime('created_at', 3)->useCurrent();
            });
        }

        if (! Schema::hasTable('whatsapp_logs')) {
            Schema::create('whatsapp_logs', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('lead_id');
                $table->text('message');
                $table->string('status', 191)->default('sent'); // sent / delivered / failed
                $table->dateTime('created_at', 3)->useCurrent();

                $table->index('lead_id', 'whatsapp_logs_lead_id_idx');
                $table->index('status', 'whatsapp_logs_status_idx');
                $table->foreign('lead_id', 'whatsapp_logs_lead_id_fkey')
                    ->references('id')->on('leads')->cascadeOnDelete()->cascadeOnUpdate();
            });
        }

        if (! Schema::hasTable('automation_rules')) {
            Schema::create('automation_rules', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('name', 191);
                $table->string('trigger', 191); // new_lead / no_contact_2days / demo_completed
                $table->string('action', 191);  // send_email / mark_followup / send_whatsapp
                $table->integer('template_id')->nullable();
                $table->boolean('enabled')->default(true);
                $table->dateTime('created_at', 3)->useCurrent();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'automation_rules', 'whatsapp_logs', 'email_templates', 'campaign_leads',
            'campaigns', 'lead_tags', 'activities', 'demo_bookings', 'leads', 'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

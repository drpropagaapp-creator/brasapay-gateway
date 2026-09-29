<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_whatsapp_channels', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 24)->default('uazapi');
            $table->string('server_url', 255)->nullable();
            $table->string('instance_name', 120)->nullable();
            $table->text('instance_token')->nullable();
            $table->string('webhook_secret', 64)->unique();
            $table->string('status', 32)->default('disconnected');
            $table->string('phone', 32)->nullable();
            $table->string('profile_name', 120)->nullable();
            $table->text('qrcode')->nullable();
            $table->string('paircode', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('last_error')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('webhook_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 40)->unique();
            $table->boolean('enabled')->default(true);
            $table->text('message');
            $table->timestamps();
        });

        Schema::create('platform_whatsapp_campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->string('status', 24)->default('queued');
            $table->text('message');
            $table->unsignedSmallInteger('delay_seconds')->default(10);
            $table->json('filters')->nullable();
            $table->unsignedInteger('queued_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_whatsapp_dispatches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('campaign_id')->nullable()->index();
            $table->string('event_type', 40);
            $table->string('phone', 20);
            $table->text('message');
            $table->string('status', 24)->default('pending');
            $table->string('wa_status', 24)->nullable();
            $table->string('provider_message_id', 80)->nullable();
            $table->string('track_id', 80)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['event_type', 'user_id']);
            $table->index('track_id');
        });

        Schema::create('platform_whatsapp_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('phone', 20);
            $table->string('source', 32)->nullable();
            $table->string('inbound_text', 500)->nullable();
            $table->timestamps();

            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_whatsapp_opt_outs');
        Schema::dropIfExists('platform_whatsapp_dispatches');
        Schema::dropIfExists('platform_whatsapp_campaigns');
        Schema::dropIfExists('platform_whatsapp_templates');
        Schema::dropIfExists('platform_whatsapp_channels');
    }
};

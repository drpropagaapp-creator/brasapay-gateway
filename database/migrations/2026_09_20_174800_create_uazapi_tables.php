<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_uazapi_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(false);
            $table->string('server_url', 255)->nullable();
            $table->text('admin_token')->nullable();
            $table->timestamps();
        });

        Schema::create('uazapi_instances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            $table->string('instance_id', 64)->nullable();
            $table->string('instance_name', 120)->nullable();
            $table->text('instance_token')->nullable();
            $table->string('webhook_secret', 64)->unique();
            $table->string('status', 32)->default('disconnected');
            $table->string('phone', 32)->nullable();
            $table->string('profile_name', 120)->nullable();
            $table->text('qrcode')->nullable();
            $table->string('paircode', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('cart_recovery_enabled')->default(false);
            $table->boolean('pix_recovery_enabled')->default(false);
            $table->json('cart_recovery_steps')->nullable();
            $table->text('message_pix')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('uazapi_message_dispatches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('uazapi_instance_id')->nullable()->index();
            $table->unsignedBigInteger('checkout_session_id')->nullable()->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('event_type', 40);
            $table->unsignedTinyInteger('sequence_step')->nullable();
            $table->string('phone', 20);
            $table->text('message');
            $table->json('payload')->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('wa_status', 24)->nullable();
            $table->string('provider_message_id', 80)->nullable();
            $table->string('track_id', 80)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['checkout_session_id', 'event_type', 'sequence_step'], 'uazapi_dispatches_session_step_idx');
            $table->index(['order_id', 'event_type', 'status'], 'uazapi_dispatches_order_event_idx');
            $table->index('track_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uazapi_message_dispatches');
        Schema::dropIfExists('uazapi_instances');
        Schema::dropIfExists('platform_uazapi_settings');
    }
};

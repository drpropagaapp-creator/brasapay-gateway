<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evolution_instances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name', 120)->nullable();
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
            $table->boolean('is_default')->default(false);
            $table->boolean('cart_recovery_enabled')->default(false);
            $table->boolean('pix_recovery_enabled')->default(false);
            $table->boolean('send_product_image')->default(true);
            $table->json('cart_recovery_steps')->nullable();
            $table->json('pix_recovery_steps')->nullable();
            $table->text('message_pix')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('webhook_synced_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_default']);
            $table->index('status');
        });

        Schema::create('evolution_instance_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evolution_instance_id')->constrained('evolution_instances')->cascadeOnDelete();
            $table->string('product_id', 36);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(['evolution_instance_id', 'product_id'], 'evolution_instance_product_unique');
        });

        Schema::create('evolution_message_dispatches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('evolution_instance_id')->nullable()->index();
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

            $table->index(['checkout_session_id', 'event_type', 'sequence_step'], 'evolution_dispatches_session_step_idx');
            $table->index(['order_id', 'event_type', 'status'], 'evolution_dispatches_order_event_idx');
            $table->index('track_id');
        });

        Schema::create('evolution_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('evolution_instance_id')->nullable()->index();
            $table->string('phone', 20);
            $table->string('source', 32)->nullable();
            $table->string('inbound_text', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'phone']);
        });

        Schema::create('evolution_recovery_stops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('evolution_instance_id')->nullable()->index();
            $table->string('phone', 20)->index();
            $table->string('reason', 32);
            $table->string('inbound_text', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evolution_recovery_stops');
        Schema::dropIfExists('evolution_opt_outs');
        Schema::dropIfExists('evolution_message_dispatches');
        Schema::dropIfExists('evolution_instance_product');
        Schema::dropIfExists('evolution_instances');
    }
};

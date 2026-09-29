<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uazapi_instances', function (Blueprint $table) {
            $table->boolean('send_product_image')->default(true)->after('pix_recovery_enabled');
            $table->json('label_map')->nullable()->after('webhook_synced_at');
        });

        Schema::create('uazapi_campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('uazapi_instance_id')->nullable()->index();
            $table->string('audience', 32);
            $table->text('message');
            $table->boolean('include_image')->default(true);
            $table->string('status', 24)->default('queued');
            $table->unsignedInteger('queued_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::table('uazapi_message_dispatches', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id')->nullable()->after('order_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('uazapi_message_dispatches', function (Blueprint $table) {
            $table->dropColumn('campaign_id');
        });
        Schema::dropIfExists('uazapi_campaigns');
        Schema::table('uazapi_instances', function (Blueprint $table) {
            $table->dropColumn(['send_product_image', 'label_map']);
        });
    }
};

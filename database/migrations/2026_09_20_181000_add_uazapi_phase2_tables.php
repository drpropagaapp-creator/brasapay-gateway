<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uazapi_instances', function (Blueprint $table) {
            $table->json('pix_recovery_steps')->nullable()->after('cart_recovery_steps');
            $table->timestamp('webhook_synced_at')->nullable()->after('connected_at');
        });

        Schema::create('uazapi_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('uazapi_instance_id')->nullable()->index();
            $table->string('phone', 20);
            $table->string('source', 32)->default('inbound');
            $table->string('inbound_text', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'phone']);
            $table->index('phone');
        });

        Schema::create('uazapi_recovery_stops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('uazapi_instance_id')->nullable()->index();
            $table->string('phone', 20);
            $table->string('reason', 32);
            $table->string('inbound_text', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'phone', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uazapi_recovery_stops');
        Schema::dropIfExists('uazapi_opt_outs');

        Schema::table('uazapi_instances', function (Blueprint $table) {
            $table->dropColumn(['pix_recovery_steps', 'webhook_synced_at']);
        });
    }
};

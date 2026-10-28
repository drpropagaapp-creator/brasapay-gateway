<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Autorização de adquirência por seller (definida só pelo admin) + histórico.
 * Tabelas novas: sellers sem linha aqui continuam com o comportamento atual.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seller_acquirer_policies')) {
            Schema::create('seller_acquirer_policies', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                // {"pix": {"mode": "restricted", "gateways": ["cajupay","woovi"]}, "card": {"mode": "disabled"}, ...}
                $table->json('methods');
                $table->unsignedBigInteger('updated_by_user_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('seller_acquirer_policy_logs')) {
            Schema::create('seller_acquirer_policy_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->string('reason', 500)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_acquirer_policy_logs');
        Schema::dropIfExists('seller_acquirer_policies');
    }
};

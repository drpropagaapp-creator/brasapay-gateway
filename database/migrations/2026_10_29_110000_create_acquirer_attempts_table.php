<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telemetria de tentativas de cobrança por adquirente (BrasaPay 4.0 — monitoramento e Smart Routing).
 * Tabela nova; retenção curta (prune diário).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('acquirer_attempts')) {
            return;
        }

        Schema::create('acquirer_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('gateway_slug', 40);
            $table->string('method', 20);
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('order_id', 64)->nullable();
            // success | declined | technical_error | timeout
            $table->string('outcome', 20);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('strategy', 20)->nullable();
            $table->string('error_class', 120)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['gateway_slug', 'method', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquirer_attempts');
    }
};

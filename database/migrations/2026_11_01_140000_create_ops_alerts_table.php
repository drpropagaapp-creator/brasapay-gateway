<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Command Center: central de alertas com histórico. Cada alerta é derivado de números reais
 * (a cada 5 minutos); some sozinho quando a condição deixa de existir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ops_alerts')) {
            return;
        }
        Schema::create('ops_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120);
            $table->string('severity', 12); // info | attention | high | critical
            $table->string('area', 20); // pagamentos | financeiro | risco | sellers | infraestrutura | automacoes
            $table->string('title', 255);
            $table->string('hint', 500)->nullable();
            $table->string('href', 255)->nullable();
            $table->string('status', 15)->default('open'); // open | acknowledged | resolved
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('ack_note', 500)->nullable();
            $table->timestamps();
            $table->index(['key', 'status']);
            $table->index(['status', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_alerts');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brasa Treasury (BrasaPay 4.0): divergências de conciliação e histórico de execuções.
 * Só cria tabelas novas; não altera saldos nem lançamentos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('treasury_discrepancies')) {
            Schema::create('treasury_discrepancies', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 64);
                $table->string('severity', 16);
                $table->string('status', 24)->default('open');
                // Identidade estável da divergência: a mesma ocorrência não duplica entre execuções.
                $table->string('fingerprint', 128)->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('order_id', 64)->nullable()->index();
                $table->unsignedBigInteger('withdrawal_id')->nullable()->index();
                $table->bigInteger('amount_cents')->default(0);
                $table->json('details')->nullable();
                $table->timestamp('first_seen_at');
                $table->timestamp('last_seen_at');
                $table->timestamp('resolved_at')->nullable();
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->text('resolution_note')->nullable();
                $table->timestamps();
                $table->index(['status', 'severity']);
                $table->index('kind');
            });
        }

        if (! Schema::hasTable('treasury_runs')) {
            Schema::create('treasury_runs', function (Blueprint $table) {
                $table->id();
                $table->timestamp('started_at');
                $table->timestamp('finished_at')->nullable();
                $table->string('status', 16)->default('running');
                $table->json('counts')->nullable();
                $table->text('error')->nullable();
                $table->unsignedBigInteger('triggered_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_runs');
        Schema::dropIfExists('treasury_discrepancies');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retrato imutável da divisão do bruto de cada venda (produtor, co-produtores,
 * afiliado) gravado antes do crédito na carteira. Pedidos antigos continuam com
 * a regra registrada aqui mesmo que os percentuais do produto mudem depois.
 * Tabela nova: não altera nenhum dado financeiro existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_split_snapshots')) {
            return;
        }

        Schema::create('order_split_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedBigInteger('seller_tenant_id')->index();
            $table->unsignedSmallInteger('rule_version');
            $table->bigInteger('total_cents');
            $table->json('slices');
            $table->json('issues')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_split_snapshots');
    }
};

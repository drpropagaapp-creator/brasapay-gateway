<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brasa Intelligence: custo de aquisição informado pelo seller (por dia, origem e campanha).
 * ROAS e CAC só são calculados onde existe custo aqui — nunca estimados.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ad_spends')) {
            return;
        }
        Schema::create('ad_spends', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->date('spent_on');
            $table->string('source', 100);
            $table->string('campaign', 191)->nullable();
            $table->unsignedBigInteger('amount_cents');
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'spent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_spends');
    }
};

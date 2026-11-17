<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ofertas criadas na Cakto por valor: a API da Cakto só cobra ofertas de preço fixo,
 * então o BrasaPay cria uma oferta por valor e reaproveita nas próximas vendas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cakto_offers')) {
            return;
        }

        Schema::create('cakto_offers', function (Blueprint $table) {
            $table->id();
            $table->string('cakto_product_id', 64);
            $table->unsignedBigInteger('amount_cents');
            $table->string('offer_id', 64);
            $table->timestamps();
            $table->unique(['cakto_product_id', 'amount_cents']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cakto_offers');
    }
};

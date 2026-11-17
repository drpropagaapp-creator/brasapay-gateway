<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cartão salvo pela API: o sistema do seller (aplicação da API) guarda o cartão do cliente
 * no checkout hospedado e cobra as próximas vezes sem o cliente digitar de novo.
 *
 * - saved_payment_methods: cliente na adquirente (Stripe exige), aplicação dona, pedido de
 *   origem (o cartão só vale depois que esse pedido foi pago) e validade.
 * - api_checkout_sessions: pedido de salvar o cartão e quais métodos a página mostra.
 *
 * Só colunas anuláveis/com padrão: nada muda para quem não usa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_payment_methods', function (Blueprint $table) {
            if (! Schema::hasColumn('saved_payment_methods', 'gateway_customer_id')) {
                $table->string('gateway_customer_id')->nullable();
            }
            if (! Schema::hasColumn('saved_payment_methods', 'api_application_id')) {
                $table->unsignedBigInteger('api_application_id')->nullable()->index();
            }
            if (! Schema::hasColumn('saved_payment_methods', 'source_order_id')) {
                $table->unsignedBigInteger('source_order_id')->nullable()->index();
            }
            if (! Schema::hasColumn('saved_payment_methods', 'exp_month')) {
                $table->unsignedSmallInteger('exp_month')->nullable();
            }
            if (! Schema::hasColumn('saved_payment_methods', 'exp_year')) {
                $table->unsignedSmallInteger('exp_year')->nullable();
            }
        });

        Schema::table('api_checkout_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('api_checkout_sessions', 'save_card')) {
                $table->boolean('save_card')->default(false);
            }
            if (! Schema::hasColumn('api_checkout_sessions', 'payment_methods')) {
                $table->json('payment_methods')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('saved_payment_methods', function (Blueprint $table) {
            foreach (['gateway_customer_id', 'api_application_id', 'source_order_id', 'exp_month', 'exp_year'] as $col) {
                if (Schema::hasColumn('saved_payment_methods', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('api_checkout_sessions', function (Blueprint $table) {
            foreach (['save_card', 'payment_methods'] as $col) {
                if (Schema::hasColumn('api_checkout_sessions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

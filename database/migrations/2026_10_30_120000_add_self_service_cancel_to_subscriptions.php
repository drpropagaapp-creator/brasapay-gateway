<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brasa Subscriptions: cancelamento pelo próprio assinante ao fim do período pago.
 * Só adiciona colunas (nullable/default), sem tocar nos dados existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscriptions', 'cancel_at_period_end')) {
                $table->boolean('cancel_at_period_end')->default(false);
            }
            if (! Schema::hasColumn('subscriptions', 'cancellation_requested_at')) {
                $table->timestamp('cancellation_requested_at')->nullable();
            }
            if (! Schema::hasColumn('subscriptions', 'cancellation_reason')) {
                $table->string('cancellation_reason', 40)->nullable();
            }
            if (! Schema::hasColumn('subscriptions', 'cancellation_note')) {
                $table->text('cancellation_note')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            foreach (['cancel_at_period_end', 'cancellation_requested_at', 'cancellation_reason', 'cancellation_note'] as $col) {
                if (Schema::hasColumn('subscriptions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

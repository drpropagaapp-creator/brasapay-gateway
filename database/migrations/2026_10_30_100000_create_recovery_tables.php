<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brasa Recovery (BrasaPay 4.0, Onda 2): histórico de mensagens de recuperação e descadastros.
 * Só tabelas novas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recovery_messages')) {
            Schema::create('recovery_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('channel', 16); // email
                $table->string('event', 32); // cart_abandoned | pix_pending | boleto_pending
                $table->unsignedTinyInteger('step');
                // Origem: sessão de checkout (carrinho) ou pedido (PIX/boleto). Chave estável para não repetir.
                $table->string('subject_key', 80);
                $table->unsignedBigInteger('checkout_session_id')->nullable();
                $table->string('order_id', 64)->nullable();
                $table->string('product_id', 64)->nullable();
                $table->string('recipient', 190);
                $table->string('status', 16); // sent | failed | skipped
                $table->string('skip_reason', 40)->nullable();
                $table->text('error')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->string('recovered_order_id', 64)->nullable()->index();
                $table->bigInteger('recovered_amount_cents')->nullable();
                $table->timestamp('recovered_at')->nullable();
                $table->timestamps();
                $table->unique(['channel', 'event', 'subject_key', 'step'], 'recovery_messages_unique_step');
                $table->index(['tenant_id', 'recipient', 'created_at']);
            });
        }

        if (! Schema::hasTable('recovery_opt_outs')) {
            Schema::create('recovery_opt_outs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('email', 190);
                $table->string('channel', 16)->default('email');
                $table->timestamps();
                $table->unique(['tenant_id', 'email', 'channel']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_opt_outs');
        Schema::dropIfExists('recovery_messages');
    }
};

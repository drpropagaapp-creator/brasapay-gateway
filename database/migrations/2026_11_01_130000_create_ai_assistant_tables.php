<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brasa AI: histórico de conversas do assistente e consumo por conta (tokens e custo estimado em
 * micro-dólares). Guarda só as perguntas, as respostas finais e os nomes das ferramentas usadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_conversations')) {
            Schema::create('ai_conversations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->string('title', 120);
                $table->timestamps();
                $table->index(['tenant_id', 'user_id', 'updated_at']);
            });
        }

        if (! Schema::hasTable('ai_messages')) {
            Schema::create('ai_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('conversation_id');
                $table->unsignedBigInteger('tenant_id');
                $table->string('role', 12); // user | assistant
                $table->text('content');
                $table->json('tools_used')->nullable();
                $table->string('model', 60)->nullable();
                $table->unsignedInteger('input_tokens')->default(0);
                $table->unsignedInteger('output_tokens')->default(0);
                $table->unsignedBigInteger('cost_micros')->default(0); // US$ × 1.000.000 (estimativa pela tabela de preços)
                $table->string('status', 12)->default('ok'); // ok | error | refused
                $table->unsignedInteger('duration_ms')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['conversation_id', 'id']);
                $table->index(['tenant_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brasa Developers: chaves de sandbox separadas, registro de chamadas da API (sem corpo),
 * tempo de resposta dos webhooks e transações de sandbox (sem efeito financeiro).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('api_keys', 'environment')) {
            Schema::table('api_keys', function (Blueprint $table) {
                $table->string('environment', 10)->default('live');
            });
        }

        Schema::table('api_webhook_deliveries', function (Blueprint $table) {
            if (! Schema::hasColumn('api_webhook_deliveries', 'duration_ms')) {
                $table->unsignedInteger('duration_ms')->nullable();
            }
            if (! Schema::hasColumn('api_webhook_deliveries', 'livemode')) {
                $table->boolean('livemode')->default(true);
            }
        });

        if (! Schema::hasTable('api_request_logs')) {
            Schema::create('api_request_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('api_application_id');
                $table->unsignedBigInteger('api_key_id')->nullable();
                $table->string('environment', 10)->default('live');
                $table->string('method', 8);
                $table->string('route', 120);
                $table->unsignedSmallInteger('status');
                $table->unsignedInteger('duration_ms');
                $table->string('request_id', 64)->nullable();
                $table->string('error_message', 255)->nullable();
                $table->string('ip', 45)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['api_application_id', 'created_at']);
                $table->index(['tenant_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('sandbox_transactions')) {
            Schema::create('sandbox_transactions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('api_application_id');
                $table->unsignedBigInteger('api_key_id')->nullable();
                $table->string('kind', 12); // payment | withdrawal
                $table->string('method', 12)->nullable();
                $table->string('status', 20);
                $table->unsignedBigInteger('amount_cents');
                $table->string('currency', 3)->default('BRL');
                $table->string('customer_email', 190)->nullable();
                $table->json('metadata')->nullable();
                $table->string('failed_reason', 255)->nullable();
                $table->timestamps();
                $table->index(['api_application_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sandbox_transactions');
        Schema::dropIfExists('api_request_logs');
        Schema::table('api_webhook_deliveries', function (Blueprint $table) {
            $table->dropColumn(['duration_ms', 'livemode']);
        });
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('environment');
        });
    }
};

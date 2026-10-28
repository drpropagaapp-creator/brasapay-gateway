<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brasa Enterprise: white-label controlado pelo admin. Só marca e domínio de exibição —
 * o tenant financeiro, as credenciais de gateway e a responsabilidade regulatória não mudam.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enterprise_accounts')) {
            Schema::create('enterprise_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->string('status', 20)->default('active'); // active | suspended
                $table->string('display_name', 120)->nullable();
                $table->json('features')->nullable(); // ex.: hidden_nav
                $table->json('limits')->nullable(); // ex.: max_domains
                $table->string('suspension_reason', 500)->nullable();
                $table->unsignedBigInteger('enabled_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('enterprise_domains')) {
            Schema::create('enterprise_domains', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('host', 190)->unique();
                $table->string('verification_token', 64);
                $table->string('status', 20)->default('pending'); // pending | active | failed | removed
                $table->timestamp('dns_verified_at')->nullable();
                $table->timestamp('https_verified_at')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->unsignedTinyInteger('consecutive_failures')->default(0);
                $table->string('last_error', 255)->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_domains');
        Schema::dropIfExists('enterprise_accounts');
    }
};

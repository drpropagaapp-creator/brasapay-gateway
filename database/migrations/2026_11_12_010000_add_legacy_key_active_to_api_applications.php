<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revogar a chave original (legacy) da aplicação passa a desligar só essa credencial.
 * Antes o botão "Revogar" da linha legacy gravava api_applications.is_active = false, o que
 * desligava a integração inteira (chaves novas, checkout hospedado e webhooks).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('api_applications') || Schema::hasColumn('api_applications', 'legacy_key_active')) {
            return;
        }

        Schema::table('api_applications', function (Blueprint $table) {
            $table->boolean('legacy_key_active')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('api_applications') && Schema::hasColumn('api_applications', 'legacy_key_active')) {
            Schema::table('api_applications', function (Blueprint $table) {
                $table->dropColumn('legacy_key_active');
            });
        }
    }
};

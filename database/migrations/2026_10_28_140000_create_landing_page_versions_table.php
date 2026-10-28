<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico das landings publicadas (produto, oferta ou plano) para restauração.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('landing_page_versions')) {
            return;
        }

        Schema::create('landing_page_versions', function (Blueprint $table) {
            $table->id();
            $table->string('product_id', 36)->index();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('scope', 16)->default('product');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->json('landing');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['product_id', 'scope', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_page_versions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acompanhamento de cada importação de pacote de produto (prévia → confirmação → resultado).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_imports')) {
            return;
        }

        Schema::create('product_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status', 16)->index();
            $table->string('package_path', 500)->nullable();
            $table->string('package_sha256', 64)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->json('summary')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_imports');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCP da agência (ex.: MetaTrack): chave fixa de integração da plataforma e fila de
 * importação de pacotes de produto (zip).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mcp_platform_keys')) {
            Schema::create('mcp_platform_keys', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('public_id', 16)->unique();
                $table->string('token_hash');
                $table->string('token_suffix', 8);
                $table->string('scope', 32)->default('agency');
                $table->unsignedBigInteger('created_by_user_id')->index();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('mcp_product_imports')) {
            Schema::create('mcp_product_imports', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('platform_key_id')->nullable()->index();
                $table->unsignedBigInteger('actor_user_id');
                $table->text('source_url');
                $table->json('manifest')->nullable();
                $table->string('status', 16)->default('queued');
                $table->json('progress')->nullable();
                $table->json('result')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_product_imports');
        Schema::dropIfExists('mcp_platform_keys');
    }
};

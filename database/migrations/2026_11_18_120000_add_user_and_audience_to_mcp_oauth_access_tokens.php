<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCP: o token OAuth passa a saber quem autorizou e para qual painel.
 * audience "seller" = tenant do infoprodutor (comportamento antigo); "platform" = admin da plataforma.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mcp_oauth_access_tokens')) {
            return;
        }

        Schema::table('mcp_oauth_access_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('mcp_oauth_access_tokens', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->index()->after('tenant_id');
            }
            if (! Schema::hasColumn('mcp_oauth_access_tokens', 'audience')) {
                $table->string('audience', 16)->default('seller')->after('user_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mcp_oauth_access_tokens')) {
            return;
        }

        Schema::table('mcp_oauth_access_tokens', function (Blueprint $table) {
            if (Schema::hasColumn('mcp_oauth_access_tokens', 'audience')) {
                $table->dropColumn('audience');
            }
            if (Schema::hasColumn('mcp_oauth_access_tokens', 'user_id')) {
                $table->dropIndex(['user_id']);
                $table->dropColumn('user_id');
            }
        });
    }
};

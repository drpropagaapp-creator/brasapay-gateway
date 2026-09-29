<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uazapi_instances', function (Blueprint $table) {
            $table->string('server_url', 255)->nullable()->after('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('uazapi_instances', function (Blueprint $table) {
            $table->dropColumn('server_url');
        });
    }
};

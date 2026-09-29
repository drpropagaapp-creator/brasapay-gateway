<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uazapi_instances', function (Blueprint $table) {
            $table->dropUnique(['tenant_id']);
            $table->index('tenant_id');
            $table->string('name', 120)->nullable()->after('tenant_id');
            $table->boolean('is_default')->default(false)->after('is_active');
            $table->timestamp('last_used_at')->nullable()->after('webhook_synced_at');
        });

        DB::table('uazapi_instances')
            ->where(function ($query) {
                $query->whereNull('name')->orWhere('name', '');
            })
            ->update(['name' => 'Conta principal']);

        $tenantIds = DB::table('uazapi_instances')->distinct()->pluck('tenant_id');
        foreach ($tenantIds as $tenantId) {
            $firstId = DB::table('uazapi_instances')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->value('id');

            if ($firstId) {
                DB::table('uazapi_instances')->where('id', $firstId)->update(['is_default' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('uazapi_instances', function (Blueprint $table) {
            $table->dropColumn(['name', 'is_default', 'last_used_at']);
            $table->dropIndex(['tenant_id']);
            $table->unique('tenant_id');
        });
    }
};

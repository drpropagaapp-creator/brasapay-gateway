<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || Schema::hasColumn('products', 'marketplace_approved')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('marketplace_approved')->default(false)->after('show_in_marketplace');
        });

        if (Schema::hasColumn('products', 'show_in_marketplace')) {
            DB::table('products')->where('show_in_marketplace', true)->update([
                'marketplace_approved' => true,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'marketplace_approved')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('marketplace_approved');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_user') || Schema::hasColumn('product_user', 'access_ref')) {
            return;
        }

        Schema::table('product_user', function (Blueprint $table) {
            $table->string('access_ref', 16)->nullable()->unique();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_user') || ! Schema::hasColumn('product_user', 'access_ref')) {
            return;
        }

        Schema::table('product_user', function (Blueprint $table) {
            $table->dropUnique(['access_ref']);
            $table->dropColumn('access_ref');
        });
    }
};

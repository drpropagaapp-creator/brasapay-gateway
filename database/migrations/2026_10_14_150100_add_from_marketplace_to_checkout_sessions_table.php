<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('checkout_sessions') || Schema::hasColumn('checkout_sessions', 'from_marketplace')) {
            return;
        }

        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->boolean('from_marketplace')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('checkout_sessions') || ! Schema::hasColumn('checkout_sessions', 'from_marketplace')) {
            return;
        }

        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->dropColumn('from_marketplace');
        });
    }
};

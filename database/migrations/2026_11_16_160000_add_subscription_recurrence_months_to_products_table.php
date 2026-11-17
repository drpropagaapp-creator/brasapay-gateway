<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || Schema::hasColumn('products', 'subscription_recurrence_months')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('subscription_recurrence_months')->nullable()->after('billing_type');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'subscription_recurrence_months')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('subscription_recurrence_months');
        });
    }
};

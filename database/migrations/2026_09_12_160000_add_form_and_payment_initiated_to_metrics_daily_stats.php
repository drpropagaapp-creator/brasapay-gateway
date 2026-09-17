<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('metrics_daily_stats')) {
            return;
        }

        Schema::table('metrics_daily_stats', function (Blueprint $table) {
            if (! Schema::hasColumn('metrics_daily_stats', 'checkouts_form_started')) {
                $table->unsignedInteger('checkouts_form_started')->default(0);
            }
            if (! Schema::hasColumn('metrics_daily_stats', 'payments_initiated')) {
                $table->unsignedInteger('payments_initiated')->default(0);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('metrics_daily_stats')) {
            return;
        }

        Schema::table('metrics_daily_stats', function (Blueprint $table) {
            if (Schema::hasColumn('metrics_daily_stats', 'checkouts_form_started')) {
                $table->dropColumn('checkouts_form_started');
            }
            if (Schema::hasColumn('metrics_daily_stats', 'payments_initiated')) {
                $table->dropColumn('payments_initiated');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wallet_transactions')) {
            try {
                Schema::table('wallet_transactions', function (Blueprint $table) {
                    $table->index(['created_at', 'type'], 'wallet_tx_created_type_index');
                });
            } catch (\Throwable) {
                // Índice já existe.
            }
        }

        if (Schema::hasTable('withdrawals')) {
            try {
                Schema::table('withdrawals', function (Blueprint $table) {
                    $table->index(['created_at', 'status'], 'withdrawals_created_status_index');
                });
            } catch (\Throwable) {
                // Índice já existe.
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wallet_transactions')) {
            try {
                Schema::table('wallet_transactions', function (Blueprint $table) {
                    $table->dropIndex('wallet_tx_created_type_index');
                });
            } catch (\Throwable) {
                //
            }
        }

        if (Schema::hasTable('withdrawals')) {
            try {
                Schema::table('withdrawals', function (Blueprint $table) {
                    $table->dropIndex('withdrawals_created_status_index');
                });
            } catch (\Throwable) {
                //
            }
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_admin_menu_views')) {
            return;
        }

        Schema::create('platform_admin_menu_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('menu_key', 32);
            $table->timestamp('viewed_at');
            $table->timestamps();

            $table->unique(['user_id', 'menu_key']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admin_menu_views');
    }
};

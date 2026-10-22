<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('member_lesson_ratings')) {
            return;
        }

        Schema::create('member_lesson_ratings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('member_lesson_id')->index();
            $table->string('product_id', 36)->index();
            $table->unsignedTinyInteger('rating');
            $table->timestamps();

            $table->unique(['user_id', 'member_lesson_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('member_lesson_id')->references('id')->on('member_lessons')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_lesson_ratings');
    }
};

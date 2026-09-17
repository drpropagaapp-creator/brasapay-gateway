<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('member_student_activity_logs')) {
            return;
        }

        Schema::create('member_student_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('product_id', 64)->index();
            $table->unsignedBigInteger('member_lesson_id')->nullable()->index();
            $table->string('event', 32)->index();
            $table->string('subject', 255)->nullable();
            $table->unsignedSmallInteger('file_index')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'product_id', 'created_at'], 'member_student_activity_user_product_created');
            $table->index(['user_id', 'product_id', 'event', 'created_at'], 'member_student_activity_user_product_event');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_student_activity_logs');
    }
};

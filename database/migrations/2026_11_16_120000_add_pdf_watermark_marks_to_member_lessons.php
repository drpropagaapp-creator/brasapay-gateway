<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_lessons', function (Blueprint $table) {
            $table->boolean('watermark_header_footer')->default(true)->after('watermark_enabled');
            $table->boolean('watermark_center')->default(true)->after('watermark_header_footer');
        });
    }

    public function down(): void
    {
        Schema::table('member_lessons', function (Blueprint $table) {
            $table->dropColumn(['watermark_header_footer', 'watermark_center']);
        });
    }
};

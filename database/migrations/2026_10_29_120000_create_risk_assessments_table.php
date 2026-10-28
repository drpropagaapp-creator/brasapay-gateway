<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BrasaShield (BrasaPay 4.0) — avaliações de risco em modo observação. Tabela nova.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('risk_assessments')) {
            return;
        }

        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 20); // order | withdrawal
            $table->string('subject_id', 64);
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedTinyInteger('score');
            $table->string('level', 10); // low | medium | high
            $table->string('suggested_action', 16); // allow | monitor | review
            $table->json('signals');
            $table->string('status', 16)->default('open'); // open | reviewed | dismissed
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->bigInteger('amount_cents')->default(0);
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id']);
            $table->index(['level', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_assessments');
    }
};

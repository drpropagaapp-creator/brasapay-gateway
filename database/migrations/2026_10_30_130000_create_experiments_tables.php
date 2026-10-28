<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brasa Experiments: testes A/B da landing do checkout. Só tabelas novas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('experiments')) {
            Schema::create('experiments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('product_id', 64)->index();
                $table->string('name', 120);
                $table->string('status', 16)->default('draft'); // draft | running | paused | finished
                // [{key:"A",label,weight,landing:null(=publicada)} , {key:"B",label,weight,landing:{...}}]
                $table->json('variants');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->string('winner_key', 4)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('experiment_assignments')) {
            Schema::create('experiment_assignments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('experiment_id');
                $table->string('variant_key', 4);
                $table->unsignedBigInteger('checkout_session_id');
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['experiment_id', 'checkout_session_id']);
                $table->index(['experiment_id', 'variant_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('experiment_assignments');
        Schema::dropIfExists('experiments');
    }
};

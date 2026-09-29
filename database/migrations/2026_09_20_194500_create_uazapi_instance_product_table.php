<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uazapi_instance_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uazapi_instance_id')->constrained('uazapi_instances')->cascadeOnDelete();
            $table->string('product_id', 36);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(['uazapi_instance_id', 'product_id'], 'uazapi_instance_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uazapi_instance_product');
    }
};

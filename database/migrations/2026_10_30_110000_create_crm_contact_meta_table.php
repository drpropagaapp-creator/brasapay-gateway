<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brasa CRM: só tags e notas do seller sobre um comprador. Os dados do contato continuam vindo
 * dos pedidos (fonte de verdade) — nada de segunda base de clientes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_contact_meta')) {
            return;
        }
        Schema::create('crm_contact_meta', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('email', 190);
            $table->json('tags')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_contact_meta');
    }
};

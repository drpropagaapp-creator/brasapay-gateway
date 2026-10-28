<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brasa Marketplace: biblioteca de templates. Templates de produto são pacotes do formato oficial
 * (brasapay.product-package) instalados pelo importador oficial; templates de recuperação são textos.
 * Preço existe só como estrutura (sempre 0 até haver operação comercial e fiscal definida).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_templates')) {
            Schema::create('marketplace_templates', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 20); // product_package | recovery
                $table->string('category', 30);
                $table->string('title', 120);
                $table->string('slug', 140)->unique();
                $table->string('tagline', 200)->nullable();
                $table->text('description')->nullable();
                $table->unsignedBigInteger('author_tenant_id')->nullable(); // null = conteúdo oficial
                $table->string('author_name', 120)->nullable();
                $table->boolean('is_official')->default(false);
                $table->string('status', 20)->default('pending_review'); // pending_review | published | rejected | unpublished
                $table->unsignedBigInteger('current_version_id')->nullable();
                $table->unsignedBigInteger('price_cents')->default(0);
                $table->unsignedInteger('installs_count')->default(0);
                $table->unsignedInteger('rating_count')->default(0);
                $table->unsignedInteger('rating_sum')->default(0);
                $table->string('rejection_reason', 500)->nullable();
                $table->timestamps();
                $table->index(['status', 'category']);
                $table->index('author_tenant_id');
            });
        }

        if (! Schema::hasTable('marketplace_template_versions')) {
            Schema::create('marketplace_template_versions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('template_id');
                $table->unsignedInteger('version');
                $table->string('package_path', 255)->nullable();
                $table->string('package_sha256', 64)->nullable();
                $table->json('content')->nullable();
                $table->json('summary')->nullable();
                $table->string('changelog', 1000)->nullable();
                $table->string('status', 20)->default('pending_review'); // pending_review | published | rejected
                $table->string('rejection_reason', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->unique(['template_id', 'version']);
            });
        }

        if (! Schema::hasTable('marketplace_installs')) {
            Schema::create('marketplace_installs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('template_id');
                $table->unsignedBigInteger('version_id');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('product_import_id')->nullable();
                $table->string('status', 20)->default('started'); // started | completed | failed
                $table->timestamps();
                $table->index(['tenant_id', 'template_id']);
            });
        }

        if (! Schema::hasTable('marketplace_reviews')) {
            Schema::create('marketplace_reviews', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('template_id');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedTinyInteger('rating');
                $table->string('comment', 1000)->nullable();
                $table->string('status', 20)->default('pending'); // pending | approved | rejected
                $table->unsignedBigInteger('moderated_by')->nullable();
                $table->timestamps();
                $table->unique(['template_id', 'tenant_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_reviews');
        Schema::dropIfExists('marketplace_installs');
        Schema::dropIfExists('marketplace_template_versions');
        Schema::dropIfExists('marketplace_templates');
    }
};

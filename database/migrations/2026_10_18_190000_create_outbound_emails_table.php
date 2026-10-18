<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_emails', function (Blueprint $table) {
            $table->id();
            $table->string('token', 40)->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('recipient_email');
            $table->string('recipient_name')->nullable();
            $table->string('recipient_role', 32)->nullable();
            $table->string('subject')->nullable();
            $table->string('mail_label', 80)->nullable();
            $table->string('mailable', 160)->nullable();
            $table->string('mailer', 32)->nullable();
            $table->string('status', 20)->default('sending')->index();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->timestamps();

            $table->index('recipient_email');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_emails');
    }
};

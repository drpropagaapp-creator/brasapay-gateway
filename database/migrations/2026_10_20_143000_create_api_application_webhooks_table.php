<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_application_webhooks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('api_application_id')->index();
            $table->string('name', 120)->nullable();
            $table->string('url', 512);
            $table->string('secret', 64)->nullable();
            $table->json('events')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('api_application_id')
                ->references('id')
                ->on('api_applications')
                ->onDelete('cascade');
        });

        Schema::create('api_application_webhook_product', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('api_application_webhook_id');
            $table->string('product_id', 36);
            $table->timestamps();

            $table->foreign('api_application_webhook_id', 'api_wh_product_webhook_fk')
                ->references('id')
                ->on('api_application_webhooks')
                ->onDelete('cascade');
            $table->foreign('product_id', 'api_wh_product_product_fk')
                ->references('id')
                ->on('products')
                ->onDelete('cascade');

            $table->unique(
                ['api_application_webhook_id', 'product_id'],
                'api_wh_product_unique'
            );
        });

        Schema::table('api_webhook_deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('api_application_webhook_id')->nullable()->after('api_application_id')->index();
        });

        $this->copyLegacyWebhooks();
    }

    public function down(): void
    {
        Schema::table('api_webhook_deliveries', function (Blueprint $table) {
            $table->dropColumn('api_application_webhook_id');
        });
        Schema::dropIfExists('api_application_webhook_product');
        Schema::dropIfExists('api_application_webhooks');
    }

    private function copyLegacyWebhooks(): void
    {
        $apps = DB::table('api_applications')
            ->whereNotNull('webhook_url')
            ->where('webhook_url', '!=', '')
            ->get(['id', 'webhook_url', 'webhook_secret', 'webhook_events', 'webhook_enabled']);

        $now = now();
        foreach ($apps as $app) {
            $events = $app->webhook_events;
            if (is_string($events) && $events !== '') {
                $decoded = json_decode($events, true);
                $events = is_array($decoded) ? $decoded : null;
            }
            if (! is_array($events)) {
                $events = null;
            }

            DB::table('api_application_webhooks')->insert([
                'api_application_id' => $app->id,
                'name' => 'Webhook 1',
                'url' => $app->webhook_url,
                'secret' => $app->webhook_secret,
                'events' => $events === null ? null : json_encode(array_values($events)),
                'is_active' => (bool) ($app->webhook_enabled ?? true),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};

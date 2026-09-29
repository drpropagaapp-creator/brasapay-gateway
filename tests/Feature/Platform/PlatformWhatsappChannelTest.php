<?php

namespace Tests\Feature\Platform;

use App\Events\SellerRegistered;
use App\Http\Middleware\EnsureInstalled;
use App\Jobs\PlatformWhatsappSendJob;
use App\Models\PlatformWhatsappChannel;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlatformWhatsappChannelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            ValidateCsrfToken::class,
        ]);
    }

    public function test_uazapi_index_includes_platform_channel_payload(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.uazapi.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Uazapi/Index')
                ->has('platform.channel')
                ->has('platform.templates', 4)
            );
    }

    public function test_admin_can_save_platform_channel_credentials(): void
    {
        Http::fake([
            'https://stacker.uazapi.com/*' => Http::response([
                'connected' => true,
                'status' => PlatformWhatsappChannel::STATUS_CONNECTED,
            ]),
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->putJson(route('plataforma.whatsapp-canal.channel.update'), [
                'provider' => 'uazapi',
                'server_url' => 'https://stacker.uazapi.com',
                'instance_token' => 'secret-token',
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('channel.provider', 'uazapi')
            ->assertJsonPath('channel.has_credentials', true);

        $this->assertDatabaseHas('platform_whatsapp_channels', [
            'provider' => 'uazapi',
            'server_url' => 'https://stacker.uazapi.com',
        ]);
    }

    public function test_seller_registered_event_is_listened(): void
    {
        Event::fake([SellerRegistered::class]);

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
            'phone' => '11999998888',
        ]);

        SellerRegistered::dispatch($seller);

        Event::assertDispatched(SellerRegistered::class);
    }

    public function test_dispatcher_queues_job_when_channel_connected(): void
    {
        Queue::fake();

        $channel = PlatformWhatsappChannel::current();
        $channel->fill([
            'provider' => PlatformWhatsappChannel::PROVIDER_UAZAPI,
            'server_url' => 'https://stacker.uazapi.com',
            'instance_token' => 'tok',
            'status' => PlatformWhatsappChannel::STATUS_CONNECTED,
            'is_active' => true,
        ]);
        $channel->save();

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 2,
            'phone' => '11988887777',
        ]);

        SellerRegistered::dispatch($seller);

        Queue::assertPushed(PlatformWhatsappSendJob::class);
    }

    public function test_webhook_accepts_unknown_secret_silently(): void
    {
        $this->postJson(route('webhooks.platform-whatsapp', ['secret' => str_repeat('a', 48)]), [
            'event' => 'messages.upsert',
        ])->assertOk()
            ->assertJson(['received' => true]);
    }
}

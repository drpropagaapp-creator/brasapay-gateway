<?php

namespace Tests\Feature\Platform;

use App\Events\KycApproved;
use App\Events\KycRejected;
use App\Events\SellerRegistered;
use App\Http\Middleware\EnsureInstalled;
use App\Jobs\PlatformWhatsappSendJob;
use App\Models\PlatformWhatsappChannel;
use App\Models\PlatformWhatsappDispatch;
use App\Models\PlatformWhatsappTemplate;
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

        $this->connectPlatformChannel();

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 2,
            'phone' => '11988887777',
        ]);

        SellerRegistered::dispatch($seller);

        Queue::assertPushed(PlatformWhatsappSendJob::class);
    }

    public function test_does_not_queue_duplicate_seller_registered_or_kyc_approved(): void
    {
        Queue::fake();
        $this->connectPlatformChannel();
        PlatformWhatsappTemplate::ensureDefaults();

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 3,
            'phone' => '11977776666',
        ]);

        SellerRegistered::dispatch($seller);
        SellerRegistered::dispatch($seller);
        KycApproved::dispatch($seller);
        KycApproved::dispatch($seller);

        Queue::assertPushed(PlatformWhatsappSendJob::class, 2);
        $this->assertSame(1, PlatformWhatsappDispatch::query()
            ->where('user_id', $seller->id)
            ->where('event_type', PlatformWhatsappTemplate::EVENT_SELLER_REGISTERED)
            ->count());
        $this->assertSame(1, PlatformWhatsappDispatch::query()
            ->where('user_id', $seller->id)
            ->where('event_type', PlatformWhatsappTemplate::EVENT_KYC_APPROVED)
            ->count());
    }

    public function test_kyc_rejected_blocks_only_recent_duplicate(): void
    {
        Queue::fake();
        $this->connectPlatformChannel();
        PlatformWhatsappTemplate::ensureDefaults();

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 4,
            'phone' => '11966665555',
        ]);

        KycRejected::dispatch($seller, 'docs incompletos');
        KycRejected::dispatch($seller, 'docs incompletos');

        $this->assertSame(1, PlatformWhatsappDispatch::query()
            ->where('user_id', $seller->id)
            ->where('event_type', PlatformWhatsappTemplate::EVENT_KYC_REJECTED)
            ->count());

        PlatformWhatsappDispatch::query()
            ->where('user_id', $seller->id)
            ->where('event_type', PlatformWhatsappTemplate::EVENT_KYC_REJECTED)
            ->update([
                'status' => PlatformWhatsappDispatch::STATUS_SENT,
                'sent_at' => now()->subMinutes(10),
                'created_at' => now()->subMinutes(10),
            ]);

        KycRejected::dispatch($seller, 'nova rejeicao');

        $this->assertSame(2, PlatformWhatsappDispatch::query()
            ->where('user_id', $seller->id)
            ->where('event_type', PlatformWhatsappTemplate::EVENT_KYC_REJECTED)
            ->count());
    }

    public function test_webhook_accepts_unknown_secret_silently(): void
    {
        $this->postJson(route('webhooks.platform-whatsapp', ['secret' => str_repeat('a', 48)]), [
            'event' => 'messages.upsert',
        ])->assertOk()
            ->assertJson(['received' => true]);
    }

    private function connectPlatformChannel(): void
    {
        $channel = PlatformWhatsappChannel::current();
        $channel->fill([
            'provider' => PlatformWhatsappChannel::PROVIDER_UAZAPI,
            'server_url' => 'https://stacker.uazapi.com',
            'instance_token' => 'tok',
            'status' => PlatformWhatsappChannel::STATUS_CONNECTED,
            'is_active' => true,
        ]);
        $channel->save();
    }
}

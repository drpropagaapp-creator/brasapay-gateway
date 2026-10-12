<?php

namespace Tests\Feature;

use App\Models\PanelPushSubscription;
use App\Services\PanelPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\UsesTestVapidKeys;
use Tests\TestCase;

class PanelPushTestEndpointTest extends TestCase
{
    use RefreshDatabase;
    use UsesTestVapidKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPushFeatureTests();
    }

    public function test_push_test_requires_subscription(): void
    {
        $this->configureTestVapidPush();
        $user = $this->createSellerUser();

        $this->actingAs($user)
            ->postJson('/painel/push-test')
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_push_test_sends_to_current_user_subscriptions(): void
    {
        $keys = $this->configureTestVapidPush();
        $user = $this->createSellerUser();

        PanelPushSubscription::create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'provider' => PanelPushSubscription::PROVIDER_VAPID,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
            'keys' => ['auth' => 'dGVzdA', 'p256dh' => 'dGVzdA'],
            'vapid_public_key' => $keys['publicKey'],
        ]);

        $mock = Mockery::mock(PanelPushService::class);
        $mock->shouldReceive('sendToSubscriptions')
            ->once()
            ->andReturn([
                'sent' => 1,
                'failed' => 0,
                'invalid' => 0,
                'expired' => 0,
                'total' => 1,
            ]);
        $this->app->instance(PanelPushService::class, $mock);

        $this->actingAs($user)
            ->postJson('/painel/push-test')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }
}

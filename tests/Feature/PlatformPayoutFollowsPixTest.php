<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\Setting;
use App\Services\Payout\PlatformPayoutGateway;
use Tests\TestCase;

class PlatformPayoutFollowsPixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        GatewayCredential::query()->delete();
        $this->connect('cajupay');
        $this->connect('assetpay', ['secret_key' => 'sk', 'public_key' => 'pk']);
        Setting::set('platform_payout_gateway', null, null);
    }

    public function test_auto_follows_first_pix_acquirer_with_cashout(): void
    {
        $this->pixOrder(['assetpay', 'cajupay', 'stripe']);
        $this->assertSame('assetpay', PlatformPayoutGateway::activeSlug());

        $this->pixOrder(['cajupay', 'assetpay']);
        $this->assertSame('cajupay', PlatformPayoutGateway::activeSlug());
    }

    public function test_pix_acquirer_without_cashout_is_skipped(): void
    {
        $this->pixOrder(['stripe', 'assetpay']);
        $this->assertSame('assetpay', PlatformPayoutGateway::activeSlug());
    }

    public function test_disconnected_pix_acquirer_falls_back_to_fixed_order(): void
    {
        $this->pixOrder(['assetpay']);
        GatewayCredential::query()->where('gateway_slug', 'assetpay')->update(['is_connected' => false]);
        $this->assertSame('cajupay', PlatformPayoutGateway::activeSlug());

        $this->pixOrder([]);
        $this->assertSame('cajupay', PlatformPayoutGateway::activeSlug());
    }

    public function test_forced_preference_still_wins(): void
    {
        $this->pixOrder(['assetpay', 'cajupay']);
        Setting::set('platform_payout_gateway', 'cajupay', null);
        $this->assertSame('cajupay', PlatformPayoutGateway::activeSlug());
    }

    /**
     * @param  list<string>  $pix
     */
    private function pixOrder(array $pix): void
    {
        Setting::set('gateway_order', json_encode(['pix' => $pix, 'card' => ['stripe']]), null);
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function connect(string $slug, array $credentials = []): void
    {
        $cred = GatewayCredential::query()->firstOrNew(['tenant_id' => null, 'gateway_slug' => $slug]);
        $cred->is_connected = true;
        $cred->is_enabled = true;
        $cred->setEncryptedCredentials($credentials);
        $cred->save();
    }
}

<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureSellerPanel;
use App\Models\ApiApplication;
use App\Models\ApiKey;
use App\Models\User;
use App\Support\ApiScopes;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

/**
 * Revogar a chave original (legacy) no painel não pode desligar a integração inteira:
 * antes, o botão gravava api_applications.is_active = false e todas as chaves passavam a
 * receber 401 "Invalid API key".
 */
class ApiLegacyKeyRevokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureSellerPanel::class,
            ValidateCsrfToken::class,
        ]);
    }

    /**
     * @return array{seller: User, app: ApiApplication, legacy_public: string, legacy_secret: string, key_public: string, key_secret: string}
     */
    private function context(): array
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ]);
        $seller->update(['tenant_id' => $seller->id]);
        $seller = $seller->fresh();

        $legacyPublic = ApiApplication::generatePublicKey();
        $legacySecret = ApiApplication::generateSecretKey();
        $app = ApiApplication::create([
            'tenant_id' => $seller->tenant_id,
            'name' => 'Integração',
            'slug' => ApiApplication::generateUniqueSlug((int) $seller->tenant_id, 'Integração'),
            'api_key_hash' => ApiApplication::hashApiKey('legacy-unused'),
            'public_key' => $legacyPublic,
            'secret_key_hash' => ApiApplication::hashSecretKey($legacySecret),
            'payment_gateways' => ApiApplication::defaultPaymentGateways(),
            'allowed_ips' => [],
            'is_active' => true,
            'is_legacy' => true,
            'scopes' => ApiScopes::legacyDefaults(),
        ]);

        $keyPublic = ApiKey::generatePublicKey();
        $keySecret = ApiKey::generateSecretKey();
        ApiKey::create([
            'tenant_id' => $seller->tenant_id,
            'api_application_id' => $app->id,
            'name' => 'Sistema do seller',
            'public_key' => $keyPublic,
            'secret_key_hash' => ApiKey::hashSecretKey($keySecret),
            'scopes' => [ApiScopes::WILDCARD],
            'allowed_ips' => [],
            'strict_idempotency' => false,
            'async_payments' => false,
            'rate_limit_tier' => 'standard',
            'is_active' => true,
        ]);

        return [
            'seller' => $seller,
            'app' => $app,
            'legacy_public' => $legacyPublic,
            'legacy_secret' => $legacySecret,
            'key_public' => $keyPublic,
            'key_secret' => $keySecret,
        ];
    }

    private function callBalance(string $public, string $secret)
    {
        return $this->withHeaders([
            'X-Public-Key' => $public,
            'X-Secret-Key' => $secret,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/balance');
    }

    public function test_revoking_legacy_key_keeps_application_and_other_keys_working(): void
    {
        $ctx = $this->context();

        $this->actingAs($ctx['seller'])
            ->put("/aplicacoes-api/{$ctx['app']->id}", ['legacy_key_active' => false])
            ->assertRedirect();

        $ctx['app']->refresh();
        $this->assertTrue($ctx['app']->is_active);
        $this->assertFalse($ctx['app']->legacyKeyActive());

        $this->assertNotSame(401, $this->callBalance($ctx['key_public'], $ctx['key_secret'])->status());
        $this->assertNotSame(403, $this->callBalance($ctx['key_public'], $ctx['key_secret'])->status());
        $this->callBalance($ctx['legacy_public'], $ctx['legacy_secret'])->assertStatus(401);

        $this->actingAs($ctx['seller'])
            ->put("/aplicacoes-api/{$ctx['app']->id}", ['legacy_key_active' => true])
            ->assertRedirect();

        $this->assertNotSame(401, $this->callBalance($ctx['legacy_public'], $ctx['legacy_secret'])->status());
    }

    public function test_valid_key_on_disabled_application_returns_explicit_403(): void
    {
        $ctx = $this->context();
        $ctx['app']->forceFill(['is_active' => false])->save();

        $this->callBalance($ctx['key_public'], $ctx['key_secret'])
            ->assertStatus(403)
            ->assertJsonPath('message', fn ($m) => str_starts_with((string) $m, 'API application is disabled.'));
    }
}

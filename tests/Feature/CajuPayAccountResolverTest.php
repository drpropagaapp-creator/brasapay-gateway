<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\CajuPayAccount;
use App\Models\GatewayCredential;
use App\Models\Setting;
use App\Models\User;
use App\Services\CajuPay\CajuPayAccountResolver;
use App\Services\CajuPay\CajuPayWebhookBootstrapService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CajuPayAccountResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            ValidateCsrfToken::class,
        ]);
    }

    private function seedAccounts(): array
    {
        $default = CajuPayAccount::create([
            'name' => 'Conta padrão',
            'is_default' => true,
            'is_connected' => true,
            'is_enabled' => true,
        ]);
        $default->setEncryptedCredentials([
            'public_key' => 'gpk_default',
            'secret_key' => 'gsk_default',
        ]);
        $default->save();

        $secondary = CajuPayAccount::create([
            'name' => 'Conta B',
            'is_default' => false,
            'is_connected' => true,
            'is_enabled' => true,
        ]);
        $secondary->setEncryptedCredentials([
            'public_key' => 'gpk_b',
            'secret_key' => 'gsk_b',
        ]);
        $secondary->save();

        return ['default' => $default, 'secondary' => $secondary];
    }

    public function test_resolver_uses_default_when_merchant_has_no_assignment(): void
    {
        $accounts = $this->seedAccounts();
        $merchant = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();

        $resolved = app(CajuPayAccountResolver::class)->resolveForTenant((int) $merchant->id);

        $this->assertNotNull($resolved);
        $this->assertSame($accounts['default']->id, $resolved->id);
        $this->assertSame('gpk_default', $resolved->getDecryptedCredentials()['public_key']);
    }

    public function test_resolver_uses_assigned_account_for_merchant(): void
    {
        $accounts = $this->seedAccounts();
        $merchant = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'cajupay_account_id' => $accounts['secondary']->id,
        ]);
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();

        $resolved = app(CajuPayAccountResolver::class)->resolveForTenant((int) $merchant->id);

        $this->assertNotNull($resolved);
        $this->assertSame($accounts['secondary']->id, $resolved->id);
        $this->assertSame('gpk_b', $resolved->getDecryptedCredentials()['public_key']);
    }

    public function test_admin_can_assign_cajupay_account_to_merchant(): void
    {
        $accounts = $this->seedAccounts();
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
        $merchant = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $merchant->forceFill(['tenant_id' => $merchant->id, 'kyc_status' => User::KYC_APPROVED])->save();

        $this->actingAs($admin)->put(route('plataforma.usuarios.update', $merchant), [
            'name' => $merchant->name,
            'email' => $merchant->email,
            'cajupay_account_id' => $accounts['secondary']->id,
        ])->assertRedirect();

        $merchant->refresh();
        $this->assertSame($accounts['secondary']->id, $merchant->cajupay_account_id);
    }

    public function test_payment_uses_method_account_when_merchant_has_no_assignment(): void
    {
        $accounts = $this->seedAccounts();
        $merchant = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();

        Setting::set('cajupay_method_accounts', [
            'pix' => $accounts['secondary']->id,
            'card' => $accounts['default']->id,
            'boleto' => null,
            'pix_auto' => null,
        ], null);

        $resolver = app(CajuPayAccountResolver::class);

        $this->assertSame($accounts['secondary']->id, $resolver->resolveForPayment((int) $merchant->id, 'pix')?->id);
        $this->assertSame($accounts['default']->id, $resolver->resolveForPayment((int) $merchant->id, 'card')?->id);
        $this->assertSame($accounts['default']->id, $resolver->resolveForPayment((int) $merchant->id, 'apple_pay')?->id);
    }

    public function test_merchant_assignment_wins_over_method_account(): void
    {
        $accounts = $this->seedAccounts();
        $merchant = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'cajupay_account_id' => $accounts['default']->id,
        ]);
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();

        Setting::set('cajupay_method_accounts', [
            'pix' => $accounts['secondary']->id,
            'card' => $accounts['secondary']->id,
        ], null);

        $resolved = app(CajuPayAccountResolver::class)->resolveForPayment((int) $merchant->id, 'pix');

        $this->assertSame($accounts['default']->id, $resolved?->id);
    }

    public function test_admin_can_save_different_cajupay_accounts_per_method(): void
    {
        $accounts = $this->seedAccounts();
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)->putJson(route('plataforma.financeiro.gateways.order'), [
            'gateway_order' => [
                'pix' => ['cajupay'],
                'card' => ['cajupay'],
                'boleto' => [],
                'pix_auto' => [],
            ],
            'cajupay_method_accounts' => [
                'pix' => $accounts['secondary']->id,
                'card' => $accounts['default']->id,
                'boleto' => null,
                'pix_auto' => 999999,
            ],
        ])->assertOk()->assertJsonPath('success', true);

        $map = app(CajuPayAccountResolver::class)->methodAccountMap(null);
        $this->assertSame($accounts['secondary']->id, $map['pix']);
        $this->assertSame($accounts['default']->id, $map['card']);
        $this->assertNull($map['pix_auto']);
    }

    public function test_resolver_falls_back_to_legacy_credential_when_accounts_are_empty(): void
    {
        CajuPayAccount::create([
            'name' => 'Conta padrão',
            'is_default' => true,
            'is_connected' => false,
            'is_enabled' => true,
        ]);

        $legacy = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
        ]);
        $legacy->is_connected = true;
        $legacy->is_enabled = true;
        $legacy->setEncryptedCredentials([
            'public_key' => 'gpk_legacy',
            'secret_key' => 'gsk_legacy',
        ]);
        $legacy->save();

        $resolved = app(CajuPayAccountResolver::class)->resolveForTenant(null);

        $this->assertNotNull($resolved);
        $this->assertSame('gpk_legacy', $resolved->getDecryptedCredentials()['public_key']);
    }
}

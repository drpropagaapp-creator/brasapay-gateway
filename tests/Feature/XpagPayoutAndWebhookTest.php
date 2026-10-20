<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Jobs\ReconcileXpagWithdrawalJob;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Payout\PayoutDestinationValidator;
use App\Services\WithdrawalAutoPayoutService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class XpagPayoutAndWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
    }

    protected function tearDown(): void
    {
        Setting::set('platform_payout_gateway', null, null);
        GatewayCredential::query()->where('gateway_slug', 'xpag')->delete();
        parent::tearDown();
    }

    public function test_auto_xpag_persists_pending_and_dispatches_reconcile_job(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $this->seedXpagCredential();
        Queue::fake();

        Http::fake([
            'https://api.xpag.global/cashout' => Http::response([
                'ok' => true,
                'e2e' => 'E-xpag-99',
                'status' => 'pending',
            ], 200),
        ]);

        $w = $this->makeWithdrawal(100, 0, 100, [
            'payout_pix_key' => '11119854997',
            'payout_pix_key_type' => 'cpf',
        ]);

        $auto = app(WithdrawalAutoPayoutService::class)->attemptAutoPayout($w->fresh());

        $this->assertTrue($auto['ok'] ?? false);
        $this->assertTrue($auto['pending'] ?? false);

        $fresh = $w->fresh();
        $this->assertSame('xpag', $fresh->payout_provider);
        $this->assertSame('E-xpag-99', $fresh->payout_external_id);
        Queue::assertPushed(ReconcileXpagWithdrawalJob::class);
    }

    public function test_webhook_cashin_confirmed_completes_order(): void
    {
        Http::fake([
            'api.xpag.global/consult-transaction*' => Http::response([
                'ok' => true,
                'type' => 'cashin',
                'status' => 'confirmed',
                'transaction_id' => 'pr_dep_1',
            ], 200),
        ]);

        $this->seedXpagCredential();

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 13.45,
            'email' => 'buyer@test.com',
            'gateway' => 'xpag',
            'gateway_id' => 'pr_dep_1',
            'payment_method' => 'pix',
        ]);

        $response = $this->postJson('/webhooks/gateways/xpag', [
            'type' => 'cashin',
            'origin' => 'dynamic',
            'status' => 'confirmed',
            'amount' => 13.45,
            'currency' => 'BRL',
            'transaction_id' => 'pr_dep_1',
            'external_id' => (string) $order->id,
            'e2e' => 'E123',
            'method' => 'PIX',
            'provider' => 'XPag',
        ]);

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_webhook_cashout_completed_marks_withdrawal_paid(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $this->seedXpagCredential();
        $w = $this->makeWithdrawal(50, 0, 50, [
            'payout_pix_key' => 'destino@pix.com',
            'payout_pix_key_type' => 'email',
        ]);
        $w->update([
            'status' => 'pending',
            'payout_provider' => 'xpag',
            'payout_external_id' => 'E-xpag-paid',
        ]);

        $response = $this->postJson('/webhooks/gateways/xpag', [
            'type' => 'cashout',
            'status' => 'completed',
            'amount' => 50,
            'currency' => 'BRL',
            'e2e' => 'E-xpag-paid',
            'external_id' => (string) $w->id,
            'method' => 'PIX',
            'provider' => 'XPag',
        ]);

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertSame('paid', $w->fresh()->status);
    }

    public function test_connection_probe_uses_balance(): void
    {
        $this->seedXpagCredential();
        Http::fake([
            'api.xpag.global/balance' => Http::response([
                'ok' => true,
                'balances' => [
                    'BRL' => ['available' => 10, 'blocked' => 0],
                ],
            ], 200),
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $response = $this->actingAs($admin)->postJson('/plataforma/financeiro/gateways/xpag/test', [
            'client_id' => 'xpagsandbox_00000000',
            'client_secret' => '202620262026202620262026',
        ]);

        $response->assertOk()->assertJsonPath('success', true);
    }

    public function test_webhook_cashin_finds_order_by_external_id_before_gateway_is_set(): void
    {
        Http::fake([
            'api.xpag.global/consult-transaction*' => Http::response([
                'ok' => true,
                'type' => 'cashin',
                'status' => 'confirmed',
                'transaction_id' => 'pr_dep_race',
            ], 200),
        ]);

        $this->seedXpagCredential();

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 20,
            'email' => 'buyer@test.com',
            'payment_method' => 'pix',
            'metadata' => ['xpag_external_id' => 'PEDIDO-XPAG-42'],
        ]);

        $response = $this->postJson('/webhooks/gateways/xpag', [
            'type' => 'cashin',
            'origin' => 'dynamic',
            'status' => 'confirmed',
            'amount' => 20,
            'currency' => 'BRL',
            'transaction_id' => 'pr_dep_race',
            'external_id' => 'PEDIDO-XPAG-42',
            'e2e' => 'E-race',
            'method' => 'PIX',
            'provider' => 'XPag',
        ]);

        $response->assertOk()->assertJson(['received' => true]);
        $fresh = $order->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertSame('xpag', $fresh->gateway);
        $this->assertSame('pr_dep_race', $fresh->gateway_id);
    }

    public function test_webhook_ignores_non_brl_and_static_and_med(): void
    {
        $this->seedXpagCredential();

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 'buyer@test.com',
            'gateway' => 'xpag',
            'gateway_id' => 'pr_ignore',
            'payment_method' => 'pix',
        ]);

        $this->postJson('/webhooks/gateways/xpag', [
            'type' => 'cashin',
            'status' => 'confirmed',
            'currency' => 'MXN',
            'transaction_id' => 'pr_ignore',
            'method' => 'SPEI',
        ])->assertOk()->assertJson(['received' => true, 'ignored' => true]);

        $this->postJson('/webhooks/gateways/xpag', [
            'type' => 'cashin',
            'origin' => 'static',
            'status' => 'confirmed',
            'currency' => 'BRL',
            'transaction_id' => 'pr_ignore',
            'method' => 'PIX',
        ])->assertOk()->assertJson(['received' => true, 'ignored' => true]);

        $this->postJson('/webhooks/gateways/xpag', [
            'type' => 'cashin',
            'status' => 'med',
            'currency' => 'BRL',
            'transaction_id' => 'pr_ignore',
            'method' => 'PIX',
        ])->assertOk()->assertJson(['received' => true, 'ignored' => true]);

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_seller_pix_key_is_enough_for_xpag_payout_destination(): void
    {
        $this->seedXpagCredential();
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'payout_settings' => [
                'payout_pix_key' => 'destino@pix.com',
                'payout_pix_key_type' => 'email',
            ],
        ])->save();

        $ready = PayoutDestinationValidator::assertReadyForWithdrawal($seller, 'xpag');
        $this->assertTrue($ready['ok'] ?? false);
    }

    public function test_withdrawals_index_lists_connected_xpag(): void
    {
        $this->seedXpagCredential();

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.saques.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Withdrawals/Index')
                ->has('cashout_acquirers')
                ->where('cashout_acquirers', fn ($list) => collect($list)->contains(
                    fn ($acquirer) => ($acquirer['slug'] ?? null) === 'xpag'
                ))
            );
    }

    public function test_admin_approve_sends_payout_to_selected_xpag(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $this->seedXpagCredential();
        Setting::set('platform_payout_gateway', 'cajupay', null);
        $cajuAccount = null;
        if (Schema::hasTable('cajupay_accounts')) {
            $cajuAccount = \App\Models\CajuPayAccount::query()->create([
                'name' => 'Conta payout override',
                'is_default' => true,
                'is_connected' => true,
                'is_enabled' => true,
            ]);
        }
        \App\Services\Withdrawal\WithdrawalPolicyService::setManualApprovalPin('9999');

        Queue::fake();
        Http::fake([
            'https://api.xpag.global/cashout' => Http::response([
                'ok' => true,
                'e2e' => 'E-xpag-chosen',
                'status' => 'pending',
            ], 200),
        ]);

        $w = $this->makeWithdrawal(80, 0, 80, [
            'payout_pix_key' => '11119854997',
            'payout_pix_key_type' => 'cpf',
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $response = $this->actingAs($admin)->post(route('plataforma.financeiro.saques.approve', $w), [
            'payout_manual' => false,
            'manual_approval_pin' => '9999',
            'payout_acquirer' => 'xpag',
        ]);

        $response->assertRedirect(route('plataforma.saques.index'));
        $response->assertSessionHas('success');

        $fresh = $w->fresh();
        $this->assertSame('xpag', $fresh->payout_provider);
        $this->assertSame('E-xpag-chosen', $fresh->payout_external_id);
        Queue::assertPushed(ReconcileXpagWithdrawalJob::class);
        $cajuAccount?->delete();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function seedXpagCredential(array $extra = []): void
    {
        Setting::set('platform_payout_gateway', 'xpag', null);
        GatewayCredential::query()->whereIn('gateway_slug', [
            'cajupay', 'spacepag', 'woovi', 'bspay', 'versell', 'xflow', 'okto', 'onlyup',
        ])->delete();

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'xpag',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials(array_merge([
            'client_id' => 'xpagsandbox_00000000',
            'client_secret' => '202620262026202620262026',
        ], $extra));
        $cred->save();
    }

    /**
     * @param  array<string, mixed>  $payoutSettings
     */
    private function makeWithdrawal(float $amount, float $fee, float $net, array $payoutSettings): Withdrawal
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'payout_settings' => $payoutSettings,
        ])->save();

        return Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => $amount,
            'fee_amount' => $fee,
            'net_amount' => $net,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Jobs\ReconcileXflowWithdrawalJob;
use App\Models\GatewayCredential;
use App\Models\Setting;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\WithdrawalAutoPayoutService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class XflowPayoutFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
    }

    protected function tearDown(): void
    {
        Setting::set('platform_payout_gateway', null, null);
        GatewayCredential::query()->where('gateway_slug', 'xflow')->delete();
        parent::tearDown();
    }

    public function test_auto_xflow_persists_pending_and_dispatches_reconcile_job(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $this->seedXflowCredential();

        Http::fake([
            'https://app.xflowpayments.com/api/v1/transfers' => Http::response([
                'data' => [
                    'id' => 'tr_xflow_99',
                    'status' => 'processing',
                    'amount' => 10000,
                    'pending_approval' => false,
                ],
            ], 201),
        ]);

        $w = $this->makeWithdrawal(100, 0, 100, [
            'payout_pix_key' => 'destino@pix.com',
            'payout_pix_key_type' => 'email',
        ]);

        $auto = app(WithdrawalAutoPayoutService::class)->attemptAutoPayout($w->fresh());

        $this->assertTrue($auto['ok'] ?? false);
        $this->assertTrue($auto['pending'] ?? false);

        $fresh = $w->fresh();
        $this->assertSame('xflow', $fresh->payout_provider);
        $this->assertSame('tr_xflow_99', $fresh->payout_external_id);
        $this->assertSame('pending', $fresh->status);

        Queue::assertPushed(ReconcileXflowWithdrawalJob::class);
    }

    public function test_auto_xflow_transfer_value_includes_admin_fee_payout_brl(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $this->seedXflowCredential([
            'xflow_admin_fee_payout_brl' => '2',
        ]);

        Http::fake([
            'https://app.xflowpayments.com/api/v1/transfers' => Http::response([
                'data' => [
                    'id' => 'tr_xflow_fee',
                    'status' => 'processing',
                    'amount' => 1800,
                ],
            ], 201),
        ]);

        $w = $this->makeWithdrawal(20, 4, 16, [
            'payout_pix_key' => 'destino@pix.com',
            'payout_pix_key_type' => 'email',
        ]);

        $auto = app(WithdrawalAutoPayoutService::class)->attemptAutoPayout($w->fresh());

        $this->assertTrue($auto['ok'] ?? false);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/v1/transfers') || $request->method() !== 'POST') {
                return false;
            }
            $data = $request->data();
            if (! is_array($data)) {
                return false;
            }

            return ($data['amount'] ?? null) === 1800
                && ($data['pix_key_type'] ?? null) === 'EMAIL'
                && $request->hasHeader('Idempotency-Key');
        });
    }

    public function test_webhook_withdrawal_completed_marks_paid(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $secret = 'xflow-payout-whsec';
        $this->seedXflowCredential(['webhook_secret' => $secret]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $w = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 100,
            'fee_amount' => 0,
            'net_amount' => 100,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
            'payout_provider' => 'xflow',
            'payout_external_id' => 'tr_wh_paid',
        ]);

        $response = $this->postSignedGatewayWebhook('/webhooks/gateways/xflow/payout', [
            'event' => 'withdrawal.completed',
            'data' => [
                'id' => 'tr_wh_paid',
                'livemode' => true,
                'status' => 'completed',
            ],
        ], $secret);

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertSame('paid', $w->fresh()->status);
    }

    public function test_webhook_withdrawal_failed_marks_failed(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $secret = 'xflow-payout-whsec-fail';
        $this->seedXflowCredential(['webhook_secret' => $secret]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $w = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 50,
            'fee_amount' => 0,
            'net_amount' => 50,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
            'payout_provider' => 'xflow',
            'payout_external_id' => 'tr_wh_fail',
        ]);

        $response = $this->postSignedGatewayWebhook('/webhooks/gateways/xflow', [
            'event' => 'withdrawal.failed',
            'data' => [
                'id' => 'tr_wh_fail',
                'livemode' => true,
                'status' => 'failed',
            ],
        ], $secret);

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertSame('failed', $w->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function seedXflowCredential(array $extra = []): void
    {
        Setting::set('platform_payout_gateway', 'xflow', null);
        GatewayCredential::query()->whereIn('gateway_slug', [
            'cajupay', 'spacepag', 'woovi', 'bspay', 'versell', 'onlyup',
        ])->delete();

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'xflow',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials(array_merge([
            'public_key' => 'pk_live_test',
            'secret_key' => 'sk_live_test',
            'webhook_secret' => 'xflow-whsec-test',
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

<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Platform\PayoutFundingNeed;
use App\Services\Platform\PlatformCashReconciliation;
use Tests\TestCase;

/**
 * A AssetPay tira líquido + taxa do saldo assim que aceita o saque (status PENDING).
 * Um saque já aceito não pode gerar "falta" nem contar como dívida do caixa.
 */
class AssetPayFundingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        GatewayCredential::query()->where('gateway_slug', 'assetpay')->delete();
        $cred = GatewayCredential::query()->firstOrNew(['tenant_id' => null, 'gateway_slug' => 'assetpay']);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'pk', 'secret_key' => 'sk',
            'cashout_public_key' => 'pko', 'cashout_secret_key' => 'sko',
            'assetpay_admin_fee_payout_brl' => '1,00',
        ]);
        $cred->save();
    }

    public function test_withdrawal_already_accepted_by_assetpay_needs_no_more_balance(): void
    {
        $seller = $this->seller();
        $sent = $this->withdrawal($seller, 4.40, 'd6f85d97');
        $next = $this->withdrawal($seller, 2.00, null);

        // Saldo depois de a AssetPay debitar R$ 5,40 do saque já enviado.
        $r = PayoutFundingNeed::compute([$this->wallet(3.85)]);

        $this->assertSame('sent', $r['items'][$sent->id]['status']);
        $this->assertSame(0.0, $r['items'][$sent->id]['missing']);
        // O próximo (2,00 + 1,00 de taxa = 3,00) cabe nos 3,85 que sobraram.
        $this->assertSame('ok', $r['items'][$next->id]['status']);
        $this->assertSame(0.0, $r['shortfall_total']);
        $this->assertTrue($r['covered']);
    }

    public function test_not_yet_sent_withdrawal_still_reports_shortfall(): void
    {
        $seller = $this->seller();
        $w = $this->withdrawal($seller, 4.40, null);

        $r = PayoutFundingNeed::compute([$this->wallet(3.85)]);

        $this->assertSame('short', $r['items'][$w->id]['status']);
        $this->assertSame(1.55, $r['items'][$w->id]['missing']);
    }

    public function test_cash_reconciliation_ignores_withdrawal_already_debited_by_assetpay(): void
    {
        $seller = $this->seller();
        $this->withdrawal($seller, 4.40, 'd6f85d97');
        $this->withdrawal($seller, 2.00, null);

        $r = PlatformCashReconciliation::build([$this->wallet(3.85)]);

        $this->assertSame(1, $r['saques_pendentes_qtd']);
        $this->assertSame(2.0, $r['saques_pendentes']);
    }

    private function seller(): User
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller;
    }

    private function withdrawal(User $seller, float $net, ?string $externalId): Withdrawal
    {
        return Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => $net,
            'fee_amount' => 0,
            'net_amount' => $net,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
            'payout_provider' => 'assetpay',
            'payout_external_id' => $externalId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function wallet(float $available): array
    {
        return ['id' => 'assetpay:credential:1', 'slug' => 'assetpay', 'status' => 'ok', 'currency' => 'BRL', 'available' => $available];
    }
}

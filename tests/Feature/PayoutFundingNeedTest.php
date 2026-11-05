<?php

namespace Tests\Feature;

use App\Models\CajuPayAccount;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Platform\PayoutFundingNeed;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PayoutFundingNeedTest extends TestCase
{
    private function cajuAccount(): CajuPayAccount
    {
        $account = CajuPayAccount::create([
            'name' => 'Conta padrão',
            'is_default' => true,
            'is_connected' => true,
            'is_enabled' => true,
        ]);
        $account->setEncryptedCredentials([
            'public_key' => 'gpk',
            'secret_key' => 'gsk',
            'cajupay_admin_fee_payout_brl' => '0,60',
        ]);
        $account->save();

        return $account;
    }

    private function pendingWithdrawal(User $seller, float $net, int $accountId): Withdrawal
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
            'payout_provider' => 'cajupay',
            'payout_meta' => ['cajupay_account_id' => $accountId],
        ]);
    }

    public function test_required_amount_includes_payout_fee_and_shortfall_is_reported(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('cajupay_accounts')) {
            $this->markTestSkipped('withdrawals/cajupay tables');
        }

        $account = $this->cajuAccount();
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $this->pendingWithdrawal($seller, 50.00, $account->id);
        $this->pendingWithdrawal($seller, 50.00, $account->id);

        $rows = [[
            'id' => 'cajupay:account:'.$account->id,
            'slug' => 'cajupay',
            'status' => 'ok',
            'currency' => 'BRL',
            'available' => 20.00,
        ]];

        $r = PayoutFundingNeed::compute($rows);

        // 2 saques de 50,00 + 0,60 de taxa cada = 101,20
        $this->assertSame(2, $r['pending_count']);
        $this->assertSame(100.0, $r['net_total']);
        $this->assertSame(1.2, $r['fees_total']);
        $this->assertSame(101.2, $r['required_total']);
        $this->assertSame(81.2, $r['shortfall_total']);
        $this->assertFalse($r['covered']);
        $this->assertSame('short', array_values($r['items'])[0]['status']);
    }

    public function test_balance_covers_oldest_withdrawals_first(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('cajupay_accounts')) {
            $this->markTestSkipped('withdrawals/cajupay tables');
        }

        $account = $this->cajuAccount();
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $first = $this->pendingWithdrawal($seller, 50.00, $account->id);
        $second = $this->pendingWithdrawal($seller, 50.00, $account->id);

        $rows = [[
            'id' => 'cajupay:account:'.$account->id,
            'slug' => 'cajupay',
            'status' => 'ok',
            'currency' => 'BRL',
            'available' => 60.00,
        ]];

        $r = PayoutFundingNeed::compute($rows);

        $this->assertSame('ok', $r['items'][$first->id]['status']);
        $this->assertSame('short', $r['items'][$second->id]['status']);
        $this->assertSame(41.2, $r['shortfall_total']);
    }

    public function test_no_pending_withdrawals_means_nothing_to_fund(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $r = PayoutFundingNeed::compute([]);

        $this->assertSame(0, $r['pending_count']);
        $this->assertTrue($r['covered']);
        $this->assertSame(0.0, $r['required_total']);
    }
}

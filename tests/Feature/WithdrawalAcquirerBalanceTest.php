<?php

namespace Tests\Feature;

use App\Http\Controllers\Platform\WithdrawalsController;
use App\Models\GatewayCredential;
use App\Services\Platform\AcquirerWalletBalanceService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WithdrawalAcquirerBalanceTest extends TestCase
{
    public function test_assetpay_balance_is_read_from_the_new_api(): void
    {
        GatewayCredential::query()->where('gateway_slug', 'assetpay')->delete();
        $cred = GatewayCredential::query()->firstOrNew(['tenant_id' => null, 'gateway_slug' => 'assetpay']);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials(['public_key' => 'pk_live_x', 'secret_key' => 'sk_live_x']);
        $cred->save();

        Http::fake([
            'https://api.assetpay.cash/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'https://api.assetpay.cash/v1/accounts/balances' => Http::response(['amount' => 925, 'recipientId' => '1']),
        ]);

        $row = collect(app(AcquirerWalletBalanceService::class)->list())->firstWhere('slug', 'assetpay');

        $this->assertNotNull($row);
        $this->assertSame('ok', $row['status']);
        $this->assertSame(9.25, $row['available']);
    }

    public function test_balances_are_attached_to_payout_options(): void
    {
        $options = [
            ['slug' => 'cajupay', 'name' => 'CajuPay'],
            ['slug' => 'assetpay', 'name' => 'AssetPay'],
            ['slug' => 'onlyup', 'name' => 'OnlyUp'],
        ];
        $wallets = [
            ['slug' => 'cajupay', 'id' => 'cajupay:account:1', 'status' => 'ok', 'available' => 100.5],
            ['slug' => 'cajupay', 'id' => 'cajupay:account:2', 'status' => 'ok', 'available' => 20],
            ['slug' => 'assetpay', 'id' => 'assetpay:credential:2', 'status' => 'error', 'available' => null],
        ];

        $out = collect(WithdrawalsController::withBalances($options, $wallets))->keyBy('slug');

        $this->assertSame(120.5, $out['cajupay']['available']);
        $this->assertSame('ok', $out['cajupay']['balance_status']);
        $this->assertNull($out['assetpay']['available']);
        $this->assertSame('error', $out['assetpay']['balance_status']);
        $this->assertSame('unsupported', $out['onlyup']['balance_status']);
        $this->assertSame('CajuPay', $out['cajupay']['name']);
    }
}

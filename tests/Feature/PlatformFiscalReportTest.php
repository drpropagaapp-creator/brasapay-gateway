<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use App\Services\Platform\PlatformFiscalReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PlatformFiscalReportTest extends TestCase
{
    private PlatformFiscalReportService $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
        $this->report = app(PlatformFiscalReportService::class);
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'America/Sao_Paulo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_order_created_in_september_and_paid_in_october_counts_in_october(): void
    {
        $seller = $this->infoprodutor();
        $order = $this->order($seller, 100, Carbon::parse('2026-09-30 18:00:00'));
        $this->saleCredit($seller, $order, 100, 4.99, Carbon::parse('2026-10-02 10:00:00'), WalletTransaction::TYPE_CREDIT_SALE);

        $september = $this->report->cards('2026-09-01 00:00:00', '2026-09-30 23:59:59');
        $october = $this->report->cards('2026-10-01 00:00:00', '2026-10-31 23:59:59');

        $this->assertSame(0.0, $september['sale_fees']);
        $this->assertSame(0, $september['sales_count']);
        $this->assertSame(4.99, $october['sale_fees']);
        $this->assertSame(1, $october['sales_count']);
        $this->assertSame(100.0, $october['volume']);
    }

    public function test_pending_in_september_and_release_in_october_counts_only_in_september(): void
    {
        $seller = $this->infoprodutor();
        $order = $this->order($seller, 100, Carbon::parse('2026-09-30 12:00:00'));
        $pending = $this->saleCredit(
            $seller,
            $order,
            100,
            4.99,
            Carbon::parse('2026-09-30 12:00:00'),
            WalletTransaction::TYPE_CREDIT_SALE_PENDING,
            ['clears_at' => '2026-10-02T12:00:00-03:00']
        );
        $this->saleCredit(
            $seller,
            $order,
            100,
            4.99,
            Carbon::parse('2026-10-02 12:00:00'),
            WalletTransaction::TYPE_CREDIT_SALE,
            ['from_pending_wallet_transaction_id' => $pending->id, 'released_at' => '2026-10-02T12:00:00-03:00']
        );
        $pending->forceFill([
            'meta' => array_merge($pending->meta ?? [], ['released_at' => '2026-10-02T12:00:00-03:00']),
        ])->save();

        $september = $this->report->cards('2026-09-01 00:00:00', '2026-09-30 23:59:59');
        $october = $this->report->cards('2026-10-01 00:00:00', '2026-10-31 23:59:59');

        $this->assertSame(4.99, $september['sale_fees']);
        $this->assertSame(0.0, $october['sale_fees']);
    }

    public function test_pending_plus_release_credit_does_not_duplicate_fee(): void
    {
        $seller = $this->infoprodutor();
        $order = $this->order($seller, 200, Carbon::parse('2026-09-10 12:00:00'));
        $pending = $this->saleCredit($seller, $order, 200, 8.00, Carbon::parse('2026-09-10 12:00:00'), WalletTransaction::TYPE_CREDIT_SALE_PENDING);
        $this->saleCredit(
            $seller,
            $order,
            200,
            8.00,
            Carbon::parse('2026-09-12 12:00:00'),
            WalletTransaction::TYPE_CREDIT_SALE,
            ['from_pending_wallet_transaction_id' => $pending->id]
        );

        $cards = $this->report->cards('2026-09-01 00:00:00', '2026-09-30 23:59:59');
        $this->assertSame(8.0, $cards['sale_fees']);
        $this->assertSame(1, $cards['sales_count']);
    }

    public function test_direct_credit_sale_without_pending_is_counted(): void
    {
        $seller = $this->infoprodutor();
        $order = $this->order($seller, 50, Carbon::parse('2026-09-05 09:00:00'));
        $this->saleCredit($seller, $order, 50, 2.50, Carbon::parse('2026-09-05 09:00:00'), WalletTransaction::TYPE_CREDIT_SALE);

        $cards = $this->report->cards('2026-09-01 00:00:00', '2026-09-30 23:59:59');
        $this->assertSame(2.5, $cards['sale_fees']);
        $this->assertSame(1, $cards['sales_count']);
    }

    public function test_later_refund_does_not_remove_retained_sale_fee(): void
    {
        $seller = $this->infoprodutor();
        $order = $this->order($seller, 80, Carbon::parse('2026-09-08 10:00:00'));
        $this->saleCredit($seller, $order, 80, 3.20, Carbon::parse('2026-09-08 10:00:00'), WalletTransaction::TYPE_CREDIT_SALE);
        $order->update(['status' => 'refunded']);
        WalletTransaction::query()->create([
            'tenant_id' => $seller->id,
            'order_id' => $order->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_DEBIT_REFUND,
            'amount_gross' => 80,
            'amount_fee' => 3.20,
            'amount_net' => 76.80,
            'meta' => ['reason' => 'platform_manual_refund'],
        ]);

        $cards = $this->report->cards('2026-09-01 00:00:00', '2026-09-30 23:59:59');
        $this->assertSame(3.2, $cards['sale_fees']);
        $this->assertSame(1, $cards['sales_count']);
    }

    public function test_paid_pending_and_processing_withdrawals_count_and_failed_rejected_do_not(): void
    {
        $seller = $this->infoprodutor();
        $at = Carbon::parse('2026-09-12 11:00:00');
        $this->withdrawal($seller, 10.00, MerchantWithdrawalService::STATUS_PAID, $at);
        $this->withdrawal($seller, 5.00, MerchantWithdrawalService::STATUS_PENDING, $at);
        $this->withdrawal($seller, 4.00, MerchantWithdrawalService::STATUS_PROCESSING, $at);
        $this->withdrawal($seller, 99.00, MerchantWithdrawalService::STATUS_FAILED, $at);
        $this->withdrawal($seller, 88.00, MerchantWithdrawalService::STATUS_REJECTED, $at);

        $cards = $this->report->cards('2026-09-01 00:00:00', '2026-09-30 23:59:59');
        $this->assertSame(19.0, $cards['withdrawal_fees']);
        $this->assertSame(3, $cards['withdrawals_count']);
    }

    public function test_changing_current_seller_percent_does_not_change_historical_fee(): void
    {
        $seller = $this->infoprodutor();
        $order = $this->order($seller, 100, Carbon::parse('2026-09-01 10:00:00'));
        $this->saleCredit($seller, $order, 100, 4.99, Carbon::parse('2026-09-01 10:00:00'), WalletTransaction::TYPE_CREDIT_SALE);
        $seller->forceFill([
            'merchant_fees' => ['pix' => ['percent' => 99, 'fixed' => 50]],
        ])->save();

        $cards = $this->report->cards('2026-09-01 00:00:00', '2026-09-30 23:59:59');
        $this->assertSame(4.99, $cards['sale_fees']);
    }

    public function test_coproduction_does_not_duplicate_global_sales_count(): void
    {
        $owner = $this->infoprodutor(['name' => 'João Silva', 'document' => '12345678901']);
        $coproducer = $this->infoprodutor(['name' => 'Maria Coprodutora', 'document' => '98765432100']);
        $order = $this->order($owner, 100, Carbon::parse('2026-09-20 10:00:00'));
        $this->saleCredit($owner, $order, 70, 3.50, Carbon::parse('2026-09-20 10:00:00'), WalletTransaction::TYPE_CREDIT_SALE);
        $this->saleCredit($coproducer, $order, 30, 1.50, Carbon::parse('2026-09-20 10:00:00'), WalletTransaction::TYPE_CREDIT_SALE);

        $cards = $this->report->cards('2026-09-01 00:00:00', '2026-09-30 23:59:59');
        $this->assertSame(1, $cards['sales_count']);
        $this->assertSame(100.0, $cards['volume']);
        $this->assertSame(5.0, $cards['sale_fees']);

        $rows = $this->report->sellerRows('2026-09-01 00:00:00', '2026-09-30 23:59:59');
        $this->assertCount(2, $rows);
        $joao = $rows->firstWhere('tenant_id', $owner->id);
        $maria = $rows->firstWhere('tenant_id', $coproducer->id);
        $this->assertSame(1, $joao['sales_count']);
        $this->assertSame(70.0, $joao['volume']);
        $this->assertSame(3.5, $joao['sale_fees']);
        $this->assertSame(1, $maria['sales_count']);
        $this->assertSame(30.0, $maria['volume']);
        $this->assertSame(1.5, $maria['sale_fees']);
    }

    public function test_pagination_does_not_change_cards_or_period_totals(): void
    {
        $admin = $this->platformAdmin();
        for ($i = 0; $i < 30; $i++) {
            $seller = $this->infoprodutor(['name' => 'Seller '.$i, 'document' => str_pad((string) $i, 11, '0', STR_PAD_LEFT)]);
            $order = $this->order($seller, 10, Carbon::parse('2026-09-03 10:00:00'));
            $this->saleCredit($seller, $order, 10, 1.00, Carbon::parse('2026-09-03 10:00:00'), WalletTransaction::TYPE_CREDIT_SALE);
        }

        $page1 = $this->actingAs($admin)
            ->get('/plataforma/fiscal?period=mes&month=9&year=2026&per_page=25&page=1')
            ->assertOk();
        $page2 = $this->actingAs($admin)
            ->get('/plataforma/fiscal?period=mes&month=9&year=2026&per_page=25&page=2')
            ->assertOk();

        $page1->assertInertia(fn ($page) => $page
            ->component('Platform/Fiscal/Index')
            ->where('cards.sale_fees', 30)
            ->where('cards.sales_count', 30)
            ->where('totals.sale_fees', 30)
            ->where('sellers.total', 30)
            ->where('sellers.per_page', 25)
            ->has('sellers.data', 25)
        );
        $page2->assertInertia(fn ($page) => $page
            ->where('cards.sale_fees', 30)
            ->where('cards.sales_count', 30)
            ->where('totals.sale_fees', 30)
            ->has('sellers.data', 5)
        );
    }

    public function test_csv_and_xlsx_match_screen_totals(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->infoprodutor([
            'name' => 'João Silva',
            'company_name' => 'João Silva LTDA',
            'document' => '12345678901',
        ]);
        $order = $this->order($seller, 52_000, Carbon::parse('2026-09-10 10:00:00'));
        $this->saleCredit($seller, $order, 52_000, 2594.80, Carbon::parse('2026-09-10 10:00:00'), WalletTransaction::TYPE_CREDIT_SALE);
        $this->withdrawal($seller, 84.30, MerchantWithdrawalService::STATUS_PAID, Carbon::parse('2026-09-15 10:00:00'));

        $this->actingAs($admin)
            ->get('/plataforma/fiscal?period=mes&month=9&year=2026')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('cards.sale_fees', 2594.8)
                ->where('cards.withdrawal_fees', 84.3)
                ->where('cards.total_fees', 2679.1)
                ->where('cards.sales_count', 1)
                ->where('cards.volume', 52000)
            );

        $csv = $this->actingAs($admin)
            ->get('/plataforma/fiscal/export.csv?period=mes&month=9&year=2026')
            ->assertOk();
        $csvBody = $csv->streamedContent();
        $this->assertStringContainsString('João Silva LTDA', $csvBody);
        $this->assertStringContainsString('12345678901', $csvBody);
        $this->assertStringContainsString('2.594,80', $csvBody);
        $this->assertStringContainsString('84,30', $csvBody);
        $this->assertStringContainsString('2.679,10', $csvBody);
        $this->assertStringContainsString('TOTAL DO PERÍODO', $csvBody);
        $this->assertStringContainsString('fiscal-2026-09-setembro.csv', (string) $csv->headers->get('content-disposition'));

        $xlsx = $this->actingAs($admin)
            ->get('/plataforma/fiscal/export.xlsx?period=mes&month=9&year=2026')
            ->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'fiscal').'.xlsx';
        file_put_contents($tmp, $xlsx->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        $this->assertSame('Fiscal', $sheet->getTitle());
        $foundTotal = false;
        foreach ($sheet->toArray() as $row) {
            if (($row[0] ?? '') === 'TOTAL DO PERÍODO') {
                $this->assertSame(1, (int) $row[2]);
                $this->assertSame('52.000,00', $row[3]);
                $this->assertSame('2.594,80', $row[4]);
                $this->assertSame(1, (int) $row[5]);
                $this->assertSame('84,30', $row[6]);
                $this->assertSame('2.679,10', $row[7]);
                $foundTotal = true;
            }
        }
        $this->assertTrue($foundTotal);
        @unlink($tmp);
    }

    public function test_seller_cannot_access_fiscal(): void
    {
        $seller = $this->infoprodutor();
        $this->actingAs($seller)
            ->get('/plataforma/fiscal')
            ->assertForbidden();
    }

    public function test_admin_page_defaults_to_current_month(): void
    {
        $admin = $this->platformAdmin();
        $this->actingAs($admin)
            ->get('/plataforma/fiscal')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Fiscal/Index')
                ->where('period', 'mes')
                ->where('month', 10)
                ->where('year', 2026)
            );
    }

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function infoprodutor(array $overrides = []): User
    {
        $seller = User::factory()->create(array_merge([
            'role' => User::ROLE_INFOPRODUTOR,
        ], $overrides));
        $attrs = ['tenant_id' => $seller->id, 'account_status' => 'approved'];
        if (Schema::hasColumn('users', 'kyc_status')) {
            $attrs['kyc_status'] = User::KYC_APPROVED;
        }
        $seller->forceFill($attrs)->save();

        return $seller->fresh();
    }

    private function order(User $seller, float $amount, Carbon $createdAt): Order
    {
        $buyer = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product = $this->createTestProduct([
            'id' => (string) (100000 + $seller->id),
            'tenant_id' => $seller->id,
        ]);
        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => $amount,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'gateway' => 'efi',
            'metadata' => [],
        ]);
        $order->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $order->fresh();
    }

    private function saleCredit(
        User $tenant,
        Order $order,
        float $gross,
        float $fee,
        Carbon $at,
        string $type,
        array $meta = [],
    ): WalletTransaction {
        $tx = WalletTransaction::query()->create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'bucket' => 'pix',
            'type' => $type,
            'amount_gross' => $gross,
            'amount_fee' => $fee,
            'amount_net' => round($gross - $fee, 2),
            'meta' => $meta,
        ]);
        $tx->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $tx->fresh();
    }

    private function withdrawal(User $seller, float $fee, string $status, Carbon $at): Withdrawal
    {
        $w = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 100,
            'fee_amount' => $fee,
            'net_amount' => round(100 - $fee, 2),
            'bucket' => 'pix',
            'status' => $status,
            'currency' => 'BRL',
        ]);
        $w->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $w->fresh();
    }
}

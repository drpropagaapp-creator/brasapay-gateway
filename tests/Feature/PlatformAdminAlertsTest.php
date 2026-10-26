<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Mail\RefundRequestAdminMail;
use App\Mail\WithdrawalFailedAdminMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\RefundRequest;
use App\Models\Setting;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use App\Services\Platform\PlatformAdminAlertCounts;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlatformAdminAlertsTest extends TestCase
{
    public function test_sidebar_badges_count_pending_items(): void
    {
        if (! Schema::hasColumn('users', 'kyc_status')) {
            $this->markTestSkipped('kyc_status column missing');
        }

        $pendingKyc = User::query()->create([
            'name' => 'KYC Pending',
            'email' => 'kyc-pending@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'kyc_status' => User::KYC_PENDING_REVIEW,
        ]);
        $pendingKyc->update(['tenant_id' => $pendingKyc->id]);

        $counts = app(PlatformAdminAlertCounts::class)->sidebarBadges();

        $this->assertGreaterThanOrEqual(1, $counts['kyc']);
    }

    public function test_platform_admin_receives_sidebar_badges_in_inertia(): void
    {
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);

        $admin = User::query()->create([
            'name' => 'Plat Admin',
            'email' => 'plat-badges@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('platform_admin_sidebar_badges')
                ->has('platform_admin_sidebar_badges.kyc')
                ->has('platform_admin_sidebar_badges.saques')
                ->has('platform_admin_sidebar_badges.disputas')
                ->has('platform_admin_sidebar_badges.transacoes')
            );
    }

    public function test_mark_failed_sends_admin_email_when_configured(): void
    {
        Mail::fake();

        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('withdrawals/tenant_wallets tables');
        }

        Setting::set('kyc_notification_emails', 'ops@example.com', null);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        TenantWallet::query()->updateOrCreate(
            ['tenant_id' => $seller->id],
            ['available_pix' => 20.0, 'available_balance' => 20.0]
        );

        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 30,
            'fee_amount' => 0,
            'net_amount' => 30,
            'bucket' => 'pix',
            'status' => 'processing',
            'currency' => 'BRL',
        ]);

        MerchantWithdrawalService::markFailed($withdrawal->fresh(), 'PIX rejeitado pelo banco');

        Mail::assertSent(WithdrawalFailedAdminMail::class, function (WithdrawalFailedAdminMail $mail) use ($withdrawal) {
            return $mail->withdrawal->is($withdrawal->fresh())
                && str_contains($mail->reason, 'PIX rejeitado');
        });
    }

    public function test_refund_request_notifies_platform_admins(): void
    {
        Mail::fake();

        if (! Schema::hasTable('refund_requests')) {
            $this->markTestSkipped('refund_requests table');
        }

        Setting::set('kyc_notification_emails', 'ops@example.com', null);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id, 'kyc_status' => User::KYC_APPROVED, 'account_status' => 'approved'])->save();

        $customer = User::factory()->create(['role' => 'cliente']);

        $product = Product::factory()->create(['tenant_id' => $seller->id]);

        $order = Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'email' => $customer->email,
            'amount' => 99.90,
            'status' => 'completed',
            'gateway' => 'manual',
            'payment_method' => 'pix',
        ]);

        RefundRequest::query()->create([
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'tenant_id' => $seller->id,
            'status' => RefundRequest::STATUS_PENDING,
            'customer_reason' => 'Não quero mais o produto',
        ]);

        $request = RefundRequest::query()->first();
        app(\App\Services\RefundRequestService::class)->notifyPlatformAdmins($request);

        Mail::assertSent(RefundRequestAdminMail::class);
    }

    public function test_visiting_transactions_clears_badge_until_a_new_refund_arrives(): void
    {
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);

        if (! Schema::hasTable('refund_requests') || ! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('refund_requests/withdrawals tables');
        }

        Carbon::setTestNow('2026-10-26 12:00:00');

        try {
            $admin = $this->platformAdmin('plat-badge-tx@example.com');
            $seller = $this->seller('seller-badge-tx@example.com');
            $this->createPendingRefund($seller, 'Primeiro reembolso');
            $this->createPendingWithdrawal($seller, 40);

            $counts = app(PlatformAdminAlertCounts::class);
            $this->assertSame(1, $counts->sidebarBadges($admin)['transacoes']);
            $this->assertSame(1, $counts->sidebarBadges($admin)['saques']);

            $this->actingAs($admin)
                ->get(route('plataforma.transacoes.index'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('platform_admin_sidebar_badges.transacoes', 0)
                    ->where('platform_admin_sidebar_badges.saques', 1)
                    ->where('refund_requests_pending_count', 1));

            Carbon::setTestNow('2026-10-26 12:01:00');
            $this->createPendingRefund($seller, 'Novo reembolso');

            $this->actingAs($admin)
                ->get(route('plataforma.dashboard'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('platform_admin_sidebar_badges.transacoes', 1)
                    ->where('platform_admin_sidebar_badges.saques', 1));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_visiting_withdrawals_clears_saques_badge_until_a_new_request(): void
    {
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);

        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        Carbon::setTestNow('2026-10-26 13:00:00');

        try {
            $admin = $this->platformAdmin('plat-badge-wd@example.com');
            $seller = $this->seller('seller-badge-wd@example.com');
            $this->createPendingWithdrawal($seller, 40);

            $counts = app(PlatformAdminAlertCounts::class);
            $this->assertSame(1, $counts->sidebarBadges($admin)['saques']);

            $this->actingAs($admin)
                ->get(route('plataforma.saques.index'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('platform_admin_sidebar_badges.saques', 0));

            Carbon::setTestNow('2026-10-26 13:01:00');
            $this->createPendingWithdrawal($seller, 55);

            $this->actingAs($admin)
                ->get(route('plataforma.dashboard'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('platform_admin_sidebar_badges.saques', 1));
        } finally {
            Carbon::setTestNow();
        }
    }

    private function platformAdmin(string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function seller(string $email): User
    {
        $seller = User::factory()->create([
            'email' => $email,
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }

    private function createPendingRefund(User $seller, string $reason): RefundRequest
    {
        $customer = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $order = Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'email' => $customer->email,
            'amount' => 50,
            'status' => 'completed',
            'gateway' => 'manual',
            'payment_method' => 'pix',
        ]);

        return RefundRequest::query()->create([
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'tenant_id' => $seller->id,
            'status' => RefundRequest::STATUS_PENDING,
            'customer_reason' => $reason,
        ]);
    }

    private function createPendingWithdrawal(User $seller, float $amount): Withdrawal
    {
        return Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => $amount,
            'fee_amount' => 0,
            'net_amount' => $amount,
            'bucket' => 'pix',
            'status' => MerchantWithdrawalService::STATUS_PENDING,
            'currency' => 'BRL',
        ]);
    }
}

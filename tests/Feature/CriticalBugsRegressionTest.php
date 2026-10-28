<?php

namespace Tests\Feature;

use App\Jobs\ReconcileWooviWithdrawalJob;
use App\Jobs\ReconcileXpagWithdrawalJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\PlatformOrderAdminService;
use Tests\TestCase;

/**
 * Regressões dos 3 bugs críticos da seção 7 (#1 renovação sem pagamento,
 * #2 estorno de saque sem confirmação, #4 MED perdido).
 */
class CriticalBugsRegressionTest extends TestCase
{
    private function seller(): User
    {
        $u = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'kyc_status' => User::KYC_APPROVED]);
        $u->forceFill(['tenant_id' => $u->id])->save();

        return $u;
    }

    // ── #1 Renovação ────────────────────────────────────────────────────────

    private function subscription(): Subscription
    {
        $seller = $this->seller();
        $buyer = User::factory()->create(['role' => User::ROLE_CLIENTE, 'tenant_id' => $seller->id]);
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'checkout_slug' => 'vip-'.uniqid(),
        ]);
        $plan = SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 29.9,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => 'p-'.uniqid(),
            'position' => 1,
        ]);

        return Subscription::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_PAST_DUE,
            'current_period_start' => now()->subMonth()->startOfDay(),
            'current_period_end' => now()->subDay()->startOfDay(),
        ]);
    }

    public function test_renovacao_sem_metodo_ou_com_manual_nao_estende_acesso_nem_cria_pedido_pago(): void
    {
        $subscription = $this->subscription();
        $token = $subscription->fresh()->renewal_token;
        $this->assertNotEmpty($token);
        $periodEnd = $subscription->fresh()->current_period_end?->toDateString();

        foreach ([[], ['payment_method' => 'manual'], ['payment_method' => 'apple_pay'], ['payment_method' => 'google_pay']] as $extra) {
            $this->post('/renovar', array_merge(['token' => $token], $extra));
        }
        // pix_auto sem mandato ativo também não pode cair no "pago sem cobrança"
        $this->post('/renovar', ['token' => $token, 'payment_method' => 'pix_auto']);

        $this->assertSame(0, Order::query()->where('subscription_plan_id', $subscription->subscription_plan_id)->where('status', 'completed')->count());
        $this->assertSame(0, Order::query()->where('gateway', 'manual')->count());
        $fresh = $subscription->fresh();
        $this->assertSame(Subscription::STATUS_PAST_DUE, $fresh->status);
        $this->assertSame($periodEnd, $fresh->current_period_end?->toDateString());
    }

    // ── #2 Saque ────────────────────────────────────────────────────────────

    private function processingWithdrawal(User $seller, string $provider): Withdrawal
    {
        TenantWallet::query()->create(['tenant_id' => $seller->id, 'available_pix' => 0, 'pending_pix' => 0, 'available_balance' => 0, 'pending_balance' => 0]);

        return Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 500,
            'fee_amount' => 0,
            'net_amount' => 500,
            'bucket' => 'pix',
            'status' => 'processing',
            'payout_provider' => $provider,
            'payout_external_id' => 'tx-'.uniqid(),
            'payout_meta' => [],
        ]);
    }

    public function test_reconciliacao_esgotada_nao_estorna_o_saque_e_deixa_para_o_admin(): void
    {
        config(['queue.default' => 'database']);

        foreach ([ReconcileWooviWithdrawalJob::class => 'woovi', ReconcileXpagWithdrawalJob::class => 'xpag'] as $jobClass => $provider) {
            $seller = $this->seller();
            $withdrawal = $this->processingWithdrawal($seller, $provider);

            // Sem credencial conectada: a consulta não confirma nada; na 36ª tentativa o job desistia e estornava.
            $job = (new $jobClass($withdrawal->id))->withFakeQueueInteractions();
            $job->job->attempts = 36;
            $job->handle();

            $fresh = $withdrawal->fresh();
            $this->assertSame('processing', $fresh->status, "$provider: saque não pode virar falho sem confirmação");
            $this->assertTrue((bool) ($fresh->payout_meta['reconcile_exhausted'] ?? false), "$provider: marca para o admin");
            $this->assertSame(0.0, (float) TenantWallet::query()->where('tenant_id', $seller->id)->value('available_pix'), "$provider: saldo não volta");
            $this->assertFalse(WalletTransaction::query()->where('withdrawal_id', $withdrawal->id)->exists(), "$provider: nenhum estorno lançado");
        }
    }

    // ── #4 MED ──────────────────────────────────────────────────────────────

    /**
     * Pedido pago com duas partes já liberadas (principal 70 + reserva 30) e 200 de outras vendas no disponível.
     */
    private function paidOrderWithReleasedParts(User $seller): Order
    {
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'metadata' => [],
        ]);
        TenantWallet::query()->create([
            'tenant_id' => $seller->id,
            'available_pix' => 300, 'pending_pix' => 0,
            'available_balance' => 300, 'pending_balance' => 0,
        ]);
        foreach ([['main', 70], ['reserve', 30]] as [$portion, $net]) {
            WalletTransaction::query()->create([
                'tenant_id' => $seller->id, 'order_id' => $order->id, 'bucket' => 'pix',
                'type' => WalletTransaction::TYPE_CREDIT_SALE,
                'amount_gross' => $net, 'amount_fee' => 0, 'amount_net' => $net,
                'meta' => ['portion' => $portion],
            ]);
        }

        return $order;
    }

    private function wallet(User $seller): array
    {
        $w = TenantWallet::query()->where('tenant_id', $seller->id)->first();

        return [round((float) $w->available_pix, 2), round((float) $w->pending_pix, 2)];
    }

    public function test_med_retem_todas_as_partes_ja_liberadas(): void
    {
        $seller = $this->seller();
        $order = $this->paidOrderWithReleasedParts($seller);

        PlatformOrderAdminService::markDisputed($order->fresh());

        // As duas partes (100) saem do disponível — antes só a primeira (70) era retida.
        $this->assertSame([200.0, 100.0], $this->wallet($seller));
    }

    public function test_med_perdido_estorna_do_valor_retido_e_nao_do_saldo_de_outras_vendas(): void
    {
        $seller = $this->seller();
        $order = $this->paidOrderWithReleasedParts($seller);

        PlatformOrderAdminService::markDisputed($order->fresh());
        PlatformOrderAdminService::refundPaidOrDisputed($order->fresh());

        // Outras vendas (200) intactas e nada preso no pendente.
        $this->assertSame([200.0, 0.0], $this->wallet($seller));
        $this->assertSame('refunded', $order->fresh()->status);
        $hold = WalletTransaction::query()->where('order_id', $order->id)->where('type', WalletTransaction::TYPE_MED_HOLD)->firstOrFail();
        $this->assertNotEmpty($hold->meta['released_at'] ?? null);
        $this->assertSame('med_dispute_refunded', $hold->meta['reason'] ?? null);

        // Liberar depois não pode devolver nada.
        PlatformOrderAdminService::releaseMedHold($order->fresh());
        $this->assertSame([200.0, 0.0], $this->wallet($seller));
    }

    public function test_med_ganho_devolve_exatamente_o_retido(): void
    {
        $seller = $this->seller();
        $order = $this->paidOrderWithReleasedParts($seller);

        PlatformOrderAdminService::markDisputed($order->fresh());
        PlatformOrderAdminService::releaseMedHoldAndComplete($order->fresh());

        $this->assertSame([300.0, 0.0], $this->wallet($seller));
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_med_so_com_partes_pendentes_nao_move_saldo_ao_ganhar(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $order = Order::create([
            'tenant_id' => $seller->id, 'user_id' => $buyer->id, 'product_id' => $product->id,
            'status' => 'completed', 'amount' => 100, 'email' => $buyer->email, 'payment_method' => 'pix', 'metadata' => [],
        ]);
        // 100 desta venda ainda em liquidação + 50 de outra venda também pendente.
        TenantWallet::query()->create(['tenant_id' => $seller->id, 'available_pix' => 0, 'pending_pix' => 150, 'available_balance' => 0, 'pending_balance' => 150]);
        WalletTransaction::query()->create([
            'tenant_id' => $seller->id, 'order_id' => $order->id, 'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_CREDIT_SALE_PENDING,
            'amount_gross' => 100, 'amount_fee' => 0, 'amount_net' => 100,
            'meta' => ['clears_at' => now()->addDays(10)->toIso8601String()],
        ]);

        PlatformOrderAdminService::markDisputed($order->fresh());
        PlatformOrderAdminService::releaseMedHoldAndComplete($order->fresh());

        // Antes: o "release" movia 100 do pendente para o disponível sem a parte ter vencido.
        $this->assertSame([0.0, 150.0], $this->wallet($seller));
    }
}

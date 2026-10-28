<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSplitSnapshot;
use App\Models\Product;
use App\Models\ProductCoproducer;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\OrderCompletedWalletCreditor;
use Tests\TestCase;

class CoproductionSplitIntegrityTest extends TestCase
{
    private const CREDIT_TYPES = [WalletTransaction::TYPE_CREDIT_SALE, WalletTransaction::TYPE_CREDIT_SALE_PENDING];

    private function infoprodutor(): User
    {
        $u = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'kyc_status' => User::KYC_APPROVED]);
        $u->forceFill(['tenant_id' => $u->id])->save();

        return $u;
    }

    private function activeCoproducer(Product $product, User $seller, User $co, float $pct, string $tokenChar): ProductCoproducer
    {
        return ProductCoproducer::query()->create([
            'product_id' => $product->id,
            'inviter_user_id' => $seller->id,
            'co_producer_user_id' => $co->id,
            'email' => $co->email,
            'status' => ProductCoproducer::STATUS_ACTIVE,
            'token' => str_repeat($tokenChar, 48),
            'commission_percent' => $pct,
            'commission_on_direct_sales' => true,
            'commission_on_affiliate_sales' => false,
            'duration_preset' => ProductCoproducer::DURATION_ETERNAL,
            'starts_at' => now()->subMinute(),
            'ends_at' => null,
            'accepted_at' => now()->subMinute(),
        ]);
    }

    private function completedOrder(User $seller, Product $product, float $amount): Order
    {
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO]);

        return Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => $amount,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'metadata' => [],
        ]);
    }

    private function grossByTenant(Order $order): array
    {
        return WalletTransaction::query()
            ->where('order_id', $order->id)
            ->whereIn('type', self::CREDIT_TYPES)
            ->get()
            ->groupBy('tenant_id')
            ->map(fn ($rows) => round((float) $rows->sum('amount_gross'), 2))
            ->all();
    }

    public function test_cenario_a_dois_coprodutores_nao_zeram_o_produtor(): void
    {
        $seller = $this->infoprodutor();
        $a = $this->infoprodutor();
        $b = $this->infoprodutor();
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $this->activeCoproducer($product, $seller, $a, 20, 'a');
        $this->activeCoproducer($product, $seller, $b, 10, 'b');

        $order = $this->completedOrder($seller, $product, 100.00);
        OrderCompletedWalletCreditor::credit($order);

        $gross = $this->grossByTenant($order);
        $this->assertEqualsWithDelta(70.00, $gross[$seller->id], 0.001);
        $this->assertEqualsWithDelta(20.00, $gross[$a->id], 0.001);
        $this->assertEqualsWithDelta(10.00, $gross[$b->id], 0.001);
        $this->assertEqualsWithDelta(100.00, array_sum($gross), 0.001);
    }

    public function test_cenario_i_credito_repetido_nao_duplica(): void
    {
        $seller = $this->infoprodutor();
        $a = $this->infoprodutor();
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $this->activeCoproducer($product, $seller, $a, 25, 'c');

        $order = $this->completedOrder($seller, $product, 297.00);
        OrderCompletedWalletCreditor::credit($order);
        $first = WalletTransaction::query()->where('order_id', $order->id)->count();

        OrderCompletedWalletCreditor::credit($order);
        OrderCompletedWalletCreditor::credit($order);

        $this->assertSame($first, WalletTransaction::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, OrderSplitSnapshot::query()->where('order_id', $order->id)->count());
    }

    public function test_cenario_j_percentual_alterado_depois_nao_muda_a_divisao_registrada(): void
    {
        $seller = $this->infoprodutor();
        $a = $this->infoprodutor();
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $row = $this->activeCoproducer($product, $seller, $a, 20, 'd');

        $order = $this->completedOrder($seller, $product, 100.00);
        OrderCompletedWalletCreditor::credit($order);
        $snapshotBefore = OrderSplitSnapshot::query()->where('order_id', $order->id)->firstOrFail()->slices;

        $row->update(['commission_percent' => 30]);
        WalletTransaction::query()->where('order_id', $order->id)->where('tenant_id', $a->id)->delete();
        OrderCompletedWalletCreditor::credit($order);

        $this->assertSame($snapshotBefore, OrderSplitSnapshot::query()->where('order_id', $order->id)->firstOrFail()->slices);
        $this->assertEqualsWithDelta(20.00, $this->grossByTenant($order)[$a->id], 0.001);
    }

    public function test_credito_parcial_e_completado_na_nova_tentativa(): void
    {
        $seller = $this->infoprodutor();
        $a = $this->infoprodutor();
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $this->activeCoproducer($product, $seller, $a, 20, 'e');

        $order = $this->completedOrder($seller, $product, 100.00);
        OrderCompletedWalletCreditor::credit($order);

        // Simula falha no meio: o crédito do co-produtor não chegou a ser gravado.
        WalletTransaction::query()->where('order_id', $order->id)->where('tenant_id', $a->id)->delete();
        OrderCompletedWalletCreditor::credit($order);

        $gross = $this->grossByTenant($order);
        $this->assertEqualsWithDelta(80.00, $gross[$seller->id], 0.001);
        $this->assertEqualsWithDelta(20.00, $gross[$a->id], 0.001);
    }

    private function walletTotal(int $tenantId): float
    {
        $w = \App\Models\TenantWallet::query()->where('tenant_id', $tenantId)->first();

        return $w === null ? 0.0 : round((float) $w->available_balance + (float) $w->pending_balance, 2);
    }

    public function test_cenario_g_reembolso_integral_reverte_cada_participante_pelo_que_recebeu(): void
    {
        $seller = $this->infoprodutor();
        $a = $this->infoprodutor();
        $b = $this->infoprodutor();
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $this->activeCoproducer($product, $seller, $a, 20, 'r');
        $this->activeCoproducer($product, $seller, $b, 10, 's');

        $order = $this->completedOrder($seller, $product, 100.00);
        OrderCompletedWalletCreditor::credit($order);
        $this->assertGreaterThan(0, $this->walletTotal($a->id));

        // Percentual muda depois da venda: a reversão segue o que foi creditado.
        ProductCoproducer::query()->where('co_producer_user_id', $a->id)->update(['commission_percent' => 50]);

        \App\Services\PlatformOrderAdminService::refundPaidOrDisputed($order->fresh());

        $this->assertEqualsWithDelta(0.0, $this->walletTotal($seller->id), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->walletTotal($a->id), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->walletTotal($b->id), 0.001);
    }

    public function test_cenario_l_med_trava_o_valor_de_cada_participante_sem_mexer_no_dos_outros(): void
    {
        $seller = $this->infoprodutor();
        $a = $this->infoprodutor();
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $this->activeCoproducer($product, $seller, $a, 20, 't');

        $order = $this->completedOrder($seller, $product, 100.00);
        OrderCompletedWalletCreditor::credit($order);
        $sellerBefore = $this->walletTotal($seller->id);
        $coBefore = $this->walletTotal($a->id);

        \App\Services\PlatformOrderAdminService::markDisputed($order->fresh());

        $this->assertSame('disputed', $order->fresh()->status);
        $this->assertEqualsWithDelta($sellerBefore, $this->walletTotal($seller->id), 0.001, 'MED move o saldo para pendente, não some.');
        $this->assertEqualsWithDelta($coBefore, $this->walletTotal($a->id), 0.001);
        $this->assertEqualsWithDelta(0.0, (float) \App\Models\TenantWallet::query()->where('tenant_id', $a->id)->value('available_balance'), 0.001);
    }

    public function test_simulacao_mostra_a_divisao_real_e_marca_convite_pendente(): void
    {
        $seller = $this->infoprodutor();
        $a = $this->infoprodutor();
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $this->activeCoproducer($product, $seller, $a, 20, 'g');
        ProductCoproducer::query()->create([
            'product_id' => $product->id,
            'inviter_user_id' => $seller->id,
            'email' => 'pendente@test.com',
            'status' => ProductCoproducer::STATUS_PENDING,
            'token' => str_repeat('h', 48),
            'commission_percent' => 10,
            'commission_on_direct_sales' => true,
            'commission_on_affiliate_sales' => false,
            'duration_preset' => ProductCoproducer::DURATION_ETERNAL,
        ]);

        $response = $this->actingAs($seller)->getJson("/produtos/{$product->id}/coproducers/simulacao?amount=100");

        $response->assertOk();
        $participants = collect($response->json('participants'));
        $this->assertEqualsWithDelta(70.0, $participants->firstWhere('role', 'seller')['gross'], 0.001);
        $this->assertEqualsWithDelta(100.0, $participants->sum('gross'), 0.001);
        $this->assertTrue((bool) $participants->firstWhere('pending_invite', true));
    }

    public function test_simulacao_de_produto_de_outro_seller_e_negada(): void
    {
        $owner = $this->infoprodutor();
        $intruder = $this->infoprodutor();
        $product = $this->createTestProduct(['tenant_id' => $owner->id]);

        $this->actingAs($intruder)->getJson("/produtos/{$product->id}/coproducers/simulacao?amount=100")->assertForbidden();
    }

    public function test_cenario_f_coprodutor_do_principal_nao_ganha_sobre_bump_de_outro_produto(): void
    {
        $seller = $this->infoprodutor();
        $a = $this->infoprodutor();
        $main = $this->createTestProduct(['tenant_id' => $seller->id]);
        $bump = $this->createTestProduct(['tenant_id' => $seller->id]);
        $this->activeCoproducer($main, $seller, $a, 20, 'f');

        $order = $this->completedOrder($seller, $main, 130.00);
        OrderItem::query()->create(['order_id' => $order->id, 'product_id' => $main->id, 'amount' => 100.00, 'position' => 0]);
        OrderItem::query()->create(['order_id' => $order->id, 'product_id' => $bump->id, 'amount' => 30.00, 'position' => 1]);

        OrderCompletedWalletCreditor::credit($order->fresh());

        $gross = $this->grossByTenant($order);
        $this->assertEqualsWithDelta(20.00, $gross[$a->id], 0.001);
        $this->assertEqualsWithDelta(110.00, $gross[$seller->id], 0.001);
    }
}

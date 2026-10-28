<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\Platform\PlatformRevenueKpis;
use Tests\TestCase;

/**
 * BrasaPay 4.0 / Onda 1 — métricas financeiras confiáveis (riscos #18 e #19 da seção 7).
 */
class PlatformRevenueKpisTest extends TestCase
{
    private function seller(): User
    {
        $u = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $u->forceFill(['tenant_id' => $u->id])->save();

        return $u;
    }

    private function line(Order $order, string $type, float $fee, array $meta = []): WalletTransaction
    {
        return WalletTransaction::query()->create([
            'tenant_id' => $order->tenant_id, 'order_id' => $order->id, 'bucket' => 'card', 'type' => $type,
            'amount_gross' => 100, 'amount_fee' => $fee, 'amount_net' => 100 - $fee, 'meta' => $meta,
        ]);
    }

    public function test_taxa_conta_cada_parte_uma_vez_incluindo_reserva_ainda_pendente(): void
    {
        $seller = $this->seller();
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $order = Order::create([
            'tenant_id' => $seller->id, 'user_id' => $seller->id, 'product_id' => $product->id,
            'status' => 'completed', 'amount' => 200, 'email' => 'c@x.com', 'payment_method' => 'card', 'metadata' => [],
        ]);
        // Parte principal (taxa 7,00) já liberada + reserva (taxa 3,00) ainda pendente.
        $main = $this->line($order, WalletTransaction::TYPE_CREDIT_SALE_PENDING, 7.00, ['portion' => 'main', 'released_at' => now()->toIso8601String()]);
        $this->line($order, WalletTransaction::TYPE_CREDIT_SALE, 7.00, ['from_pending_wallet_transaction_id' => $main->id]);
        $this->line($order, WalletTransaction::TYPE_CREDIT_SALE_PENDING, 3.00, ['portion' => 'reserve']);
        // Outra venda D+0 direta.
        $order2 = Order::create([
            'tenant_id' => $seller->id, 'user_id' => $seller->id, 'product_id' => $product->id,
            'status' => 'completed', 'amount' => 50, 'email' => 'd@x.com', 'payment_method' => 'card', 'metadata' => [],
        ]);
        $this->line($order2, WalletTransaction::TYPE_CREDIT_SALE, 2.50);

        $kpis = PlatformRevenueKpis::compute(null, null);

        // Antes: 7,00 + 2,50 = 9,50 (a reserva pendente sumia). Certo: 7 + 3 + 2,50.
        $this->assertSame(12.5, $kpis['faturamento_taxas_cobradas']);
    }

    public function test_custo_de_saque_considera_saques_pagos(): void
    {
        $cred = GatewayCredential::query()->firstOrNew(['tenant_id' => null, 'gateway_slug' => 'woovi']);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials(['app_id' => 'x', 'woovi_admin_fee_payout_brl' => '1.50']);
        $cred->save();

        $seller = $this->seller();
        foreach (['paid' => 2, 'pending' => 1, 'failed' => 1] as $status => $n) {
            for ($i = 0; $i < $n; $i++) {
                Withdrawal::query()->create([
                    'tenant_id' => $seller->id, 'user_id' => $seller->id, 'amount' => 100, 'fee_amount' => 0,
                    'net_amount' => 100, 'bucket' => 'pix', 'status' => $status, 'payout_provider' => 'woovi',
                ]);
            }
        }

        $kpis = PlatformRevenueKpis::compute(null, null);

        // Só os 2 pagos geram custo (antes filtrava "completed", que não existe, e dava 0).
        $this->assertSame(3.0, $kpis['faturamento_custo_adquirente_saques']);
        $this->assertSame(-3.0, $kpis['faturamento_liquido']);
    }
}

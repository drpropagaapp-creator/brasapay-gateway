<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PlatformAuditLog;
use App\Models\TenantWallet;
use App\Models\TreasuryDiscrepancy;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\Treasury\TreasuryReconciliationService;
use Tests\TestCase;

class TreasuryReconciliationTest extends TestCase
{
    private function seller(): User
    {
        $u = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $u->forceFill(['tenant_id' => $u->id])->save();

        return $u;
    }

    private function order(User $seller, string $status = 'completed', float $amount = 100): Order
    {
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $order = Order::create([
            'tenant_id' => $seller->id, 'user_id' => $seller->id, 'product_id' => $product->id,
            'status' => $status, 'amount' => $amount, 'email' => 'c@x.com', 'payment_method' => 'pix', 'metadata' => [],
        ]);
        $order->forceFill(['created_at' => now()->subHours(2)])->save();

        return $order;
    }

    private function credit(Order $order, float $gross, string $type = WalletTransaction::TYPE_CREDIT_SALE, array $meta = []): WalletTransaction
    {
        return WalletTransaction::query()->create([
            'tenant_id' => $order->tenant_id, 'order_id' => $order->id, 'bucket' => 'pix', 'type' => $type,
            'amount_gross' => $gross, 'amount_fee' => 1, 'amount_net' => $gross - 1, 'meta' => $meta,
        ]);
    }

    private function run(): array
    {
        return (new TreasuryReconciliationService)->run();
    }

    public function test_pedido_pago_sem_credito_e_idempotente_e_encerra_sozinho(): void
    {
        $order = $this->order($this->seller());

        $this->run();
        $this->run();

        $rows = TreasuryDiscrepancy::query()->where('kind', 'order_paid_without_credit')->get();
        $this->assertCount(1, $rows, 'A mesma ocorrência não duplica entre execuções.');
        $this->assertSame('critical', $rows[0]->severity);
        $this->assertSame(10000, $rows[0]->amount_cents);

        $this->credit($order, 100);
        $this->run();

        $row = $rows[0]->fresh();
        $this->assertSame(TreasuryDiscrepancy::STATUS_RESOLVED, $row->status);
        $this->assertStringContainsString('automaticamente', (string) $row->resolution_note);
    }

    public function test_conciliacao_nao_movimenta_saldo(): void
    {
        $seller = $this->seller();
        TenantWallet::query()->create(['tenant_id' => $seller->id, 'available_pix' => 50, 'pending_pix' => 0, 'available_balance' => 50, 'pending_balance' => 0]);
        $order = $this->order($seller);
        $this->credit($order, 150); // crédito acima do pedido
        $txBefore = WalletTransaction::query()->count();

        $this->run();

        $this->assertSame(1, TreasuryDiscrepancy::query()->where('kind', 'credit_above_order')->where('amount_cents', 5000)->count());
        $this->assertSame($txBefore, WalletTransaction::query()->count());
        $this->assertSame(50.0, (float) TenantWallet::query()->where('tenant_id', $seller->id)->value('available_pix'));
    }

    public function test_taxa_de_liberacao_nao_conta_credito_em_dobro(): void
    {
        $order = $this->order($this->seller());
        $pending = $this->credit($order, 100, WalletTransaction::TYPE_CREDIT_SALE_PENDING, ['released_at' => now()->toIso8601String()]);
        $this->credit($order, 100, WalletTransaction::TYPE_CREDIT_SALE, ['from_pending_wallet_transaction_id' => $pending->id]);

        $this->run();

        $this->assertSame(0, TreasuryDiscrepancy::query()->where('kind', 'credit_above_order')->count());
    }

    public function test_saques_ambiguos_e_falhos(): void
    {
        $seller = $this->seller();
        $mk = fn (array $a) => Withdrawal::query()->create(array_merge([
            'tenant_id' => $seller->id, 'user_id' => $seller->id, 'amount' => 80, 'fee_amount' => 0, 'net_amount' => 80, 'bucket' => 'pix',
        ], $a));
        $exhausted = $mk(['status' => 'processing', 'payout_provider' => 'woovi', 'payout_external_id' => 'tx1', 'payout_meta' => ['reconcile_exhausted' => true]]);
        $failed = $mk(['status' => 'failed', 'payout_provider' => 'woovi']);
        WalletTransaction::query()->create([
            'tenant_id' => $seller->id, 'withdrawal_id' => $failed->id, 'bucket' => 'pix', 'type' => WalletTransaction::TYPE_WITHDRAWAL_REQUEST,
            'amount_gross' => 80, 'amount_fee' => 0, 'amount_net' => 80,
        ]);
        $paidNoDebit = $mk(['status' => 'paid', 'payout_provider' => 'woovi']);

        $this->run();

        $this->assertSame(TreasuryDiscrepancy::STATUS_INVESTIGATING, TreasuryDiscrepancy::query()->where('withdrawal_id', $exhausted->id)->value('status'));
        $this->assertSame('withdrawal_failed_without_refund', TreasuryDiscrepancy::query()->where('withdrawal_id', $failed->id)->value('kind'));
        $this->assertSame('withdrawal_paid_without_debit', TreasuryDiscrepancy::query()->where('withdrawal_id', $paidNoDebit->id)->value('kind'));
    }

    public function test_med_aberto_fora_de_disputa(): void
    {
        $order = $this->order($this->seller(), 'refunded');
        WalletTransaction::query()->create([
            'tenant_id' => $order->tenant_id, 'order_id' => $order->id, 'bucket' => 'pix', 'type' => WalletTransaction::TYPE_MED_HOLD,
            'amount_gross' => 100, 'amount_fee' => 0, 'amount_net' => 90, 'meta' => ['held_net' => 90, 'hold_mode' => 'available_to_pending'],
        ]);

        $this->run();

        $this->assertSame(9000, (int) TreasuryDiscrepancy::query()->where('kind', 'med_hold_open_outside_dispute')->value('amount_cents'));
    }

    public function test_painel_so_para_admin_e_mudanca_de_status_exige_justificativa(): void
    {
        $this->order($this->seller());
        $this->run();
        $row = TreasuryDiscrepancy::query()->firstOrFail();
        $admin = User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN]);
        $seller = $this->seller();

        $this->assertNotSame(200, $this->actingAs($seller)->get('/plataforma/tesouraria')->status(), 'Seller não acessa a tesouraria.');
        $this->actingAs($admin)->get('/plataforma/tesouraria')->assertOk();

        $this->actingAs($admin)->put("/plataforma/tesouraria/divergencias/{$row->id}", ['status' => 'ignored', 'note' => ''])
            ->assertSessionHasErrors('note');
        $this->actingAs($admin)->put("/plataforma/tesouraria/divergencias/{$row->id}", ['status' => 'ignored', 'note' => 'Pedido de teste do QA'])
            ->assertRedirect();

        $this->assertSame(TreasuryDiscrepancy::STATUS_IGNORED, $row->fresh()->status);
        $this->assertSame(1, PlatformAuditLog::query()->where('action', 'platform.treasury.discrepancy_status')->count());

        // Ignorada continua ignorada mesmo que a ocorrência persista.
        $this->run();
        $this->assertSame(TreasuryDiscrepancy::STATUS_IGNORED, $row->fresh()->status);
    }
}

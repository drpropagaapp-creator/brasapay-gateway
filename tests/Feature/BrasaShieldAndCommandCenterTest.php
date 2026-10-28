<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Risk\BrasaShieldService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BrasaShieldAndCommandCenterTest extends TestCase
{
    private function seller(array $attrs = []): User
    {
        $u = User::factory()->create(array_merge(['role' => User::ROLE_INFOPRODUTOR], $attrs));
        $u->forceFill(['tenant_id' => $u->id])->save();

        return $u;
    }

    private function order(User $seller, array $attrs = []): Order
    {
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        return Order::create(array_merge([
            'tenant_id' => $seller->id, 'user_id' => $seller->id, 'product_id' => $product->id, 'status' => 'completed',
            'amount' => 97, 'email' => 'comprador@x.com', 'payment_method' => 'pix', 'metadata' => [], 'customer_ip' => '10.0.0.1',
        ], $attrs));
    }

    public function test_compras_em_rajada_e_historico_de_reembolso_geram_risco_explicavel_sem_bloquear(): void
    {
        $seller = $this->seller(['created_at' => now()->subYear()]);
        $this->order($seller, ['status' => 'refunded', 'created_at' => now()->subDays(10)]);
        $this->order($seller, ['status' => 'disputed', 'created_at' => now()->subDays(5)]);
        $this->order($seller);
        $this->order($seller);
        $last = $this->order($seller);

        $r = app(BrasaShieldService::class)->assessOrder($last);

        $codes = array_column($r->signals, 'code');
        $this->assertContains('velocity_email', $codes);
        $this->assertContains('buyer_refund_history', $codes);
        $this->assertSame('high', $r->level);
        $this->assertSame('review', $r->suggested_action);
        $this->assertSame('completed', $last->fresh()->status, 'Observação: nada é bloqueado.');
    }

    public function test_compra_comum_e_risco_baixo_e_reavaliar_nao_duplica(): void
    {
        $seller = $this->seller(['created_at' => now()->subYear()]);
        $order = $this->order($seller, ['email' => 'unico@x.com', 'customer_ip' => '10.9.9.9']);

        $shield = app(BrasaShieldService::class);
        $shield->assessOrder($order);
        $r = $shield->assessOrder($order);

        $this->assertSame('low', $r->level);
        $this->assertSame(1, RiskAssessment::query()->where('subject_type', 'order')->where('subject_id', (string) $order->id)->count());
    }

    public function test_saque_de_conta_nova_entra_em_observacao(): void
    {
        $seller = $this->seller(['created_at' => now()->subDays(2)]);
        $w = Withdrawal::query()->create([
            'tenant_id' => $seller->id, 'user_id' => $seller->id, 'amount' => 800, 'fee_amount' => 0, 'net_amount' => 800, 'bucket' => 'pix', 'status' => 'pending',
        ]);

        $r = app(BrasaShieldService::class)->assessWithdrawal($w);

        $this->assertContains('withdrawal_new_account', array_column($r->signals, 'code'));
        $this->assertSame('pending', $w->fresh()->status);
    }

    public function test_command_center_e_risco_so_para_admin(): void
    {
        Cache::flush();
        $admin = User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN]);
        $seller = $this->seller();

        $this->actingAs($admin)->get('/plataforma/central')->assertOk();
        $this->actingAs($admin)->get('/plataforma/risco')->assertOk();
        $this->assertNotSame(200, $this->actingAs($seller)->get('/plataforma/central')->status());
        $this->assertNotSame(200, $this->actingAs($seller)->get('/plataforma/risco')->status());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionMetricsService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** BrasaPay 4.0 / Onda 2 — Brasa Subscriptions. */
class BrasaSubscriptionsTest extends TestCase
{
    private function seller(): User
    {
        $u = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'kyc_status' => User::KYC_APPROVED]);
        $u->forceFill(['tenant_id' => $u->id])->save();

        return $u;
    }

    private function sub(User $seller, ?User $buyer, string $interval, float $price, string $status = 'active', array $extra = []): Subscription
    {
        $buyer ??= User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id, 'type' => Product::TYPE_AREA_MEMBROS, 'billing_type' => Product::BILLING_SUBSCRIPTION]);
        $plan = SubscriptionPlan::create(['product_id' => $product->id, 'name' => $interval, 'price' => $price, 'currency' => 'BRL',
            'interval' => $interval, 'checkout_slug' => 'p'.uniqid(), 'position' => 1]);

        return Subscription::create(array_merge([
            'tenant_id' => $seller->id, 'user_id' => $buyer->id, 'product_id' => $product->id, 'subscription_plan_id' => $plan->id,
            'status' => $status, 'current_period_start' => now()->subDays(10)->startOfDay(), 'current_period_end' => now()->addDays(20)->startOfDay(),
        ], $extra));
    }

    public function test_mrr_mensaliza_por_intervalo_e_ignora_vitalicio(): void
    {
        $s = $this->seller();
        $this->sub($s, null, 'monthly', 100);
        $this->sub($s, null, 'annual', 1200);      // 100/mês (antes contava 1200)
        $this->sub($s, null, 'quarterly', 300);    // 100/mês
        $this->sub($s, null, 'lifetime', 997);     // fora do MRR
        $this->sub($s, null, 'monthly', 50, 'cancelled');

        $m = app(SubscriptionMetricsService::class)->metrics($s->id, null);

        $this->assertEqualsWithDelta(300.0, $m['mrr'], 0.05);
        $this->assertEqualsWithDelta(3600.0, $m['arr'], 0.6);
        $this->assertSame(4, $m['active']);
    }

    public function test_assinante_cancela_mantem_acesso_e_nao_e_cobrado_e_encerra_no_fim(): void
    {
        $s = $this->seller();
        $buyer = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $sub = $this->sub($s, $buyer, 'monthly', 49.9);
        $other = User::factory()->create(['role' => User::ROLE_CLIENTE]);

        $this->actingAs($other)->post("/painel-cliente/assinaturas/{$sub->id}/cancelar", ['reason' => 'price'])->assertNotFound();
        $this->actingAs($buyer)->post("/painel-cliente/assinaturas/{$sub->id}/cancelar", ['reason' => 'price', 'note' => 'caro'])->assertRedirect();

        $sub->refresh();
        $this->assertTrue($sub->cancel_at_period_end);
        $this->assertSame(Subscription::STATUS_ACTIVE, $sub->status, 'Acesso continua até o fim do período.');
        $m = app(SubscriptionMetricsService::class)->metrics($s->id, null);
        $this->assertSame(1, $m['cancel_requested']);
        $this->assertSame(['price' => 1], $m['cancel_reasons_90d']->all());

        // Fim do período: encerra direto (sem virar "pagamento pendente" e sem lembrete de cobrança).
        $sub->update(['current_period_end' => now()->subDay()->startOfDay()]);
        Artisan::call('subscriptions:expire-due');
        $this->assertSame(Subscription::STATUS_CANCELLED, $sub->fresh()->status);
    }

    public function test_reativar_desfaz_o_pedido(): void
    {
        $s = $this->seller();
        $buyer = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $sub = $this->sub($s, $buyer, 'monthly', 30, 'active', ['cancel_at_period_end' => true, 'cancellation_reason' => 'not_using']);

        $this->actingAs($buyer)->post("/painel-cliente/assinaturas/{$sub->id}/reativar")->assertRedirect();

        $this->assertFalse($sub->fresh()->cancel_at_period_end);
        $this->actingAs($buyer)->get('/painel-cliente/assinaturas')->assertOk();
    }
}

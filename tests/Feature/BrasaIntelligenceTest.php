<?php

namespace Tests\Feature;

use App\Models\AdSpend;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\User;
use App\Services\Intelligence\IntelligenceService;
use Tests\TestCase;

/** BrasaPay 4.0 / Onda 2 — Brasa Intelligence. */
class BrasaIntelligenceTest extends TestCase
{
    private function seller(): User
    {
        $u = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'kyc_status' => User::KYC_APPROVED]);
        $u->forceFill(['tenant_id' => $u->id])->save();

        return $u;
    }

    private function order(User $seller, $product, string $email, string $status, string $amount, int $daysAgo = 1, array $meta = [], string $method = 'pix'): Order
    {
        $o = Order::create(['tenant_id' => $seller->id, 'user_id' => $seller->id, 'product_id' => $product->id, 'status' => $status,
            'amount' => $amount, 'email' => $email, 'payment_method' => $method, 'metadata' => $meta]);
        $o->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();

        return $o;
    }

    private function spend(User $seller, string $source, ?string $campaign, int $cents): void
    {
        AdSpend::query()->create(['tenant_id' => $seller->id, 'spent_on' => now()->subDay()->toDateString(), 'source' => $source, 'campaign' => $campaign, 'amount_cents' => $cents]);
    }

    private function report(User $seller): array
    {
        return app(IntelligenceService::class)->report($seller->id, null, now()->subDays(29)->startOfDay(), now()->endOfDay());
    }

    public function test_receita_so_confirmada_roas_e_cac_so_com_custo(): void
    {
        $s = $this->seller();
        $p = $this->createTestProduct(['tenant_id' => $s->id]);

        $fb = $this->order($s, $p, 'nova@x.com', 'completed', '300.00');
        CheckoutSession::query()->create(['tenant_id' => $s->id, 'product_id' => $p->id, 'checkout_slug' => 'x', 'session_token' => 'fb1',
            'step' => CheckoutSession::STEP_CONVERTED, 'order_id' => $fb->id, 'utm_source' => 'Facebook', 'utm_campaign' => 'BF']);
        $this->order($s, $p, 'antiga@x.com', 'completed', '100.00', 1, ['utm_source' => 'google']);
        $this->order($s, $p, 'antiga@x.com', 'completed', '50.00', 60); // compra antes do período: não é cliente novo
        $this->order($s, $p, 'p@x.com', 'pending', '999.00');
        $this->order($s, $p, 'r@x.com', 'refunded', '777.00');
        $this->spend($s, 'facebook', 'bf', 10000);

        $r = $this->report($s);
        $this->assertSame(40000, $r['totals']['revenue_cents']);
        $this->assertSame(2, $r['totals']['paid_orders']);
        $this->assertSame(1, $r['totals']['new_customers']);

        $camp = collect($r['campaigns'])->keyBy(fn ($c) => $c['source'].'|'.$c['campaign']);
        $this->assertSame('3.00', $camp['facebook|bf']['roas']);
        $this->assertSame(10000, $camp['facebook|bf']['cac_cents']);
        $this->assertNull($camp['google|']['roas'], 'Sem custo informado não há ROAS.');
        $this->assertNull($camp['google|']['cac_cents']);

        $pix = collect($r['payments'])->firstWhere('method', 'pix');
        $this->assertSame(4, $pix['attempts']);
        $this->assertSame(3, $pix['approved']); // 2 pagos + 1 reembolsado
    }

    public function test_alertas_com_evidencia(): void
    {
        $s = $this->seller();
        $p = $this->createTestProduct(['tenant_id' => $s->id]);
        $this->order($s, $p, 'a@x.com', 'completed', '30.00', 1, ['utm_source' => 'tiktok', 'utm_campaign' => 'teste']);

        $titles = collect($this->report($s)['insights'])->pluck('title');
        $this->assertContains('ROAS e CAC precisam do custo dos anúncios', $titles);

        $this->spend($s, 'tiktok', 'teste', 10000);
        $insights = collect($this->report($s)['insights']);
        $bad = $insights->firstWhere('title', 'Campanha gastando mais do que fatura');
        $this->assertNotNull($bad);
        $this->assertStringContainsString('ROAS 0.30', $bad['detail']);
    }

    public function test_custos_isolados_por_tenant_e_valor_em_centavos(): void
    {
        $s = $this->seller();
        $other = $this->seller();
        $this->actingAs($s)->post('/inteligencia/custos', ['spent_on' => now()->toDateString(), 'source' => 'Facebook', 'campaign' => '', 'amount' => '150,50'])->assertRedirect();
        $spend = AdSpend::query()->where('tenant_id', $s->id)->firstOrFail();
        $this->assertSame(15050, $spend->amount_cents);
        $this->assertSame('facebook', $spend->source);
        $this->assertNull($spend->campaign);

        $this->actingAs($other)->delete("/inteligencia/custos/{$spend->id}")->assertNotFound();
        $this->assertSame(0, $this->report($other)['totals']['spend_cents']);
        $this->actingAs($s)->get('/inteligencia')->assertOk();
        $this->actingAs($s)->delete("/inteligencia/custos/{$spend->id}")->assertRedirect();
        $this->assertSame(0, AdSpend::query()->count());
    }
}

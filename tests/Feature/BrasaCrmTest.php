<?php

namespace Tests\Feature;

use App\Models\CrmContactMeta;
use App\Models\Order;
use App\Models\User;
use App\Services\Crm\CrmService;
use Tests\TestCase;

/** BrasaPay 4.0 / Onda 2 — Brasa CRM. */
class BrasaCrmTest extends TestCase
{
    private function seller(): User
    {
        $u = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'kyc_status' => User::KYC_APPROVED]);
        $u->forceFill(['tenant_id' => $u->id])->save();

        return $u;
    }

    private function order(User $seller, string $email, string $status, float $amount, int $daysAgo = 1, $product = null): Order
    {
        $product ??= $this->createTestProduct(['tenant_id' => $seller->id]);
        $o = Order::create(['tenant_id' => $seller->id, 'user_id' => $seller->id, 'product_id' => $product->id, 'status' => $status,
            'amount' => $amount, 'email' => $email, 'payment_method' => 'pix', 'metadata' => []]);
        $o->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();

        return $o;
    }

    private function emails(int $tenantId, string $segment): array
    {
        return collect(app(CrmService::class)->contacts($tenantId, null, $segment)->items())->pluck('email')->sort()->values()->all();
    }

    public function test_agrega_por_email_e_segmenta(): void
    {
        $s = $this->seller();
        $this->order($s, 'Ana@x.com', 'completed', 600, 5);
        $this->order($s, 'ana@x.com', 'completed', 500, 2);
        $this->order($s, 'bia@x.com', 'completed', 97, 120);
        $this->order($s, 'caio@x.com', 'pending', 47, 1);

        $all = collect(app(CrmService::class)->contacts($s->id, null)->items())->keyBy('email');
        $this->assertSame(2, $all['ana@x.com']['paid_orders']);
        $this->assertSame(1100.0, $all['ana@x.com']['total_paid']);

        $this->assertSame(['ana@x.com'], $this->emails($s->id, 'repeat'));
        $this->assertSame(['ana@x.com'], $this->emails($s->id, 'high_value'));
        $this->assertSame(['bia@x.com'], $this->emails($s->id, 'inactive'));
        $this->assertSame(['caio@x.com'], $this->emails($s->id, 'unpaid'));
    }

    public function test_seller_nao_ve_compras_feitas_com_outro_seller(): void
    {
        $a = $this->seller();
        $b = $this->seller();
        $this->order($a, 'mesmo@x.com', 'completed', 100);
        $this->order($b, 'mesmo@x.com', 'completed', 9999);
        $key = rtrim(strtr(base64_encode('mesmo@x.com'), '+/', '-_'), '=');

        $contact = app(CrmService::class)->contact($a->id, null, 'mesmo@x.com');
        $this->assertSame(100.0, $contact['total_paid']);
        $this->assertCount(1, $contact['orders']);

        $this->actingAs($a)->get('/crm')->assertOk();
        $this->actingAs($a)->get("/crm/contato/{$key}")->assertOk();
        // Contato que só existe em outro seller não abre.
        $only = rtrim(strtr(base64_encode('so-do-b@x.com'), '+/', '-_'), '=');
        $this->order($b, 'so-do-b@x.com', 'completed', 50);
        $this->actingAs($a)->get("/crm/contato/{$only}")->assertNotFound();
        $this->actingAs($a)->put("/crm/contato/{$only}", ['tags' => ['x'], 'notes' => 'y'])->assertNotFound();
    }

    public function test_tags_e_notas(): void
    {
        $s = $this->seller();
        $this->order($s, 'vip@x.com', 'completed', 300);
        $key = rtrim(strtr(base64_encode('vip@x.com'), '+/', '-_'), '=');

        $this->actingAs($s)->put("/crm/contato/{$key}", ['tags' => ['VIP', 'VIP', '<b>mentoria</b>'], 'notes' => 'Ligar na sexta'])->assertRedirect();

        $meta = CrmContactMeta::query()->where('tenant_id', $s->id)->where('email', 'vip@x.com')->firstOrFail();
        $this->assertSame(['VIP', 'mentoria'], $meta->tags);
        $this->assertSame('Ligar na sexta', $meta->notes);
    }

    public function test_estagios_resumo_funil_e_contato_sem_email(): void
    {
        $s = $this->seller();
        $this->order($s, 'lead@x.com', 'pending', 97, 2);
        $this->order($s, 'novo@x.com', 'completed', 97, 5);
        $this->order($s, 'rec@x.com', 'completed', 50, 20);
        $this->order($s, 'rec@x.com', 'completed', 50, 3);
        $this->order($s, 'vip@x.com', 'completed', 1500, 10);
        $this->order($s, 'sumiu@x.com', 'completed', 2000, 120);
        $this->order($s, 'tg7158265228@sem-email.apextrack', 'completed', 6, 1);

        $crm = app(CrmService::class);
        $sum = $crm->summary($s->id, null);
        $this->assertSame(1, $sum['stages']['lead']['count']);
        $this->assertSame(2, $sum['stages']['novo']['count']);
        $this->assertSame(1, $sum['stages']['recorrente']['count']);
        $this->assertSame(1, $sum['stages']['alto_valor']['count']);
        $this->assertSame(1, $sum['stages']['em_risco']['count'], 'Quem pagou muito mas sumiu há 90+ dias está em risco.');
        $this->assertSame(6, $sum['buyers']);
        $this->assertSame(3653.0, $sum['revenue']);

        $risk = $crm->contacts($s->id, null, 'all', null, null, 30, 'em_risco')->items();
        $this->assertSame(['sumiu@x.com'], array_column($risk, 'email'));
        $this->assertSame(['lead', 'novo', 'recorrente', 'alto_valor', 'em_risco'], array_keys($crm->pipeline($s->id, null)));
        $this->assertSame('Telegram 7158265228', CrmService::placeholderLabel('tg7158265228@sem-email.apextrack'));
        $this->assertSame('PixGo', CrmService::placeholderLabel('pixgo+abc@pixgo.local'));
        $this->assertNull(CrmService::placeholderLabel('maria@gmail.com'));

        $trail = $crm->trails($s->id, null, ['rec@x.com']);
        $this->assertCount(2, $trail['rec@x.com']);
        $this->actingAs($s)->get('/crm?view=funil')->assertOk();
        $this->actingAs($s)->get('/crm?stage=em_risco')->assertOk();
    }
}

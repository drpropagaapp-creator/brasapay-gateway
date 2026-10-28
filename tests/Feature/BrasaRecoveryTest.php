<?php

namespace Tests\Feature;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\RecoveryMessage;
use App\Models\RecoveryOptOut;
use App\Models\User;
use App\Services\Recovery\BrasaRecoveryService;
use App\Services\TenantMailConfigService;
use App\Support\RecoveryEmailSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** BrasaPay 4.0 / Onda 2 — Brasa Recovery por e-mail. */
class BrasaRecoveryTest extends TestCase
{
    /** @var list<array{email:string,subject:string,html:string}> */
    public static array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$sent = [];
        // Meio-dia em São Paulo: fora do horário silencioso padrão.
        Carbon::setTestNow(Carbon::parse('2026-10-29 12:00:00', 'America/Sao_Paulo')->setTimezone(config('app.timezone')));
        $this->app->bind(BrasaRecoveryService::class, fn ($app) => new class($app->make(TenantMailConfigService::class)) extends BrasaRecoveryService
        {
            protected function tenantCanSend(int $tenantId): bool
            {
                return true;
            }

            protected function send(int $tenantId, string $email, string $subject, string $html): void
            {
                BrasaRecoveryTest::$sent[] = compact('email', 'subject', 'html');
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seller(bool $enabled = true): User
    {
        $u = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'kyc_status' => User::KYC_APPROVED]);
        $u->forceFill(['tenant_id' => $u->id])->save();
        $cfg = RecoveryEmailSettings::defaults();
        $cfg['enabled'] = $enabled;
        RecoveryEmailSettings::save($u->id, $cfg);

        return $u;
    }

    private function abandoned(User $seller, string $email, int $minutesAgo): CheckoutSession
    {
        $product = $this->createTestProduct(['tenant_id' => $seller->id, 'name' => 'Curso X', 'checkout_slug' => 'cx'.substr(md5((string) microtime(true)), 0, 6)]);

        return CheckoutSession::query()->create([
            'tenant_id' => $seller->id, 'product_id' => $product->id, 'session_token' => bin2hex(random_bytes(8)),
            'checkout_slug' => $product->checkout_slug ?? 'x', 'step' => CheckoutSession::STEP_FORM_FILLED, 'email' => $email, 'name' => 'Maria Souza',
            'form_filled_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    private function process(): array
    {
        return app(BrasaRecoveryService::class)->run();
    }

    public function test_carrinho_abandonado_envia_o_passo_vencido_uma_vez_com_descadastro(): void
    {
        $this->abandoned($this->seller(), 'maria@x.com', 40);

        $this->process();
        $this->process();

        $this->assertCount(1, self::$sent);
        $this->assertSame('maria@x.com', self::$sent[0]['email']);
        $this->assertStringContainsString('Maria', self::$sent[0]['subject']);
        $this->assertStringContainsString('/recuperacao/descadastrar/', self::$sent[0]['html']);
        $this->assertSame(1, RecoveryMessage::query()->where('status', 'sent')->count());
    }

    public function test_desligado_descadastrado_ou_ja_comprou_nao_recebe(): void
    {
        $this->abandoned($this->seller(false), 'a@x.com', 40);

        $seller = $this->seller();
        $this->abandoned($seller, 'b@x.com', 40);
        RecoveryOptOut::query()->create(['tenant_id' => $seller->id, 'email' => 'b@x.com', 'channel' => 'email']);

        $s = $this->abandoned($seller, 'c@x.com', 40);
        Order::create(['tenant_id' => $seller->id, 'user_id' => $seller->id, 'product_id' => $s->product_id, 'status' => 'completed',
            'amount' => 97, 'email' => 'C@x.com', 'payment_method' => 'pix', 'metadata' => []]);

        $this->process();

        $this->assertSame([], self::$sent);
        $this->assertSame(['already_bought', 'opted_out'], RecoveryMessage::query()->where('status', 'skipped')->orderBy('skip_reason')->pluck('skip_reason')->all());
    }

    public function test_horario_silencioso_adia_e_passo_muito_atrasado_nao_dispara(): void
    {
        $seller = $this->seller();
        $cfg = RecoveryEmailSettings::forTenant($seller->id);
        $cfg['quiet_start'] = 11;
        $cfg['quiet_end'] = 14;
        RecoveryEmailSettings::save($seller->id, $cfg);
        $this->abandoned($seller, 'q@x.com', 40);

        $this->process();
        $this->assertSame([], self::$sent);
        $this->assertSame(0, RecoveryMessage::query()->count(), 'Adiado, não descartado.');

        // Ativado agora com carrinho de 5 h atrás (passo de 30 min venceu há muito): não envia.
        $other = $this->seller();
        $this->abandoned($other, 'velho@x.com', 300);
        Carbon::setTestNow(Carbon::parse('2026-10-29 15:00:00', 'America/Sao_Paulo')->setTimezone(config('app.timezone')));
        $this->process();
        $this->assertSame([], array_values(array_filter(self::$sent, fn ($m) => $m['email'] === 'velho@x.com')));
    }

    public function test_pix_pago_depois_do_lembrete_conta_como_recuperado(): void
    {
        $seller = $this->seller();
        $product = $this->createTestProduct(['tenant_id' => $seller->id, 'checkout_slug' => 'pixrec1']);
        $order = Order::create(['tenant_id' => $seller->id, 'user_id' => $seller->id, 'product_id' => $product->id, 'status' => 'pending',
            'amount' => 197, 'email' => 'pix@x.com', 'payment_method' => 'pix', 'metadata' => []]);
        $order->forceFill(['created_at' => now()->subMinutes(25)])->saveQuietly();

        $this->process();
        $this->assertCount(1, self::$sent, json_encode(['msgs' => RecoveryMessage::query()->get()->toArray(), 'order' => $order->fresh()->only(['status','payment_method','created_at','email']), 'slug' => $product->fresh()->checkout_slug, 'now' => now()->toIso8601String()]));
        $this->assertStringContainsString('R$ 197,00', self::$sent[0]['subject']);

        Carbon::setTestNow(now()->addMinutes(10));
        $order->update(['status' => 'completed']);
        $this->process();

        $msg = RecoveryMessage::query()->firstOrFail();
        $this->assertSame((string) $order->id, $msg->recovered_order_id);
        $this->assertSame(19700, $msg->recovered_amount_cents);
    }

    public function test_nunca_usa_o_smtp_da_plataforma_para_texto_do_seller(): void
    {
        \App\Models\Setting::set('smtp_host', 'smtp.plataforma.test', null);
        $this->abandoned($this->seller(), 'alvo@x.com', 40);

        // Serviço real (sem o dublê do setUp): seller sem e-mail próprio não envia nada.
        (new BrasaRecoveryService(app(TenantMailConfigService::class)))->run();

        $this->assertSame(0, RecoveryMessage::query()->count());
    }

    public function test_link_de_descadastro_exige_assinatura(): void
    {
        $seller = $this->seller();
        $email = rtrim(strtr(base64_encode('z@x.com'), '+/', '-_'), '=');

        $this->get("/recuperacao/descadastrar/{$seller->id}/{$email}")->assertStatus(403);
        $this->get(URL::signedRoute('recovery.optout', ['tenant' => $seller->id, 'email' => $email]))->assertOk();

        $this->assertTrue(RecoveryOptOut::isOptedOut($seller->id, 'z@x.com'));
    }

    public function test_pagina_do_seller(): void
    {
        $seller = $this->seller();
        $this->actingAs($seller)->get('/recuperacao')->assertOk();
        $this->actingAs($seller)->put('/recuperacao', array_merge(RecoveryEmailSettings::defaults(), ['enabled' => true]))->assertRedirect();
        $this->assertTrue(RecoveryEmailSettings::forTenant($seller->id)['enabled']);
    }
}

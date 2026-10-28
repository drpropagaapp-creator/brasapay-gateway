<?php

namespace Tests\Feature;

use App\Models\OpsAlert;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** BrasaPay 4.0 / Onda 3 — Command Center avançado (central de alertas e ações seguras). */
class BrasaCommandCenterTest extends TestCase
{
    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN]);
    }

    private function disputedOrder(): Order
    {
        $s = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'kyc_status' => User::KYC_APPROVED]);
        $s->forceFill(['tenant_id' => $s->id])->save();
        $p = $this->createTestProduct(['tenant_id' => $s->id]);

        return Order::create(['tenant_id' => $s->id, 'user_id' => $s->id, 'product_id' => $p->id, 'status' => 'disputed',
            'amount' => '50.00', 'email' => 'c@x.com', 'payment_method' => 'pix', 'metadata' => []]);
    }

    private function failedJob(string $class): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'redis', 'queue' => 'default', 'failed_at' => now(),
            'payload' => json_encode(['uuid' => $uuid, 'displayName' => $class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['commandName' => $class, 'command' => 'x']]),
            'exception' => "RuntimeException: falhou\n#0 trace"]);

        return $uuid;
    }

    public function test_alerta_abre_com_dado_real_e_resolve_sozinho(): void
    {
        Cache::put('schedule_heartbeat', now()->toIso8601String(), 300);
        $order = $this->disputedOrder();
        $this->artisan('ops:evaluate-alerts')->assertSuccessful();
        $alert = OpsAlert::query()->where('key', 'payments.med')->firstOrFail();
        $this->assertSame('open', $alert->status);

        $this->artisan('ops:evaluate-alerts')->assertSuccessful();
        $this->assertSame(1, OpsAlert::query()->where('key', 'payments.med')->count(), 'Mesmo alerta não duplica.');

        $order->update(['status' => 'refunded']);
        $this->artisan('ops:evaluate-alerts')->assertSuccessful();
        $this->assertSame('resolved', $alert->fresh()->status);
    }

    public function test_reconhecer_exige_nota_e_so_admin_acessa(): void
    {
        Cache::put('schedule_heartbeat', now()->toIso8601String(), 300);
        $this->disputedOrder();
        $this->artisan('ops:evaluate-alerts');
        $alert = OpsAlert::query()->where('key', 'payments.med')->firstOrFail();

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $this->assertNotSame(200, $this->actingAs($seller)->get('/plataforma/central')->status());

        $admin = $this->admin();
        $this->actingAs($admin)->get('/plataforma/central')->assertOk();
        $this->actingAs($admin)->post("/plataforma/central/alertas/{$alert->id}/reconhecer", ['note' => ''])->assertSessionHasErrors('note');
        $this->actingAs($admin)->post("/plataforma/central/alertas/{$alert->id}/reconhecer", ['note' => 'Time de risco analisando'])->assertRedirect();
        $this->assertSame('acknowledged', $alert->fresh()->status);
    }

    public function test_so_jobs_sem_efeito_financeiro_sao_reprocessados(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $payment = $this->failedJob(\App\Jobs\ProcessWithdrawalPayoutJob::class);
        $webhook = $this->failedJob(\App\Jobs\DeliverApiWebhookJob::class);

        $this->actingAs($admin)->post("/plataforma/central/jobs/{$payment}/reprocessar")->assertStatus(422);
        $this->assertTrue(DB::table('failed_jobs')->where('uuid', $payment)->exists());

        $this->actingAs($admin)->post("/plataforma/central/jobs/{$webhook}/reprocessar")->assertRedirect();
        $this->assertFalse(DB::table('failed_jobs')->where('uuid', $webhook)->exists(), 'Job seguro volta para a fila.');
    }

    public function test_busca_rapida_por_pedido(): void
    {
        $order = $this->disputedOrder();
        $r = $this->actingAs($this->admin())->getJson('/plataforma/central/busca?q=%23'.$order->id)->assertOk()->json('results');
        $this->assertSame('Pedido', $r[0]['type']);
        $this->assertStringContainsString((string) $order->id, $r[0]['href']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\CheckoutSession;
use App\Models\Experiment;
use App\Models\User;
use App\Services\Experiments\ExperimentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** BrasaPay 4.0 / Onda 2 — Brasa Experiments. */
class BrasaExperimentsTest extends TestCase
{
    private function makeExperiment(): array
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'kyc_status' => User::KYC_APPROVED]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $product = $this->createTestProduct(['tenant_id' => $seller->id, 'checkout_slug' => 'abteste1']);
        $exp = Experiment::query()->create([
            'tenant_id' => $seller->id, 'product_id' => (string) $product->id, 'name' => 'Headline', 'status' => Experiment::STATUS_RUNNING,
            'variants' => [
                ['key' => 'A', 'label' => 'A', 'weight' => 50, 'landing' => null],
                ['key' => 'B', 'label' => 'B', 'weight' => 50, 'landing' => ['mode' => 'blocks', 'blocks' => [['id' => 'h1', 'type' => 'heading', 'props' => ['text' => 'Nova headline']]]]],
            ],
        ]);

        return [$seller, $product, $exp];
    }

    private function newSession($product): CheckoutSession
    {
        return CheckoutSession::query()->create(['tenant_id' => $product->tenant_id, 'product_id' => $product->id, 'checkout_slug' => $product->checkout_slug,
            'session_token' => bin2hex(random_bytes(8)), 'step' => CheckoutSession::STEP_VISIT]);
    }

    public function test_atribuicao_estavel_e_variante_b_troca_a_landing(): void
    {
        [, $product, $exp] = $this->makeExperiment();
        $session = $this->newSession($product);
        $svc = app(ExperimentService::class);

        $first = $svc->apply(Request::create('/c/abteste1'), $product, 'abteste1', $session, ['template' => 'original']);
        $key = DB::table('experiment_assignments')->where('checkout_session_id', $session->id)->value('variant_key');
        for ($i = 0; $i < 5; $i++) {
            $again = $svc->apply(Request::create('/c/abteste1'), $product, 'abteste1', $session, ['template' => 'original']);
            $this->assertSame($first, $again, 'Recarregar não troca a variante.');
        }
        $this->assertSame(1, DB::table('experiment_assignments')->count());
        if ($key === 'B') {
            $this->assertSame('Nova headline', $first['landing']['blocks'][0]['props']['text']);
        } else {
            $this->assertSame('original', $first['template']);
        }

        // Cookie de uma visita anterior mantém a mesma variante numa sessão nova.
        $s2 = $this->newSession($product);
        $svc->apply(Request::create('/c/abteste1', 'GET', [], ['bp_exp_'.$exp->id => 'B']), $product, 'abteste1', $s2, []);
        $this->assertSame('B', DB::table('experiment_assignments')->where('checkout_session_id', $s2->id)->value('variant_key'));
    }

    public function test_nao_declara_vencedora_sem_amostra_e_declara_com_significancia(): void
    {
        $svc = app(ExperimentService::class);
        $small = $svc->verdict(['A' => ['sessions' => 50, 'paid' => 1], 'B' => ['sessions' => 50, 'paid' => 9]]);
        $this->assertSame('collecting', $small['status']);

        $tie = $svc->verdict(['A' => ['sessions' => 1000, 'paid' => 50], 'B' => ['sessions' => 1000, 'paid' => 53]]);
        $this->assertSame('inconclusive', $tie['status']);

        $win = $svc->verdict(['A' => ['sessions' => 1000, 'paid' => 30], 'B' => ['sessions' => 1000, 'paid' => 70]]);
        $this->assertSame('winner', $win['status']);
        $this->assertSame('B', $win['winner']);
    }

    public function test_aplicar_vencedora_exige_significancia_e_outro_seller_nao_acessa(): void
    {
        [$seller, , $exp] = $this->makeExperiment();
        $other = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'kyc_status' => User::KYC_APPROVED]);
        $other->forceFill(['tenant_id' => $other->id])->save();

        $this->actingAs($seller)->get('/experimentos')->assertOk();
        $this->actingAs($seller)->post("/experimentos/{$exp->id}/aplicar-vencedora")->assertStatus(422);
        $this->actingAs($other)->post("/experimentos/{$exp->id}/acao", ['action' => 'pause'])->assertNotFound();
        $this->assertSame(Experiment::STATUS_RUNNING, $exp->fresh()->status);
    }
}

<?php

namespace App\Services\Experiments;

use App\Models\CheckoutSession;
use App\Models\Experiment;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Brasa Experiments — A/B da landing do checkout principal do produto.
 *
 * - Atribuição estável: cookie por experimento (30 dias) + registro por sessão de checkout;
 *   recarregar a página não troca a variante.
 * - Métrica principal: pagamento CONFIRMADO por sessão atribuída (não visualização).
 * - Vencedora só com amostra mínima e teste de duas proporções (p < 0,05); senão "inconclusivo".
 * - Qualquer falha aqui não afeta o checkout: volta para a landing publicada.
 */
class ExperimentService
{
    public const MIN_SESSIONS_PER_VARIANT = 200;

    public const MIN_CONVERSIONS_TOTAL = 20;

    /** Aplica a variante da sessão no config da landing (checkout principal, fora da prévia). */
    public function apply(Request $request, Product $product, string $checkoutSlug, ?CheckoutSession $session, array $config): array
    {
        try {
            if ($session === null || $checkoutSlug !== (string) $product->checkout_slug || ! Schema::hasTable('experiments')) {
                return $config;
            }
            $exp = Experiment::query()->where('product_id', (string) $product->id)->where('status', Experiment::STATUS_RUNNING)->latest('id')->first();
            if ($exp === null) {
                return $config;
            }

            $key = DB::table('experiment_assignments')->where('experiment_id', $exp->id)->where('checkout_session_id', $session->id)->value('variant_key');
            if ($key === null) {
                $cookieKey = (string) $request->cookie('bp_exp_'.$exp->id, '');
                $key = $this->variant($exp, $cookieKey) !== null ? $cookieKey : $this->draw($exp);
                try {
                    DB::table('experiment_assignments')->insert(['experiment_id' => $exp->id, 'variant_key' => $key, 'checkout_session_id' => $session->id, 'created_at' => now()]);
                } catch (QueryException) {
                    $key = DB::table('experiment_assignments')->where('experiment_id', $exp->id)->where('checkout_session_id', $session->id)->value('variant_key') ?? $key;
                }
                Cookie::queue('bp_exp_'.$exp->id, $key, 60 * 24 * 30);
            }

            $variant = $this->variant($exp, $key);
            if (is_array($variant['landing'] ?? null)) {
                $config['template'] = 'landing';
                $config['landing'] = $variant['landing'];
            }
        } catch (\Throwable $e) {
            Log::warning('Experiments: falha ao aplicar variante, usando a landing publicada', ['message' => $e->getMessage()]);
        }

        return $config;
    }

    /** @return array<string, array<string, mixed>> por variante + conclusão estatística */
    public function results(Experiment $exp): array
    {
        $rows = DB::table('experiment_assignments as a')
            ->join('checkout_sessions as s', 's.id', '=', 'a.checkout_session_id')
            ->leftJoin('orders as o', 'o.id', '=', 's.order_id')
            ->where('a.experiment_id', $exp->id)
            ->groupBy('a.variant_key')
            ->selectRaw("a.variant_key,
                COUNT(*) as sessions,
                SUM(CASE WHEN s.step IN ('form_started','form_filled','converted') OR s.order_id IS NOT NULL THEN 1 ELSE 0 END) as started,
                SUM(CASE WHEN s.order_id IS NOT NULL THEN 1 ELSE 0 END) as orders,
                SUM(CASE WHEN o.status = 'completed' THEN 1 ELSE 0 END) as paid,
                SUM(CASE WHEN o.status = 'completed' THEN o.amount ELSE 0 END) as revenue")
            ->get()->keyBy('variant_key');

        $out = [];
        foreach ($exp->variants as $v) {
            $r = $rows->get($v['key']);
            $sessions = (int) ($r->sessions ?? 0);
            $paid = (int) ($r->paid ?? 0);
            $out[$v['key']] = [
                'label' => $v['label'],
                'sessions' => $sessions,
                'started' => (int) ($r->started ?? 0),
                'orders' => (int) ($r->orders ?? 0),
                'paid' => $paid,
                'revenue' => round((float) ($r->revenue ?? 0), 2),
                'conversion' => $sessions > 0 ? round($paid / $sessions, 4) : null,
                'avg_ticket' => $paid > 0 ? round((float) $r->revenue / $paid, 2) : null,
            ];
        }

        return ['variants' => $out, 'verdict' => $this->verdict($out)];
    }

    /** Teste z de duas proporções (A vs B) com amostra mínima. */
    public function verdict(array $variants): array
    {
        $a = $variants['A'] ?? null;
        $b = $variants['B'] ?? null;
        if (! $a || ! $b) {
            return ['status' => 'inconclusive', 'reason' => 'Faltam variantes.'];
        }
        if ($a['sessions'] < self::MIN_SESSIONS_PER_VARIANT || $b['sessions'] < self::MIN_SESSIONS_PER_VARIANT || ($a['paid'] + $b['paid']) < self::MIN_CONVERSIONS_TOTAL) {
            return ['status' => 'collecting', 'reason' => sprintf('Amostra insuficiente: mínimo %d sessões por variante e %d vendas pagas no total.', self::MIN_SESSIONS_PER_VARIANT, self::MIN_CONVERSIONS_TOTAL)];
        }
        $p1 = $a['paid'] / $a['sessions'];
        $p2 = $b['paid'] / $b['sessions'];
        $p = ($a['paid'] + $b['paid']) / ($a['sessions'] + $b['sessions']);
        $se = sqrt($p * (1 - $p) * (1 / $a['sessions'] + 1 / $b['sessions']));
        $z = $se > 0 ? ($p2 - $p1) / $se : 0.0;
        $pValue = 2 * (1 - $this->normalCdf(abs($z)));
        if ($pValue >= 0.05) {
            return ['status' => 'inconclusive', 'p_value' => round($pValue, 4), 'reason' => 'Diferença ainda não é estatisticamente significativa (p ≥ 0,05).'];
        }

        return ['status' => 'winner', 'winner' => $p2 > $p1 ? 'B' : 'A', 'p_value' => round($pValue, 4), 'lift' => $p1 > 0 ? round(($p2 - $p1) / $p1, 4) : null];
    }

    private function draw(Experiment $exp): string
    {
        $total = array_sum(array_map(fn ($v) => max(0, (int) $v['weight']), $exp->variants));
        $roll = $total > 0 ? random_int(1, $total) : 1;
        foreach ($exp->variants as $v) {
            $roll -= max(0, (int) $v['weight']);
            if ($roll <= 0) {
                return $v['key'];
            }
        }

        return $exp->variants[0]['key'];
    }

    private function variant(Experiment $exp, string $key): ?array
    {
        foreach ($exp->variants as $v) {
            if ($v['key'] === $key) {
                return $v;
            }
        }

        return null;
    }

    private function normalCdf(float $x): float
    {
        // Aproximação de Abramowitz-Stegun para a CDF normal padrão.
        $t = 1 / (1 + 0.2316419 * $x);
        $d = 0.3989423 * exp(-$x * $x / 2);
        $p = $d * $t * (0.3193815 + $t * (-0.3565638 + $t * (1.781478 + $t * (-1.821256 + $t * 1.330274))));

        return 1 - $p;
    }
}

<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Services\Payout\GatewayPayoutEconomics;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Desempenho das adquirentes a partir de acquirer_attempts (e, no PIX, da conversão real
 * em pagamento: PIX criado não é venda paga).
 */
class AcquirerHealthService
{
    public const DEFAULT_MIN_SAMPLE = 30;

    /**
     * @return array<string, array<string, array<string, mixed>>> [method][slug] => métricas
     */
    public function stats(int $hours = 24, int $minSample = self::DEFAULT_MIN_SAMPLE): array
    {
        if (! Schema::hasTable('acquirer_attempts')) {
            return [];
        }

        $since = now()->subHours(max(1, $hours));
        $rows = DB::table('acquirer_attempts')
            ->where('created_at', '>=', $since)
            ->groupBy('method', 'gateway_slug', 'outcome')
            ->selectRaw('method, gateway_slug, outcome, COUNT(*) as n')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $m = &$out[$r->method][$r->gateway_slug];
            $m ??= ['attempts' => 0, 'success' => 0, 'declined' => 0, 'technical_error' => 0, 'timeout' => 0];
            $m[$r->outcome] = ($m[$r->outcome] ?? 0) + (int) $r->n;
            $m['attempts'] += (int) $r->n;
            unset($m);
        }

        foreach ($out as $method => $slugs) {
            foreach ($slugs as $slug => $m) {
                $attempts = max(1, $m['attempts']);
                // Recusa do cartão é do banco emissor, não falha da adquirente: fica fora da aprovação técnica.
                $technicalBase = max(1, $m['success'] + $m['technical_error'] + $m['timeout']);
                $latency = $this->latencyPercentiles($slug, $method, $since);
                $out[$method][$slug] = $m + [
                    'success_rate' => round($m['success'] / $technicalBase, 4),
                    'decline_rate' => round($m['declined'] / $attempts, 4),
                    'technical_error_rate' => round($m['technical_error'] / $attempts, 4),
                    'timeout_rate' => round($m['timeout'] / $attempts, 4),
                    'latency_p50_ms' => $latency['p50'],
                    'latency_p95_ms' => $latency['p95'],
                    'sufficient_sample' => $m['attempts'] >= $minSample,
                    'paid_conversion' => $method === 'pix' ? $this->pixPaidConversion($slug, $since) : null,
                    'cost_per_100_brl' => $method === 'pix' ? $this->pixCostPer100($slug) : null,
                ];
            }
        }

        return $out;
    }

    /** Cache curto para o caminho de pagamento (o checkout não pode esperar agregações). */
    public function cachedStats(int $hours, int $minSample): array
    {
        return Cache::remember("acquirer_health:{$hours}:{$minSample}", 60, fn () => $this->stats($hours, $minSample));
    }

    /** @return array{p50:int|null,p95:int|null} */
    private function latencyPercentiles(string $slug, string $method, $since): array
    {
        $values = DB::table('acquirer_attempts')
            ->where('gateway_slug', $slug)->where('method', $method)->where('created_at', '>=', $since)
            ->where('outcome', AcquirerAttemptRecorder::SUCCESS)
            ->orderByDesc('id')->limit(2000)
            ->pluck('latency_ms')->map(fn ($v) => (int) $v)->sort()->values();
        if ($values->isEmpty()) {
            return ['p50' => null, 'p95' => null];
        }
        $pick = fn (float $q) => $values[min($values->count() - 1, (int) floor($q * ($values->count() - 1)))];

        return ['p50' => $pick(0.5), 'p95' => $pick(0.95)];
    }

    /** PIX gerado na adquirente que virou pagamento confirmado. */
    private function pixPaidConversion(string $slug, $since): ?float
    {
        $base = Order::query()->where('gateway', $slug)->where('payment_method', 'pix')
            ->where('created_at', '>=', $since)->where('created_at', '<=', now()->subMinutes(30));
        $total = (clone $base)->count();
        if ($total === 0) {
            return null;
        }

        return round((clone $base)->whereIn('status', ['completed', 'refunded', 'disputed', 'refund_pending'])->count() / $total, 4);
    }

    private function pixCostPer100(string $slug): ?float
    {
        try {
            $e = GatewayPayoutEconomics::fromSlug($slug);

            return GatewayPayoutEconomics::pixInFeeCostBrl(100.0, (float) ($e['admin_fee_pix_brl'] ?? 0), (float) ($e['admin_fee_pix_percent'] ?? 0));
        } catch (\Throwable) {
            return null;
        }
    }
}

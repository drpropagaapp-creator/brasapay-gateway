<?php

namespace App\Services\Payments;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Brasa Smart Routing — reordena as adquirentes candidatas pelo desempenho real.
 *
 * Garantias:
 * - só REORDENA a lista recebida (nunca adiciona adquirente); a política do seller é aplicada
 *   depois, então uma adquirente proibida nunca é usada;
 * - desligado por padrão (estratégia "manual" = ordem atual);
 * - amostra pequena não decide nada: só entra na ordenação quem tem tentativas suficientes,
 *   e as demais ficam na posição em que estavam.
 */
class SmartRoutingService
{
    public const STRATEGIES = ['manual', 'performance', 'cost', 'balanced', 'custom'];

    public const METHODS = ['pix', 'card', 'boleto'];

    private const WEIGHTS = [
        'performance' => ['approval' => 0.5, 'stability' => 0.4, 'latency' => 0.1, 'cost' => 0.0],
        'cost' => ['approval' => 0.1, 'stability' => 0.3, 'latency' => 0.0, 'cost' => 0.6],
        'balanced' => ['approval' => 0.35, 'stability' => 0.3, 'latency' => 0.15, 'cost' => 0.2],
    ];

    public function __construct(private AcquirerHealthService $health) {}

    /** @return array{enabled:bool,strategy:string,methods:list<string>,min_sample:int,window_hours:int,weights:array<string,float>} */
    public static function config(): array
    {
        $raw = Setting::get('smart_routing', null, null);
        $raw = is_string($raw) ? (json_decode($raw, true) ?: []) : (is_array($raw) ? $raw : []);
        $strategy = in_array($raw['strategy'] ?? null, self::STRATEGIES, true) ? $raw['strategy'] : 'manual';
        $weights = is_array($raw['weights'] ?? null) ? $raw['weights'] : [];

        return [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'strategy' => $strategy,
            'methods' => array_values(array_intersect(self::METHODS, (array) ($raw['methods'] ?? self::METHODS))),
            'min_sample' => max(10, (int) ($raw['min_sample'] ?? AcquirerHealthService::DEFAULT_MIN_SAMPLE)),
            'window_hours' => max(1, min(168, (int) ($raw['window_hours'] ?? 24))),
            'weights' => [
                'approval' => max(0.0, (float) ($weights['approval'] ?? 0.35)),
                'stability' => max(0.0, (float) ($weights['stability'] ?? 0.3)),
                'latency' => max(0.0, (float) ($weights['latency'] ?? 0.15)),
                'cost' => max(0.0, (float) ($weights['cost'] ?? 0.2)),
            ],
        ];
    }

    /** Liga/desliga por seller: inherit (segue a plataforma), on ou off. */
    public static function tenantMode(?int $tenantId): string
    {
        if ($tenantId === null || $tenantId < 1) {
            return 'inherit';
        }
        $v = Setting::get('smart_routing_tenant', null, $tenantId);

        return in_array($v, ['on', 'off'], true) ? $v : 'inherit';
    }

    /**
     * @param  list<string>  $slugs
     * @return array{0: list<string>, 1: string}  [ordem, estratégia efetivamente aplicada]
     */
    public function reorder(array $slugs, string $method, ?int $tenantId): array
    {
        try {
            $cfg = self::config();
            $mode = self::tenantMode($tenantId);
            $active = $mode === 'on' || ($mode === 'inherit' && $cfg['enabled']);
            if (! $active || $cfg['strategy'] === 'manual' || ! in_array($method, $cfg['methods'], true) || count($slugs) < 2) {
                return [$slugs, 'manual'];
            }

            $stats = $this->health->cachedStats($cfg['window_hours'], $cfg['min_sample'])[$method] ?? [];

            return [$this->rank($slugs, $stats, $this->weightsFor($cfg)), $cfg['strategy']];
        } catch (\Throwable $e) {
            // Roteamento é opcional: qualquer falha volta para a ordem atual.
            Log::warning('SmartRouting: falhou, usando ordem manual', ['message' => $e->getMessage()]);

            return [$slugs, 'manual'];
        }
    }

    /**
     * @param  list<string>  $slugs
     * @param  array<string, array<string, mixed>>  $stats
     * @param  array<string, float>  $w
     * @return list<string>
     */
    public function rank(array $slugs, array $stats, array $w): array
    {
        $scored = [];
        $costs = array_filter(array_map(fn ($s) => $stats[$s]['cost_per_100_brl'] ?? null, $slugs), fn ($c) => $c !== null);
        $maxCost = $costs === [] ? 0.0 : max($costs);
        foreach ($slugs as $slug) {
            $m = $stats[$slug] ?? null;
            if ($m === null || empty($m['sufficient_sample'])) {
                continue;
            }
            $stability = 1 - ((float) $m['technical_error_rate'] + (float) $m['timeout_rate']);
            $latency = $m['latency_p95_ms'] !== null ? 1 - min((int) $m['latency_p95_ms'], 8000) / 8000 : 0.5;
            $cost = ($maxCost > 0 && ($m['cost_per_100_brl'] ?? null) !== null) ? 1 - ((float) $m['cost_per_100_brl'] / $maxCost) : 1.0;
            $score = $w['approval'] * (float) $m['success_rate'] + $w['stability'] * $stability + $w['latency'] * $latency + $w['cost'] * $cost;
            // Instável (mais de 20% de erro técnico/timeout) vai para o fim, mesmo sendo barata.
            if ($stability < 0.8) {
                $score -= 10;
            }
            $scored[$slug] = $score;
        }

        if (count($scored) < 2) {
            return $slugs;
        }

        // As posições de quem tem amostra suficiente são preenchidas pela melhor nota; o resto fica onde estava.
        $positions = array_keys(array_filter($slugs, fn ($s) => isset($scored[$s])));
        $ranked = array_keys($scored);
        usort($ranked, fn ($a, $b) => $scored[$b] <=> $scored[$a] ?: array_search($a, $slugs, true) <=> array_search($b, $slugs, true));
        $result = $slugs;
        foreach ($positions as $i => $pos) {
            $result[$pos] = $ranked[$i];
        }

        return array_values($result);
    }

    /** @return array<string, float> */
    private function weightsFor(array $cfg): array
    {
        $w = $cfg['strategy'] === 'custom' ? $cfg['weights'] : self::WEIGHTS[$cfg['strategy']];
        $sum = array_sum($w);

        return $sum > 0 ? array_map(fn ($v) => $v / $sum, $w) : self::WEIGHTS['balanced'];
    }
}

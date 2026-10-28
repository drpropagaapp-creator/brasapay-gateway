<?php

namespace App\Http\Controllers\Platform;

use App\Gateways\GatewayRegistry;
use App\Http\Controllers\Concerns\RequiresPlatformStepUp;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Services\Payments\AcquirerHealthService;
use App\Services\Payments\AcquirerPolicyService;
use App\Services\Payments\SmartRoutingService;
use App\Services\PaymentService;
use App\Services\PlatformAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Desempenho das adquirentes + configuração do Brasa Smart Routing (somente admin).
 */
class SmartRoutingController extends Controller
{
    use RequiresPlatformStepUp;

    public function index(Request $request, AcquirerHealthService $health): Response
    {
        $hours = (int) $request->integer('horas', 24);
        $hours = in_array($hours, [1, 6, 24, 72, 168], true) ? $hours : 24;
        $cfg = SmartRoutingService::config();

        $names = collect(GatewayRegistry::allowedAcquirers())->mapWithKeys(fn ($g) => [(string) ($g['slug'] ?? '') => (string) ($g['name'] ?? $g['slug'] ?? '')]);

        return Inertia::render('Platform/Ops/SmartRouting', [
            'hours' => $hours,
            'stats' => $health->stats($hours, $cfg['min_sample']),
            'gateway_names' => $names,
            'config' => $cfg,
            'strategies' => SmartRoutingService::STRATEGIES,
            'methods' => SmartRoutingService::METHODS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->validatePlatformStepUp($request);
        $v = $request->validate([
            'enabled' => ['required', 'boolean'],
            'strategy' => ['required', Rule::in(SmartRoutingService::STRATEGIES)],
            'methods' => ['present', 'array'],
            'methods.*' => [Rule::in(SmartRoutingService::METHODS)],
            'min_sample' => ['required', 'integer', 'min:10', 'max:10000'],
            'window_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'weights' => ['required', 'array'],
            'weights.approval' => ['required', 'numeric', 'min:0', 'max:1'],
            'weights.stability' => ['required', 'numeric', 'min:0', 'max:1'],
            'weights.latency' => ['required', 'numeric', 'min:0', 'max:1'],
            'weights.cost' => ['required', 'numeric', 'min:0', 'max:1'],
        ]);

        $before = SmartRoutingService::config();
        Setting::set('smart_routing', json_encode($v), null);
        Cache::forget('acquirer_health:'.$v['window_hours'].':'.$v['min_sample']);
        PlatformAuditService::log('platform.smart_routing.updated', ['before' => $before, 'after' => $v], $request);

        return back()->with('success', 'Smart Routing atualizado.');
    }

    /** Desligamento de emergência: volta para a ordem manual em todos os sellers que herdam a plataforma. */
    public function disable(Request $request): RedirectResponse
    {
        $cfg = SmartRoutingService::config();
        $cfg['enabled'] = false;
        Setting::set('smart_routing', json_encode($cfg), null);
        PlatformAuditService::log('platform.smart_routing.emergency_disabled', [], $request);

        return back()->with('success', 'Smart Routing desligado. Os pagamentos seguem a ordem manual.');
    }

    public function tenantMode(Request $request, User $user): RedirectResponse
    {
        $this->validatePlatformStepUp($request);
        $v = $request->validate(['mode' => ['required', Rule::in(['inherit', 'on', 'off'])]]);
        $tenantId = (int) ($user->tenant_id ?? $user->id);
        Setting::set('smart_routing_tenant', $v['mode'] === 'inherit' ? null : $v['mode'], $tenantId);
        PlatformAuditService::log('platform.smart_routing.tenant_mode', ['tenant_id' => $tenantId, 'mode' => $v['mode']], $request);

        return back()->with('success', 'Smart Routing do seller atualizado.');
    }

    /** Simula a ordem que um pagamento usaria agora para um seller (sem cobrar nada). */
    public function simulate(Request $request, PaymentService $payments): JsonResponse
    {
        $v = $request->validate([
            'method' => ['required', Rule::in(SmartRoutingService::METHODS)],
            'tenant_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $tenantId = isset($v['tenant_id']) ? (int) $v['tenant_id'] : null;

        $final = $payments->getGatewayOrderForMethod($tenantId, $v['method']);
        $policy = app(AcquirerPolicyService::class)->ruleFor($tenantId, $v['method']);

        return response()->json([
            'final_order' => $final,
            'strategy' => SmartRoutingService::tenantMode($tenantId) === 'off' ? 'manual (desligado para o seller)' : SmartRoutingService::config()['strategy'],
            'tenant_mode' => SmartRoutingService::tenantMode($tenantId),
            'policy' => $policy,
        ]);
    }
}

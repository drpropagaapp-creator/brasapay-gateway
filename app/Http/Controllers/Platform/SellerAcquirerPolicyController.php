<?php

namespace App\Http\Controllers\Platform;

use App\Gateways\GatewayRegistry;
use App\Http\Controllers\Concerns\RequiresPlatformStepUp;
use App\Http\Controllers\Controller;
use App\Models\GatewayCredential;
use App\Models\SellerAcquirerPolicy;
use App\Models\SellerAcquirerPolicyLog;
use App\Models\User;
use App\Services\Payments\AcquirerPolicyService;
use App\Services\PaymentService;
use App\Services\PlatformAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Adquirentes e métodos autorizados por seller — exclusivo do admin.
 */
class SellerAcquirerPolicyController extends Controller
{
    use RequiresPlatformStepUp;

    public function __construct(private AcquirerPolicyService $policy) {}

    public function show(User $user): JsonResponse
    {
        Gate::authorize('manageMerchantForPlatform', $user);
        $tenantId = $this->tenantIdFor($user);

        $connected = GatewayCredential::connectedMapForPayment($tenantId);
        $payments = app(PaymentService::class);
        $rules = $this->policy->policyFor($tenantId);

        $methods = [];
        foreach (SellerAcquirerPolicy::METHODS as $method) {
            $capable = [];
            foreach (AcquirerPolicyService::capableGateways($method) as $slug) {
                $def = GatewayRegistry::get($slug);
                $capable[] = [
                    'slug' => $slug,
                    'name' => (string) ($def['name'] ?? $slug),
                    'connected' => $connected->has($slug),
                ];
            }

            $effective = array_values(array_filter(
                $payments->getGatewayOrderForMethod($tenantId, $method),
                fn ($slug) => $connected->has($slug)
            ));

            $methods[] = [
                'key' => $method,
                'rule' => $rules[$method] ?? ['mode' => SellerAcquirerPolicy::MODE_INHERIT, 'gateways' => []],
                'capable' => $capable,
                'effective' => $effective,
            ];
        }

        $logs = SellerAcquirerPolicyLog::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(function (SellerAcquirerPolicyLog $log) {
                return [
                    'id' => $log->id,
                    'actor' => $log->actor_user_id ? User::query()->whereKey($log->actor_user_id)->value('name') : null,
                    'before' => $log->before,
                    'after' => $log->after,
                    'reason' => $log->reason,
                    'created_at' => $log->created_at?->toIso8601String(),
                ];
            });

        return response()->json([
            'tenant_id' => $tenantId,
            'methods' => $methods,
            'logs' => $logs,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageMerchantForPlatform', $user);
        $this->validatePlatformStepUp($request);

        $validated = $request->validate([
            'methods' => ['present', 'array'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $tenantId = $this->tenantIdFor($user);
        $this->policy->save($tenantId, $validated['methods'], (int) $request->user()->id, $validated['reason'] ?? null);

        PlatformAuditService::log('platform.seller.acquirer_policy_updated', [
            'seller_user_id' => $user->id,
            'tenant_id' => $tenantId,
        ], $request);

        return back()->with('success', 'Adquirentes do seller atualizadas.');
    }

    private function tenantIdFor(User $user): int
    {
        if (! $user->isInfoprodutor()) {
            abort(422, 'Política de adquirência só se aplica a contas de infoprodutor.');
        }

        return (int) ($user->tenant_id ?? $user->id);
    }
}

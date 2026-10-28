<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\Sandbox\SandboxController;
use App\Models\ApiApplication;
use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\ApiWebhookDelivery;
use App\Models\SandboxTransaction;
use App\Services\Api\SandboxService;
use App\Support\WebhookPayloadSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brasa Developers — portal do desenvolvedor: consumo da API, erros recentes, entregas de webhook
 * (com payload mascarado), simulador de entrega e sandbox. A gestão de chaves continua em /aplicacoes-api.
 */
class DevelopersController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantId = (int) $request->user()->tenant_id;
        $apps = ApiApplication::query()->where('tenant_id', $tenantId)->orderBy('id')->get(['id', 'name', 'is_active']);
        $app = $apps->firstWhere('id', (int) $request->query('app')) ?? $apps->first();

        return Inertia::render('Developers/Index', [
            'applications' => $apps->map(fn ($a) => ['id' => $a->id, 'name' => $a->name ?: 'Integração principal', 'is_active' => (bool) $a->is_active])->values(),
            'selected' => $app?->id,
            'overview' => $app ? $this->overview($app) : null,
            'base_url' => rtrim((string) config('app.url'), '/'),
            'test_events' => SandboxController::testEvents(),
            'sandbox_declined_token' => SandboxService::CARD_DECLINED_TOKEN,
        ]);
    }

    public function delivery(Request $request, ApiWebhookDelivery $delivery): JsonResponse
    {
        abort_unless((int) $delivery->tenant_id === (int) $request->user()->tenant_id, 404);

        return response()->json([
            'id' => $delivery->id,
            'event' => $delivery->event,
            'event_id' => $delivery->event_id,
            'url' => $delivery->url,
            'status' => $delivery->status,
            'livemode' => $delivery->livemode ?? true,
            'attempt' => (int) $delivery->attempt,
            'last_status_code' => $delivery->last_status_code,
            'duration_ms' => $delivery->duration_ms,
            'created_at' => $delivery->created_at?->toIso8601String(),
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'next_retry_at' => $delivery->next_retry_at?->toIso8601String(),
            'payload' => WebhookPayloadSanitizer::sanitize($delivery->payload ?? []),
            'response_body' => Str::limit((string) $delivery->last_response_body, 2000),
        ]);
    }

    public function testWebhook(Request $request, ApiApplication $apiApplication, SandboxService $sandbox): JsonResponse
    {
        abort_unless((int) $apiApplication->tenant_id === (int) $request->user()->tenant_id, 404);
        $event = $request->validate(['event' => ['required', Rule::in(SandboxController::testEvents())]])['event'];
        $created = $sandbox->sendTestEvent($apiApplication, $event);

        return response()->json([
            'created' => $created,
            'message' => $created > 0
                ? 'Evento de teste enviado (livemode: false). Acompanhe a entrega na lista.'
                : 'Nenhum webhook ativo inscrito neste evento. Cadastre a URL em Aplicações API.',
        ], $created > 0 ? 202 : 422);
    }

    /** @return array<string, mixed> */
    private function overview(ApiApplication $app): array
    {
        $since7 = now()->subDays(7);
        $since24 = now()->subDay();
        $logs = ApiRequestLog::query()->where('api_application_id', $app->id)->where('created_at', '>=', $since7);

        $byStatus = (clone $logs)->selectRaw("environment,
                SUM(CASE WHEN status < 400 THEN 1 ELSE 0 END) as ok,
                SUM(CASE WHEN status >= 400 AND status < 500 THEN 1 ELSE 0 END) as client_errors,
                SUM(CASE WHEN status >= 500 THEN 1 ELSE 0 END) as server_errors,
                COUNT(*) as total, AVG(duration_ms) as avg_ms")
            ->groupBy('environment')->get()->keyBy('environment');

        $durations = (clone $logs)->where('environment', ApiKey::ENV_LIVE)->latest('id')->limit(2000)->pluck('duration_ms')->sort()->values();
        $p95 = $durations->isEmpty() ? null : (int) $durations[(int) floor(0.95 * ($durations->count() - 1))];

        $routes = (clone $logs)->selectRaw('method, route, COUNT(*) as total, SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) as errors, AVG(duration_ms) as avg_ms')
            ->groupBy('method', 'route')->orderByDesc('total')->limit(10)->get()
            ->map(fn ($r) => ['method' => $r->method, 'route' => $r->route, 'total' => (int) $r->total, 'errors' => (int) $r->errors, 'avg_ms' => (int) round((float) $r->avg_ms)]);

        $errors = ApiRequestLog::query()->where('api_application_id', $app->id)->where('status', '>=', 400)
            ->latest('id')->limit(30)->get(['id', 'environment', 'method', 'route', 'status', 'duration_ms', 'request_id', 'error_message', 'created_at'])
            ->map(fn ($e) => ['id' => $e->id, 'environment' => $e->environment, 'method' => $e->method, 'route' => $e->route, 'status' => $e->status,
                'duration_ms' => $e->duration_ms, 'request_id' => $e->request_id, 'error' => $e->error_message, 'at' => $e->created_at?->toIso8601String()]);

        $deliveries = ApiWebhookDelivery::query()->where('api_application_id', $app->id)->latest('created_at')->limit(30)
            ->get(['id', 'event', 'status', 'last_status_code', 'attempt', 'duration_ms', 'livemode', 'created_at', 'delivered_at'])
            ->map(fn ($d) => ['id' => $d->id, 'event' => $d->event, 'status' => $d->status, 'code' => $d->last_status_code, 'attempt' => (int) $d->attempt,
                'duration_ms' => $d->duration_ms, 'livemode' => $d->livemode ?? true, 'at' => $d->created_at?->toIso8601String()]);

        $env = fn (string $e) => [
            'total' => (int) ($byStatus[$e]->total ?? 0),
            'ok' => (int) ($byStatus[$e]->ok ?? 0),
            'client_errors' => (int) ($byStatus[$e]->client_errors ?? 0),
            'server_errors' => (int) ($byStatus[$e]->server_errors ?? 0),
            'avg_ms' => isset($byStatus[$e]) ? (int) round((float) $byStatus[$e]->avg_ms) : null,
        ];

        return [
            'app' => ['id' => $app->id, 'name' => $app->name ?: 'Integração principal', 'is_active' => (bool) $app->is_active],
            'live' => $env(ApiKey::ENV_LIVE) + ['p95_ms' => $p95, 'last_24h' => (clone $logs)->where('environment', ApiKey::ENV_LIVE)->where('created_at', '>=', $since24)->count()],
            'sandbox' => $env(ApiKey::ENV_SANDBOX) + [
                'keys' => ApiKey::query()->where('api_application_id', $app->id)->where('environment', ApiKey::ENV_SANDBOX)->where('is_active', true)->count(),
                'transactions' => SandboxTransaction::query()->where('api_application_id', $app->id)->count(),
            ],
            'routes' => $routes,
            'errors' => $errors,
            'deliveries' => $deliveries,
            'has_webhook' => (bool) $app->webhook_url || $app->webhooks()->where('is_active', true)->exists(),
        ];
    }
}

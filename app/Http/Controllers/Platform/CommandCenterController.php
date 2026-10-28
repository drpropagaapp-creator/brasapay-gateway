<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\ApiWebhookDelivery;
use App\Models\OpsAlert;
use App\Models\Order;
use App\Models\User;
use App\Services\Api\ApiWebhookDeliveryService;
use App\Services\PlatformAuditService;
use App\Services\Platform\CommandCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brasa Command Center — visão única de operação, central de alertas e ações administrativas seguras.
 * Ações que mexem com dinheiro (cobrança, saque, reembolso, conciliação) não são reprocessadas daqui:
 * ficam nas telas próprias, com as travas delas.
 */
class CommandCenterController extends Controller
{
    /** Jobs que podem ser reprocessados daqui: notificações e rastreamento, sem efeito financeiro. */
    private const RETRYABLE_JOBS = [
        \App\Jobs\DeliverApiWebhookJob::class,
        \App\Jobs\DispatchWebhookJob::class,
        \App\Jobs\SendApiApplicationWebhookJob::class,
        \App\Jobs\MetaConversionsSendPurchaseJob::class,
        \App\Jobs\UtmifySendOrderJob::class,
        \App\Jobs\EnrichMetricsEventGeoJob::class,
        \App\Jobs\AggregateMetricsDailyStatsJob::class,
        \App\Jobs\NotifySalesAchievementEarnedJob::class,
        \App\Jobs\NotifySalesAchievementRewardStatusJob::class,
        \App\Jobs\SendProductApprovedMailJob::class,
        \App\Jobs\SendProductRejectedMailJob::class,
        \App\Jobs\SendAffiliateEnrollmentApprovedMailJob::class,
    ];

    public function __construct(private CommandCenterService $center) {}

    public function index(): Response
    {
        $data = Cache::remember('command_center:v2', 60, fn () => $this->center->snapshot());

        return Inertia::render('Platform/CommandCenter', $data + [
            'generated_at' => now()->toIso8601String(),
            'alert_center' => [
                'active' => OpsAlert::query()->whereIn('status', ['open', 'acknowledged'])->get()
                    ->sortBy(fn ($a) => OpsAlert::SEVERITY_RANK[$a->severity] ?? 9)->values()->map(fn ($a) => $this->presentAlert($a)),
                'resolved' => OpsAlert::query()->where('status', 'resolved')->where('resolved_at', '>=', now()->subDays(7))
                    ->latest('resolved_at')->limit(30)->get()->map(fn ($a) => $this->presentAlert($a)),
            ],
            'failed_jobs' => $this->failedJobs(),
            'failed_deliveries' => ApiWebhookDelivery::query()->where('status', ApiWebhookDelivery::STATUS_FAILED)
                ->where('updated_at', '>=', now()->subDays(3))->latest('updated_at')->limit(30)
                ->get(['id', 'tenant_id', 'event', 'url', 'last_status_code', 'attempt', 'updated_at'])
                ->map(fn ($d) => ['id' => $d->id, 'tenant_id' => $d->tenant_id, 'event' => $d->event, 'host' => parse_url((string) $d->url, PHP_URL_HOST),
                    'code' => $d->last_status_code, 'attempt' => $d->attempt, 'at' => $d->updated_at?->toIso8601String()]),
        ]);
    }

    public function acknowledge(Request $request, OpsAlert $alert): RedirectResponse
    {
        $v = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']]);
        abort_unless(in_array($alert->status, ['open', 'acknowledged'], true), 422, 'Alerta já resolvido.');
        $alert->update(['status' => 'acknowledged', 'acknowledged_by' => $request->user()->id, 'acknowledged_at' => now(), 'ack_note' => $v['note']]);
        PlatformAuditService::log('ops.alert.acknowledged', ['alert_id' => $alert->id, 'key' => $alert->key, 'note' => $v['note']]);

        return back()->with('success', 'Alerta reconhecido. Ele some sozinho quando a condição deixar de existir.');
    }

    public function retryJob(Request $request, string $uuid): RedirectResponse
    {
        abort_unless(Schema::hasTable('failed_jobs'), 404);
        $job = DB::table('failed_jobs')->where('uuid', $uuid)->first();
        abort_if($job === null, 404);
        $class = $this->jobClass((string) $job->payload);
        abort_unless(in_array($class, self::RETRYABLE_JOBS, true), 422, 'Este job mexe com pagamento, saque ou conciliação e não é reprocessado daqui.');

        Artisan::call('queue:retry', ['id' => [$uuid]]);
        PlatformAuditService::log('ops.job.retried', ['uuid' => $uuid, 'job' => $class]);

        return back()->with('success', 'Job devolvido para a fila.');
    }

    public function retryDelivery(ApiWebhookDelivery $delivery, ApiWebhookDeliveryService $deliveries): RedirectResponse
    {
        abort_unless($delivery->status === ApiWebhookDelivery::STATUS_FAILED, 422, 'Só entregas que falharam podem ser reenviadas.');
        $deliveries->retryNow($delivery);
        PlatformAuditService::log('ops.webhook.retried', ['delivery_id' => $delivery->id, 'tenant_id' => $delivery->tenant_id, 'event' => $delivery->event]);

        return back()->with('success', 'Reenvio agendado.');
    }

    /** Busca rápida: pedido (#id), seller (e-mail ou id). Só devolve links para as telas que já existem. */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:190']])['q']);
        $results = [];
        if (ctype_digit(ltrim($q, '#'))) {
            $id = (int) ltrim($q, '#');
            if ($order = Order::query()->find($id, ['id', 'tenant_id', 'status', 'amount'])) {
                $results[] = ['type' => 'Pedido', 'label' => "#{$order->id} · {$order->status} · R$ ".number_format((float) $order->amount, 2, ',', '.'), 'href' => '/plataforma/transacoes?q='.$order->id];
            }
            if ($user = User::query()->where('role', User::ROLE_INFOPRODUTOR)->find($id, ['id', 'name', 'email'])) {
                $results[] = ['type' => 'Seller', 'label' => "{$user->name} ({$user->email})", 'href' => "/plataforma/usuarios/{$user->id}"];
            }
        } elseif (str_contains($q, '@')) {
            foreach (User::query()->where('role', User::ROLE_INFOPRODUTOR)->whereRaw('LOWER(email) = ?', [mb_strtolower($q)])->limit(5)->get(['id', 'name', 'email']) as $user) {
                $results[] = ['type' => 'Seller', 'label' => "{$user->name} ({$user->email})", 'href' => "/plataforma/usuarios/{$user->id}"];
            }
        }

        return response()->json(['results' => $results]);
    }

    /** @return list<array<string, mixed>> */
    private function failedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        return DB::table('failed_jobs')->latest('failed_at')->limit(30)->get(['uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(function ($j) {
                $class = $this->jobClass((string) $j->payload);

                return ['uuid' => $j->uuid, 'queue' => $j->queue, 'job' => class_basename($class ?: 'desconhecido'),
                    'error' => Str::limit(strtok((string) $j->exception, "\n") ?: '', 200), 'at' => (string) $j->failed_at,
                    'retryable' => in_array($class, self::RETRYABLE_JOBS, true)];
            })->all();
    }

    private function jobClass(string $payload): ?string
    {
        $data = json_decode($payload, true);

        return is_array($data) ? ($data['displayName'] ?? ($data['data']['commandName'] ?? null)) : null;
    }

    private function presentAlert(OpsAlert $a): array
    {
        return ['id' => $a->id, 'key' => $a->key, 'severity' => $a->severity, 'area' => $a->area, 'title' => $a->title, 'hint' => $a->hint,
            'href' => $a->href, 'status' => $a->status, 'first_seen_at' => $a->first_seen_at?->toIso8601String(),
            'last_seen_at' => $a->last_seen_at?->toIso8601String(), 'resolved_at' => $a->resolved_at?->toIso8601String(), 'ack_note' => $a->ack_note];
    }
}

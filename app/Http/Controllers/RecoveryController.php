<?php

namespace App\Http\Controllers;

use App\Models\RecoveryMessage;
use App\Models\RecoveryOptOut;
use App\Services\TenantMailConfigService;
use App\Support\RecoveryEmailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brasa Recovery — configuração e resultados do seller; descadastro público do comprador.
 */
class RecoveryController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantId = (int) $request->user()->tenant_id;
        $since = now()->subDays(30);
        $base = RecoveryMessage::query()->where('tenant_id', $tenantId)->where('created_at', '>=', $since);

        $recovered = (clone $base)->whereNotNull('recovered_order_id')->get(['recovered_order_id', 'recovered_amount_cents'])
            ->unique('recovered_order_id');

        return Inertia::render('Recovery/Index', [
            'settings' => RecoveryEmailSettings::forTenant($tenantId),
            'smtp_ready' => app(TenantMailConfigService::class)->isEmailConfigured($tenantId)
                || app(TenantMailConfigService::class)->isEmailConfigured(null),
            'stats' => [
                'sent' => (clone $base)->where('status', 'sent')->count(),
                'failed' => (clone $base)->where('status', 'failed')->count(),
                'skipped' => (clone $base)->where('status', 'skipped')->count(),
                'recovered_orders' => $recovered->count(),
                'recovered_amount_cents' => (int) $recovered->sum('recovered_amount_cents'),
                'opt_outs' => RecoveryOptOut::query()->where('tenant_id', $tenantId)->count(),
            ],
            'by_event' => (clone $base)->where('status', 'sent')->selectRaw('event, COUNT(*) as n')->groupBy('event')->pluck('n', 'event'),
            'recent' => (clone $base)->latest('id')->limit(30)->get()->map(fn (RecoveryMessage $m) => [
                'id' => $m->id,
                'event' => $m->event,
                'step' => $m->step,
                'recipient' => self::mask($m->recipient),
                'status' => $m->status,
                'skip_reason' => $m->skip_reason,
                'sent_at' => $m->sent_at?->toIso8601String() ?? $m->created_at?->toIso8601String(),
                'recovered' => $m->recovered_order_id !== null,
            ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'enabled' => ['required', 'boolean'],
            'quiet_start' => ['required', 'integer', 'min:0', 'max:23'],
            'quiet_end' => ['required', 'integer', 'min:0', 'max:23'],
            'max_per_day' => ['required', 'integer', 'min:1', 'max:5'],
            'events' => ['required', 'array'],
            'events.*.enabled' => ['boolean'],
            'events.*.steps' => ['array', 'max:3'],
            'events.*.steps.*.delay_minutes' => ['required', 'integer', 'min:10', 'max:10080'],
            'events.*.steps.*.subject' => ['required', 'string', 'max:150'],
            'events.*.steps.*.body' => ['required', 'string', 'max:3000'],
        ]);
        RecoveryEmailSettings::save((int) $request->user()->tenant_id, $request->only(['enabled', 'quiet_start', 'quiet_end', 'max_per_day', 'events']));

        return back()->with('success', 'Recuperação por e-mail atualizada.');
    }

    /** Link assinado do rodapé do e-mail: o comprador para de receber lembretes deste seller. */
    public function optOut(Request $request, int $tenant, string $email): Response
    {
        $decoded = base64_decode(strtr($email, '-_', '+/'), true);
        $valid = is_string($decoded) && filter_var($decoded, FILTER_VALIDATE_EMAIL);
        if ($valid) {
            RecoveryOptOut::query()->firstOrCreate(['tenant_id' => $tenant, 'email' => mb_strtolower($decoded), 'channel' => 'email']);
        }

        return Inertia::render('Recovery/OptOut', ['ok' => (bool) $valid]);
    }

    private static function mask(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($user, 0, 2).str_repeat('•', max(1, mb_strlen($user) - 2)).'@'.$domain;
    }
}

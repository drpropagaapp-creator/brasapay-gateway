<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\PlatformAuditService;
use App\Support\AiAssistantSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Brasa AI (admin): provedor, modelo, limites e consumo. A chave nunca volta para a tela. */
class AiSettingsController extends Controller
{
    public function index(): Response
    {
        $since = now()->startOfMonth();
        $byTenant = AiMessage::query()->where('created_at', '>=', $since)->where('role', 'assistant')
            ->groupBy('tenant_id')->selectRaw('tenant_id, COUNT(*) as answers, SUM(cost_micros) as cost, SUM(CASE WHEN status = \'error\' THEN 1 ELSE 0 END) as errors')
            ->orderByDesc('cost')->limit(20)->get();
        $users = User::query()->whereIn('id', $byTenant->pluck('tenant_id'))->get(['id', 'name', 'email'])->keyBy('id');
        $month = AiMessage::query()->where('created_at', '>=', $since)->where('role', 'assistant');

        return Inertia::render('Platform/Ai/Index', [
            'settings' => AiAssistantSettings::get(),
            'has_key' => AiAssistantSettings::apiKey() !== null,
            'models' => collect(AiAssistantSettings::MODELS)->map(fn ($m, $id) => ['id' => $id, 'label' => $m['label'], 'input' => $m['input'], 'output' => $m['output']])->values(),
            'efforts' => AiAssistantSettings::EFFORTS,
            'month' => [
                'answers' => (clone $month)->count(),
                'errors' => (clone $month)->where('status', 'error')->count(),
                'cost_micros' => (int) (clone $month)->sum('cost_micros'),
                'avg_ms' => (int) round((float) (clone $month)->where('status', 'ok')->avg('duration_ms')),
                'tools' => $this->toolCounts($since),
            ],
            'tenants' => $byTenant->map(fn ($r) => ['tenant_id' => $r->tenant_id, 'name' => $users->get($r->tenant_id)?->name, 'email' => $users->get($r->tenant_id)?->email,
                'answers' => (int) $r->answers, 'errors' => (int) $r->errors, 'cost_micros' => (int) $r->cost])->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'enabled' => ['required', 'boolean'],
            'model' => ['required', Rule::in(array_keys(AiAssistantSettings::MODELS))],
            'effort' => ['required', Rule::in(AiAssistantSettings::EFFORTS)],
            'daily_messages_per_user' => ['required', 'integer', 'min:1', 'max:500'],
            'monthly_budget_cents_per_tenant' => ['required', 'integer', 'min:0', 'max:1000000'],
            'monthly_budget_cents_platform' => ['required', 'integer', 'min:0', 'max:100000000'],
            'api_key' => ['nullable', 'string', 'max:300', 'regex:/^sk-ant-[A-Za-z0-9_\-]{10,}$/'],
            'remove_key' => ['nullable', 'boolean'],
        ]);
        if (! empty($v['api_key'])) {
            AiAssistantSettings::setApiKey($v['api_key']);
        } elseif (! empty($v['remove_key'])) {
            AiAssistantSettings::setApiKey(null);
        }
        $saved = AiAssistantSettings::save($v);
        PlatformAuditService::log('ai.settings.updated', $saved + ['key_changed' => ! empty($v['api_key']) || ! empty($v['remove_key'])]);

        return back()->with('success', 'Configuração do assistente salva.');
    }

    /** @return array<string, int> */
    private function toolCounts(\Carbon\CarbonInterface $since): array
    {
        $counts = [];
        AiMessage::query()->where('created_at', '>=', $since)->where('role', 'assistant')->whereNotNull('tools_used')
            ->select('tools_used')->limit(5000)->get()->each(function ($m) use (&$counts) {
                foreach ((array) $m->tools_used as $t) {
                    $counts[$t] = ($counts[$t] ?? 0) + 1;
                }
            });
        arsort($counts);

        return $counts;
    }
}

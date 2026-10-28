<?php

namespace App\Http\Controllers;

use App\Models\AdSpend;
use App\Services\Intelligence\IntelligenceService;
use App\Services\TeamAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Brasa Intelligence — origem das vendas, ROAS/CAC com custo informado, aprovação e alertas. */
class IntelligenceController extends Controller
{
    private const PERIODS = [7, 30, 90];

    public function __construct(private IntelligenceService $intelligence) {}

    public function index(Request $request): Response
    {
        $f = $request->validate(['days' => ['nullable', 'integer', Rule::in(self::PERIODS)]]);
        $days = (int) ($f['days'] ?? 30);
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $isTeam = $user->isTeam();
        $allowed = $isTeam ? array_map('strval', app(TeamAccessService::class)->allowedProductIdsFor($user)) : null;

        $end = now()->endOfDay();
        $start = now()->subDays($days - 1)->startOfDay();

        return Inertia::render('Intelligence/Index', [
            'days' => $days,
            'periods' => self::PERIODS,
            'report' => $this->intelligence->report($tenantId, $allowed, $start, $end, ! $isTeam),
            'can_edit_spend' => ! $isTeam,
            'spends' => $isTeam ? [] : AdSpend::query()->where('tenant_id', $tenantId)
                ->whereBetween('spent_on', [$start->toDateString(), $end->toDateString()])
                ->orderByDesc('spent_on')->orderByDesc('id')->limit(300)
                ->get(['id', 'spent_on', 'source', 'campaign', 'amount_cents', 'note'])
                ->map(fn (AdSpend $s) => ['id' => $s->id, 'spent_on' => $s->spent_on->toDateString(), 'source' => $s->source,
                    'campaign' => $s->campaign, 'amount_cents' => $s->amount_cents, 'note' => $s->note]),
        ]);
    }

    public function storeSpend(Request $request): RedirectResponse
    {
        abort_if($request->user()->isTeam(), 403);
        $v = $request->validate([
            'spent_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after:'.now()->subYears(2)->toDateString()],
            'source' => ['required', 'string', 'max:100'],
            'campaign' => ['nullable', 'string', 'max:191'],
            'amount' => ['required', 'regex:/^\d{1,9}([.,]\d{1,2})?$/'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $cents = (int) bcmul(str_replace(',', '.', $v['amount']), '100', 0);
        abort_if($cents <= 0, 422, 'Informe um valor maior que zero.');

        AdSpend::query()->create([
            'tenant_id' => (int) $request->user()->tenant_id,
            'spent_on' => Carbon::createFromFormat('Y-m-d', $v['spent_on'])->toDateString(),
            'source' => mb_strtolower(trim($v['source'])),
            'campaign' => filled($v['campaign'] ?? null) ? mb_strtolower(trim($v['campaign'])) : null,
            'amount_cents' => $cents,
            'note' => $v['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Custo registrado.');
    }

    public function destroySpend(Request $request, AdSpend $spend): RedirectResponse
    {
        abort_if($request->user()->isTeam(), 403);
        abort_unless((int) $spend->tenant_id === (int) $request->user()->tenant_id, 404);
        $spend->delete();

        return back()->with('success', 'Custo removido.');
    }
}

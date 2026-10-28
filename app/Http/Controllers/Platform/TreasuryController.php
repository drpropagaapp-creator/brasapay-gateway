<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\TreasuryDiscrepancy;
use App\Models\TreasuryRun;
use App\Models\User;
use App\Services\PlatformAuditService;
use App\Services\Treasury\TreasuryReconciliationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brasa Treasury — painel de divergências de conciliação (somente admin).
 * As ações aqui mudam apenas o status da divergência; nenhum saldo é movimentado.
 */
class TreasuryController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'open', 'investigating', 'resolved', 'ignored', 'all'])],
            'severity' => ['nullable', Rule::in(['info', 'attention', 'high', 'critical'])],
            'kind' => ['nullable', Rule::in(array_keys(TreasuryReconciliationService::KINDS))],
        ]);
        $status = $filters['status'] ?? 'active';

        $query = TreasuryDiscrepancy::query()->orderByRaw($this->severityOrderSql())->orderByDesc('last_seen_at');
        match ($status) {
            'active' => $query->whereIn('status', [TreasuryDiscrepancy::STATUS_OPEN, TreasuryDiscrepancy::STATUS_INVESTIGATING]),
            'all' => null,
            default => $query->where('status', $status),
        };
        if (! empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }
        if (! empty($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }

        $page = $query->paginate(50)->withQueryString();
        $tenantNames = User::query()->whereIn('id', collect($page->items())->pluck('tenant_id')->filter()->unique())->pluck('name', 'id');

        $active = TreasuryDiscrepancy::query()->whereIn('status', [TreasuryDiscrepancy::STATUS_OPEN, TreasuryDiscrepancy::STATUS_INVESTIGATING]);
        $summary = [
            'active' => (clone $active)->count(),
            'critical' => (clone $active)->where('severity', 'critical')->count(),
            'amount_cents' => (int) (clone $active)->whereIn('severity', ['critical', 'high'])->sum('amount_cents'),
            'withdrawals_investigating' => (clone $active)->where('kind', 'withdrawal_reconcile_exhausted')->count(),
        ];

        return Inertia::render('Platform/Treasury/Index', [
            'filters' => ['status' => $status, 'severity' => $filters['severity'] ?? null, 'kind' => $filters['kind'] ?? null],
            'kinds' => collect(TreasuryReconciliationService::KINDS)->map(fn ($v, $k) => ['key' => $k, 'severity' => $v[0], 'label' => $v[1]])->values(),
            'summary' => $summary,
            'last_run' => TreasuryRun::query()->latest('id')->first()?->only(['id', 'status', 'started_at', 'finished_at', 'counts', 'error']),
            'discrepancies' => [
                'data' => collect($page->items())->map(fn (TreasuryDiscrepancy $d) => [
                    'id' => $d->id,
                    'kind' => $d->kind,
                    'label' => TreasuryReconciliationService::KINDS[$d->kind][1] ?? $d->kind,
                    'severity' => $d->severity,
                    'status' => $d->status,
                    'tenant_id' => $d->tenant_id,
                    'tenant_name' => $d->tenant_id ? ($tenantNames[$d->tenant_id] ?? null) : null,
                    'order_id' => $d->order_id,
                    'withdrawal_id' => $d->withdrawal_id,
                    'amount_cents' => $d->amount_cents,
                    'details' => $d->details,
                    'first_seen_at' => $d->first_seen_at?->toIso8601String(),
                    'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                    'resolved_at' => $d->resolved_at?->toIso8601String(),
                    'resolution_note' => $d->resolution_note,
                ]),
                'links' => $page->linkCollection(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $result = (new TreasuryReconciliationService)->run((int) $request->user()->id);
        PlatformAuditService::log('platform.treasury.run', ['run_id' => $result['run_id'] ?? null], $request);

        return back()->with(
            ! empty($result['skipped']) ? 'info' : 'success',
            ! empty($result['skipped']) ? 'Já existe uma conciliação em andamento.' : 'Conciliação concluída.'
        );
    }

    public function updateStatus(Request $request, TreasuryDiscrepancy $discrepancy): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([TreasuryDiscrepancy::STATUS_INVESTIGATING, TreasuryDiscrepancy::STATUS_RESOLVED, TreasuryDiscrepancy::STATUS_IGNORED, TreasuryDiscrepancy::STATUS_OPEN])],
            'note' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $before = $discrepancy->status;
        $closing = in_array($validated['status'], [TreasuryDiscrepancy::STATUS_RESOLVED, TreasuryDiscrepancy::STATUS_IGNORED], true);
        $discrepancy->update([
            'status' => $validated['status'],
            'resolved_at' => $closing ? now() : null,
            'resolved_by' => $closing ? $request->user()->id : null,
            'resolution_note' => trim(($discrepancy->resolution_note ? $discrepancy->resolution_note."\n" : '')
                .now()->format('d/m/Y H:i').' — '.$request->user()->name.': '.$validated['note']),
        ]);

        PlatformAuditService::log('platform.treasury.discrepancy_status', [
            'discrepancy_id' => $discrepancy->id,
            'kind' => $discrepancy->kind,
            'from' => $before,
            'to' => $validated['status'],
            'note' => $validated['note'],
        ], $request);

        return back()->with('success', 'Divergência atualizada.');
    }

    private function severityOrderSql(): string
    {
        return "CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'attention' THEN 2 ELSE 3 END";
    }
}

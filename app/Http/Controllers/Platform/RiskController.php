<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Services\PlatformAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** BrasaShield — fila de análise de risco (somente admin; observação, sem bloqueio automático). */
class RiskController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate([
            'level' => ['nullable', Rule::in(['high', 'medium', 'low'])],
            'status' => ['nullable', Rule::in(['open', 'reviewed', 'dismissed', 'all'])],
            'type' => ['nullable', Rule::in(['order', 'withdrawal'])],
        ]);
        $status = $f['status'] ?? 'open';
        $q = RiskAssessment::query()->where('level', '!=', 'low')->orderByDesc('score')->orderByDesc('id');
        if ($status !== 'all') {
            $q->where('status', $status);
        }
        if (! empty($f['level'])) {
            $q->where('level', $f['level']);
        }
        if (! empty($f['type'])) {
            $q->where('subject_type', $f['type']);
        }
        $page = $q->paginate(50)->withQueryString();
        $names = User::query()->whereIn('id', collect($page->items())->pluck('tenant_id')->filter()->unique())->pluck('name', 'id');

        return Inertia::render('Platform/Risk/Index', [
            'filters' => ['level' => $f['level'] ?? null, 'status' => $status, 'type' => $f['type'] ?? null],
            'summary' => [
                'high_open' => RiskAssessment::query()->where('level', 'high')->where('status', 'open')->count(),
                'medium_open' => RiskAssessment::query()->where('level', 'medium')->where('status', 'open')->count(),
                'assessed_24h' => RiskAssessment::query()->where('updated_at', '>=', now()->subDay())->count(),
            ],
            'items' => [
                'data' => collect($page->items())->map(fn (RiskAssessment $r) => [
                    'id' => $r->id,
                    'subject_type' => $r->subject_type,
                    'subject_id' => $r->subject_id,
                    'tenant_id' => $r->tenant_id,
                    'tenant_name' => $r->tenant_id ? ($names[$r->tenant_id] ?? null) : null,
                    'score' => $r->score,
                    'level' => $r->level,
                    'suggested_action' => $r->suggested_action,
                    'signals' => $r->signals,
                    'status' => $r->status,
                    'amount_cents' => $r->amount_cents,
                    'review_note' => $r->review_note,
                    'updated_at' => $r->updated_at?->toIso8601String(),
                ]),
                'links' => $page->linkCollection(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function review(Request $request, RiskAssessment $assessment): RedirectResponse
    {
        $v = $request->validate([
            'status' => ['required', Rule::in(['reviewed', 'dismissed', 'open'])],
            'note' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $before = $assessment->status;
        $assessment->update([
            'status' => $v['status'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => trim(($assessment->review_note ? $assessment->review_note."\n" : '').now()->format('d/m/Y H:i').' — '.$request->user()->name.': '.$v['note']),
        ]);
        PlatformAuditService::log('platform.risk.review', [
            'assessment_id' => $assessment->id, 'subject' => $assessment->subject_type.':'.$assessment->subject_id,
            'from' => $before, 'to' => $v['status'], 'note' => $v['note'],
        ], $request);

        return back()->with('success', 'Análise registrada.');
    }
}

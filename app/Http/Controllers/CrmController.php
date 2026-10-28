<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Crm\CrmService;
use App\Services\TeamAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Brasa CRM — compradores do seller (derivados dos pedidos), segmentos, tags e notas. */
class CrmController extends Controller
{
    public function __construct(private CrmService $crm) {}

    public function index(Request $request): Response
    {
        $f = $request->validate([
            'segment' => ['nullable', Rule::in(array_keys(CrmService::SEGMENTS))],
            'stage' => ['nullable', Rule::in(array_keys(CrmService::STAGES))],
            'view' => ['nullable', Rule::in(['lista', 'funil'])],
            'q' => ['nullable', 'string', 'max:120'],
            'product' => ['nullable', 'string', 'max:64'],
        ]);
        [$tenantId, $allowed] = $this->scope();
        $productId = $f['product'] ?? null;
        if ($productId !== null && $allowed !== null && ! in_array($productId, $allowed, true)) {
            abort(403);
        }
        $view = $f['view'] ?? 'lista';
        $stage = $f['stage'] ?? null;
        $segment = $stage !== null ? 'all' : ($f['segment'] ?? 'all');

        $withKeys = function (array $rows) use ($tenantId, $allowed) {
            $trails = $this->crm->trails($tenantId, $allowed, array_column($rows, 'email'));

            return collect($rows)->map(fn ($c) => $c + ['key' => self::key($c['email']), 'trail' => $trails[$c['email']] ?? []])->values();
        };

        $contacts = null;
        $pipeline = null;
        if ($view === 'funil') {
            $pipeline = collect($this->crm->pipeline($tenantId, $allowed, $productId))->map(fn ($rows) => $withKeys($rows));
        } else {
            $page = $this->crm->contacts($tenantId, $allowed, $segment, $f['q'] ?? null, $productId, 24, $stage);
            $contacts = [
                'data' => $withKeys($page->items()),
                'links' => $page->linkCollection(),
                'total' => $page->total(),
            ];
        }

        return Inertia::render('Crm/Index', [
            'filters' => ['segment' => $segment, 'stage' => $stage, 'view' => $view, 'q' => $f['q'] ?? '', 'product' => $productId],
            'segments' => CrmService::SEGMENTS,
            'stages' => CrmService::STAGES,
            'summary' => Cache::remember('crm:summary:'.$tenantId.':'.md5(json_encode([$allowed, $productId])), 120,
                fn () => $this->crm->summary($tenantId, $allowed, $productId)),
            'products' => Product::query()->where('tenant_id', $tenantId)
                ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed ?: ['__none__']))
                ->orderBy('name')->get(['id', 'name'])->map(fn ($p) => ['id' => (string) $p->id, 'name' => $p->name]),
            'contacts' => $contacts,
            'pipeline' => $pipeline,
        ]);
    }

    public function show(Request $request, string $key): Response|JsonResponse
    {
        [$tenantId, $allowed] = $this->scope();
        $email = self::email($key);
        $contact = $this->crm->contact($tenantId, $allowed, $email);
        abort_if($contact === null, 404);
        $contact += ['key' => $key, 'placeholder' => CrmService::placeholderLabel($email)];

        // O painel lateral da lista busca o mesmo contato em JSON.
        return $request->wantsJson() ? response()->json(['contact' => $contact]) : Inertia::render('Crm/Show', ['contact' => $contact]);
    }

    public function update(Request $request, string $key): RedirectResponse|JsonResponse
    {
        $v = $request->validate([
            'tags' => ['present', 'array', 'max:10'],
            'tags.*' => ['string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        [$tenantId, $allowed] = $this->scope();
        $email = self::email($key);
        abort_if($this->crm->contact($tenantId, $allowed, $email) === null, 404);
        $meta = $this->crm->saveMeta($tenantId, $email, $v['tags'], $v['notes'] ?? null, (int) $request->user()->id);

        return $request->wantsJson()
            ? response()->json(['tags' => $meta->tags ?? [], 'notes' => $meta->notes ?? ''])
            : back()->with('success', 'Contato atualizado.');
    }

    /** @return array{0:int, 1:list<string>|null} */
    private function scope(): array
    {
        $user = auth()->user();
        $allowed = $user->isTeam() ? array_map('strval', app(TeamAccessService::class)->allowedProductIdsFor($user)) : null;

        return [(int) $user->tenant_id, $allowed];
    }

    private static function key(string $email): string
    {
        return rtrim(strtr(base64_encode($email), '+/', '-_'), '=');
    }

    private static function email(string $key): string
    {
        $email = base64_decode(strtr($key, '-_', '+/'), true);
        abort_unless(is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL), 404);

        return mb_strtolower($email);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Crm\CrmService;
use App\Services\TeamAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'q' => ['nullable', 'string', 'max:120'],
            'product' => ['nullable', 'string', 'max:64'],
        ]);
        [$tenantId, $allowed] = $this->scope();
        $productId = $f['product'] ?? null;
        if ($productId !== null && $allowed !== null && ! in_array($productId, $allowed, true)) {
            abort(403);
        }

        $page = $this->crm->contacts($tenantId, $allowed, $f['segment'] ?? 'all', $f['q'] ?? null, $productId);

        return Inertia::render('Crm/Index', [
            'filters' => ['segment' => $f['segment'] ?? 'all', 'q' => $f['q'] ?? '', 'product' => $productId],
            'segments' => CrmService::SEGMENTS,
            'products' => Product::query()->where('tenant_id', $tenantId)
                ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed ?: ['__none__']))
                ->orderBy('name')->get(['id', 'name'])->map(fn ($p) => ['id' => (string) $p->id, 'name' => $p->name]),
            'contacts' => [
                'data' => collect($page->items())->map(fn ($c) => $c + ['key' => self::key($c['email'])])->values(),
                'links' => $page->linkCollection(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(string $key): Response
    {
        [$tenantId, $allowed] = $this->scope();
        $contact = $this->crm->contact($tenantId, $allowed, self::email($key));
        abort_if($contact === null, 404);

        return Inertia::render('Crm/Show', ['contact' => $contact + ['key' => $key]]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        $v = $request->validate([
            'tags' => ['present', 'array', 'max:10'],
            'tags.*' => ['string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        [$tenantId, $allowed] = $this->scope();
        $email = self::email($key);
        abort_if($this->crm->contact($tenantId, $allowed, $email) === null, 404);
        $this->crm->saveMeta($tenantId, $email, $v['tags'], $v['notes'] ?? null, (int) $request->user()->id);

        return back()->with('success', 'Contato atualizado.');
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

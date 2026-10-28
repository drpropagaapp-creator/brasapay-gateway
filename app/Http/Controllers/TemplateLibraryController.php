<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceInstall;
use App\Models\MarketplaceReview;
use App\Models\MarketplaceTemplate;
use App\Models\MarketplaceTemplateVersion;
use App\Models\Product;
use App\Models\ProductImport;
use App\Services\Marketplace\TemplateLibraryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Brasa Marketplace (seller) — catálogo, prévia, instalação, avaliações e envio de templates. */
class TemplateLibraryController extends Controller
{
    public function __construct(private TemplateLibraryService $library) {}

    public function index(Request $request): Response
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', Rule::in(array_keys(MarketplaceTemplate::CATEGORIES))],
            'sort' => ['nullable', Rule::in(['popular', 'rating', 'new'])],
        ]);
        $q = MarketplaceTemplate::query()->where('status', MarketplaceTemplate::STATUS_PUBLISHED)
            ->when($f['category'] ?? null, fn ($w, $c) => $w->where('category', $c))
            ->when($f['q'] ?? null, function ($w, $term) {
                $like = '%'.mb_strtolower(str_replace(['%', '_'], '', $term)).'%';
                $w->where(fn ($x) => $x->whereRaw('LOWER(title) LIKE ?', [$like])->orWhereRaw('LOWER(COALESCE(tagline, \'\')) LIKE ?', [$like]));
            });
        match ($f['sort'] ?? 'popular') {
            'rating' => $q->orderByRaw('CASE WHEN rating_count = 0 THEN 0 ELSE rating_sum * 1.0 / rating_count END DESC'),
            'new' => $q->latest('id'),
            default => $q->orderByDesc('is_official')->orderByDesc('installs_count'),
        };
        $tenantId = (int) $request->user()->tenant_id;

        return Inertia::render('TemplateLibrary/Index', [
            'filters' => ['q' => $f['q'] ?? '', 'category' => $f['category'] ?? null, 'sort' => $f['sort'] ?? 'popular'],
            'categories' => MarketplaceTemplate::CATEGORIES,
            'templates' => $q->limit(60)->get()->map(fn ($t) => $this->card($t))->values(),
            'can_publish' => ! $request->user()->isTeam(),
            'mine' => $request->user()->isTeam() ? [] : MarketplaceTemplate::query()->where('author_tenant_id', $tenantId)->latest('id')->get()
                ->map(fn ($t) => $this->card($t) + [
                    'status' => $t->status,
                    'rejection_reason' => $t->rejection_reason,
                    'pending_version' => MarketplaceTemplateVersion::query()->where('template_id', $t->id)->where('status', MarketplaceTemplate::STATUS_PENDING)->value('version'),
                ])->values(),
            'products' => $request->user()->isTeam() ? [] : Product::query()->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($p) => ['id' => (string) $p->id, 'name' => $p->name])->values(),
        ]);
    }

    public function show(Request $request, string $slug): Response
    {
        $tenantId = (int) $request->user()->tenant_id;
        $t = MarketplaceTemplate::query()->where('slug', $slug)->firstOrFail();
        $isAuthor = (int) $t->author_tenant_id === $tenantId && $tenantId > 0;
        abort_unless($t->status === MarketplaceTemplate::STATUS_PUBLISHED || $isAuthor, 404);

        $current = $t->current_version_id ? MarketplaceTemplateVersion::query()->find($t->current_version_id) : null;
        $myInstalls = MarketplaceInstall::query()->where('template_id', $t->id)->where('tenant_id', $tenantId)->latest('id')->limit(10)->get();
        $versions = MarketplaceTemplateVersion::query()->where('template_id', $t->id)->where('status', MarketplaceTemplate::STATUS_PUBLISHED)
            ->orderByDesc('version')->get(['id', 'version', 'changelog', 'reviewed_at']);

        return Inertia::render('TemplateLibrary/Show', [
            'template' => $this->card($t) + ['description' => $t->description, 'status' => $t->status, 'is_author' => $isAuthor],
            'summary' => $current?->summary,
            'current_version' => $current?->version,
            'versions' => $versions->map(fn ($v) => ['version' => $v->version, 'changelog' => $v->changelog, 'at' => $v->reviewed_at?->toIso8601String()])->values(),
            'reviews' => MarketplaceReview::query()->where('template_id', $t->id)->where('status', 'approved')->latest('id')->limit(30)
                ->get(['rating', 'comment', 'created_at'])->map(fn ($r) => ['rating' => $r->rating, 'comment' => $r->comment, 'at' => $r->created_at?->toIso8601String()])->values(),
            'my_installs' => $myInstalls->map(function ($i) use ($versions) {
                $import = $i->product_import_id ? ProductImport::query()->find($i->product_import_id) : null;

                return ['version' => optional($versions->firstWhere('id', $i->version_id))->version, 'status' => $import?->status ?? $i->status,
                    'import_uuid' => $import?->uuid, 'at' => $i->created_at?->toIso8601String()];
            })->values(),
            'update_available' => $current && $myInstalls->isNotEmpty() && $myInstalls->first()->version_id !== $current->id,
            'can_install' => ! $request->user()->isTeam() && $t->status === MarketplaceTemplate::STATUS_PUBLISHED,
            'can_review' => ! $request->user()->isTeam() && ! $isAuthor && $myInstalls->isNotEmpty(),
            'my_review' => MarketplaceReview::query()->where('template_id', $t->id)->where('tenant_id', $tenantId)->first(['rating', 'comment', 'status']),
        ]);
    }

    public function install(Request $request, string $slug): RedirectResponse
    {
        $t = MarketplaceTemplate::query()->where('slug', $slug)->firstOrFail();
        $result = $this->library->install($t, $request->user());

        if ($result['import'] !== null) {
            return redirect()->route('produtos.import.show', $result['import'])
                ->with('success', 'Confira a prévia e confirme para criar os produtos na sua conta.');
        }

        return redirect('/recuperacao')->with('success', 'Textos de recuperação instalados. A recuperação continua como estava (ligada ou desligada).');
    }

    public function review(Request $request, string $slug): RedirectResponse
    {
        $v = $request->validate(['rating' => ['required', 'integer', 'min:1', 'max:5'], 'comment' => ['nullable', 'string', 'max:1000']]);
        $t = MarketplaceTemplate::query()->where('slug', $slug)->where('status', MarketplaceTemplate::STATUS_PUBLISHED)->firstOrFail();
        $this->library->review($t, $request->user(), (int) $v['rating'], $v['comment'] ?? null);

        return back()->with('success', 'Avaliação enviada. Ela aparece depois da moderação.');
    }

    public function submit(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'kind' => ['required', Rule::in([MarketplaceTemplate::KIND_PACKAGE, MarketplaceTemplate::KIND_RECOVERY])],
            'template_id' => ['nullable', 'integer'],
            'product_id' => ['required_if:kind,'.MarketplaceTemplate::KIND_PACKAGE, 'nullable', 'string', 'max:64'],
            'title' => ['required_without:template_id', 'nullable', 'string', 'max:120'],
            'category' => ['nullable', Rule::in(array_keys(MarketplaceTemplate::CATEGORIES))],
            'tagline' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'changelog' => ['nullable', 'string', 'max:1000'],
            'confirm_public' => ['accepted'],
        ]);
        $template = isset($v['template_id'])
            ? MarketplaceTemplate::query()->whereKey($v['template_id'])->where('author_tenant_id', (int) $request->user()->tenant_id)->firstOrFail()
            : null;

        if ($v['kind'] === MarketplaceTemplate::KIND_PACKAGE) {
            $product = Product::query()->whereKey($v['product_id'])->where('tenant_id', (int) $request->user()->tenant_id)->firstOrFail();
            $this->library->submitProduct($request->user(), $product, $v, $template);
        } else {
            $this->library->submitRecovery($request->user(), $v, $template);
        }

        return back()->with('success', 'Enviado para revisão. O template aparece no catálogo depois de aprovado.');
    }

    /** @return array<string, mixed> */
    private function card(MarketplaceTemplate $t): array
    {
        return [
            'id' => $t->id, 'slug' => $t->slug, 'title' => $t->title, 'tagline' => $t->tagline, 'kind' => $t->kind,
            'category' => $t->category, 'category_label' => MarketplaceTemplate::CATEGORIES[$t->category] ?? $t->category,
            'author' => $t->is_official ? 'Oficial' : $t->author_name, 'is_official' => $t->is_official,
            'installs' => $t->installs_count, 'rating' => $t->ratingAverage(), 'rating_count' => $t->rating_count, 'free' => $t->price_cents === 0,
        ];
    }
}

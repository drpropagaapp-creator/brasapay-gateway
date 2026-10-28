<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceReview;
use App\Models\MarketplaceTemplate;
use App\Models\MarketplaceTemplateVersion;
use App\Services\Marketplace\TemplateLibraryService;
use App\Services\ProductPortability\PackageFormat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Brasa Marketplace (admin) — revisão de versões, publicação, templates oficiais e moderação de avaliações. */
class TemplateLibraryAdminController extends Controller
{
    public function __construct(private TemplateLibraryService $library) {}

    public function index(): Response
    {
        $pending = MarketplaceTemplateVersion::query()->where('status', MarketplaceTemplate::STATUS_PENDING)->oldest('id')->limit(100)->get();
        $templates = MarketplaceTemplate::query()->whereIn('id', $pending->pluck('template_id'))->get()->keyBy('id');

        return Inertia::render('Platform/TemplateLibrary/Index', [
            'categories' => MarketplaceTemplate::CATEGORIES,
            'max_mb' => (int) (PackageFormat::MAX_PACKAGE_BYTES / 1024 / 1024),
            'pending' => $pending->map(fn ($v) => [
                'id' => $v->id, 'version' => $v->version, 'changelog' => $v->changelog, 'summary' => $v->summary,
                // Revisão vê o conteúdo integral: textos de recuperação e os links que eles contêm.
                'content' => $v->content,
                'links' => TemplateLibraryService::extractLinks($v->content),
                'has_package' => (bool) $v->package_path,
                'created_at' => $v->created_at?->toIso8601String(),
                'template' => ($t = $templates->get($v->template_id)) ? ['id' => $t->id, 'title' => $t->title, 'kind' => $t->kind,
                    'category' => MarketplaceTemplate::CATEGORIES[$t->category] ?? $t->category, 'author' => $t->author_name,
                    'author_tenant_id' => $t->author_tenant_id, 'tagline' => $t->tagline, 'description' => $t->description] : null,
            ])->values(),
            'templates' => MarketplaceTemplate::query()->whereIn('status', [MarketplaceTemplate::STATUS_PUBLISHED, MarketplaceTemplate::STATUS_UNPUBLISHED])
                ->latest('id')->limit(200)->get()->map(fn ($t) => [
                    'id' => $t->id, 'title' => $t->title, 'slug' => $t->slug, 'status' => $t->status, 'is_official' => $t->is_official,
                    'author' => $t->author_name, 'installs' => $t->installs_count, 'rating' => $t->ratingAverage(),
                ])->values(),
            'reviews' => MarketplaceReview::query()->where('status', 'pending')->oldest('id')->limit(100)->get()
                ->map(fn ($r) => ['id' => $r->id, 'template_id' => $r->template_id, 'rating' => $r->rating, 'comment' => $r->comment,
                    'template' => MarketplaceTemplate::query()->whereKey($r->template_id)->value('title')])->values(),
        ]);
    }

    /** Baixa o pacote enviado para o admin conferir (por exemplo, importando numa conta de teste). */
    public function downloadPackage(MarketplaceTemplateVersion $version): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $path = $version->absolutePackagePath();
        abort_unless($path !== null && is_file($path), 404);
        \App\Services\PlatformAuditService::log('marketplace.version.downloaded', ['version_id' => $version->id, 'template_id' => $version->template_id]);

        return response()->download($path, 'template-'.$version->template_id.'-v'.$version->version.'.brasapay.zip', ['Content-Type' => 'application/zip']);
    }

    public function review(Request $request, MarketplaceTemplateVersion $version): RedirectResponse
    {
        $v = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:500']]);
        $v['decision'] === 'approve'
            ? $this->library->approve($version, $request->user())
            : $this->library->reject($version, $request->user(), (string) $v['reason']);

        return back()->with('success', $v['decision'] === 'approve' ? 'Versão aprovada e publicada.' : 'Versão recusada.');
    }

    public function publication(Request $request, MarketplaceTemplate $template): RedirectResponse
    {
        $v = $request->validate(['published' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->library->setPublished($template, (bool) $v['published'], $v['reason']);

        return back()->with('success', 'Template atualizado.');
    }

    public function storeOfficial(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'package' => ['required', 'file', 'max:'.(int) (PackageFormat::MAX_PACKAGE_BYTES / 1024)],
            'title' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(array_keys(MarketplaceTemplate::CATEGORIES))],
            'tagline' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);
        $this->library->createOfficialFromPackage($request->user(), $request->file('package')->getRealPath(), $v);

        return back()->with('success', 'Template oficial publicado.');
    }

    public function moderateReview(Request $request, MarketplaceReview $review): RedirectResponse
    {
        $v = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])]]);
        $this->library->moderateReview($review, $request->user(), $v['decision'] === 'approve');

        return back()->with('success', 'Avaliação moderada.');
    }
}

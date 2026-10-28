<?php

namespace App\Http\Controllers;

use App\Models\Experiment;
use App\Models\LandingPageVersion;
use App\Models\Product;
use App\Services\Experiments\ExperimentService;
use App\Services\TeamAccessService;
use App\Support\CheckoutConfigUrlSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Brasa Experiments — A/B da landing do checkout (seller). */
class ExperimentsController extends Controller
{
    public function __construct(private ExperimentService $experiments) {}

    public function index(Request $request): Response
    {
        [$tenantId, $allowed] = $this->scope();
        $products = Product::query()->where('tenant_id', $tenantId)
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed ?: ['__none__']))
            ->orderBy('name')->get(['id', 'name']);
        $versions = LandingPageVersion::query()->whereIn('product_id', $products->pluck('id')->map(fn ($id) => (string) $id))
            ->where('scope', 'product')->latest('id')->limit(200)->get(['id', 'product_id', 'created_at', 'landing']);

        $list = Experiment::query()->where('tenant_id', $tenantId)
            ->whereIn('product_id', $products->pluck('id')->map(fn ($id) => (string) $id))
            ->latest('id')->limit(50)->get()
            ->map(fn (Experiment $e) => [
                'id' => $e->id, 'name' => $e->name, 'status' => $e->status, 'product_id' => $e->product_id,
                'product' => $products->firstWhere('id', $e->product_id)?->name,
                'started_at' => $e->started_at?->toIso8601String(), 'ended_at' => $e->ended_at?->toIso8601String(),
                'winner_key' => $e->winner_key,
                'weights' => collect($e->variants)->mapWithKeys(fn ($v) => [$v['key'] => (int) $v['weight']]),
                'results' => $this->experiments->results($e),
            ]);

        return Inertia::render('Experiments/Index', [
            'experiments' => $list,
            'products' => $products->map(fn ($p) => ['id' => (string) $p->id, 'name' => $p->name]),
            'versions' => $versions->map(fn ($v) => [
                'id' => $v->id, 'product_id' => (string) $v->product_id, 'created_at' => $v->created_at?->toIso8601String(),
                'blocks' => count(is_array($v->landing['blocks'] ?? null) ? $v->landing['blocks'] : []),
            ]),
            'min' => ['sessions' => ExperimentService::MIN_SESSIONS_PER_VARIANT, 'conversions' => ExperimentService::MIN_CONVERSIONS_TOTAL],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'product_id' => ['required', 'string', 'max:64'],
            'version_id' => ['required', 'integer'],
            'weight_b' => ['required', 'integer', 'min:10', 'max:90'],
        ]);
        $product = $this->product($v['product_id']);
        $version = LandingPageVersion::query()->whereKey($v['version_id'])->where('product_id', (string) $product->id)->firstOrFail();
        // A versão salva já foi sanitizada no salvamento; sanitiza de novo por segurança antes de servir.
        $landing = CheckoutConfigUrlSanitizer::sanitize(['landing' => $version->landing])['landing'] ?? null;
        abort_unless(is_array($landing), 422, 'Versão de landing inválida.');

        Experiment::query()->create([
            'tenant_id' => (int) $product->tenant_id,
            'product_id' => (string) $product->id,
            'name' => $v['name'],
            'status' => Experiment::STATUS_DRAFT,
            'variants' => [
                ['key' => 'A', 'label' => 'A — landing publicada', 'weight' => 100 - $v['weight_b'], 'landing' => null],
                ['key' => 'B', 'label' => 'B — versão de '.$version->created_at?->format('d/m H:i'), 'weight' => $v['weight_b'], 'landing' => $landing],
            ],
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Experimento criado. Inicie quando quiser.');
    }

    public function transition(Request $request, Experiment $experiment): RedirectResponse
    {
        $this->authorizeExperiment($experiment);
        $v = $request->validate(['action' => ['required', Rule::in(['start', 'pause', 'finish'])]]);

        if ($v['action'] === 'start') {
            abort_if($experiment->status === Experiment::STATUS_FINISHED, 422, 'Experimento encerrado.');
            $running = Experiment::query()->where('product_id', $experiment->product_id)->where('status', Experiment::STATUS_RUNNING)->whereKeyNot($experiment->id)->exists();
            abort_if($running, 422, 'Já existe um experimento rodando neste produto.');
            $experiment->update(['status' => Experiment::STATUS_RUNNING, 'started_at' => $experiment->started_at ?? now()]);
        } elseif ($v['action'] === 'pause') {
            $experiment->update(['status' => Experiment::STATUS_PAUSED]);
        } else {
            $experiment->update(['status' => Experiment::STATUS_FINISHED, 'ended_at' => now()]);
        }

        return back()->with('success', 'Experimento atualizado.');
    }

    /** Publica a variante B como landing do produto — só se ela venceu com significância. */
    public function applyWinner(Request $request, Experiment $experiment): RedirectResponse
    {
        $this->authorizeExperiment($experiment);
        $verdict = $this->experiments->results($experiment)['verdict'];
        abort_unless(($verdict['status'] ?? null) === 'winner', 422, 'Ainda não há vencedora com significância estatística.');

        if ($verdict['winner'] === 'B') {
            $product = $this->product($experiment->product_id);
            $landing = collect($experiment->variants)->firstWhere('key', 'B')['landing'] ?? null;
            $config = is_array($product->getRawOriginal('checkout_config')) ? $product->getRawOriginal('checkout_config') : (json_decode((string) $product->getRawOriginal('checkout_config'), true) ?: []);
            $before = is_array($config['landing'] ?? null) ? $config['landing'] : null;
            $config['landing'] = $landing;
            $config['template'] = 'landing';
            $product->update(['checkout_config' => $config]);
            LandingPageVersion::recordIfChanged($product, 'product', null, $before, $landing, $request->user()->id);
        }
        $experiment->update(['status' => Experiment::STATUS_FINISHED, 'ended_at' => $experiment->ended_at ?? now(), 'winner_key' => $verdict['winner']]);

        return back()->with('success', $verdict['winner'] === 'B' ? 'Variante B publicada como landing do produto.' : 'A landing atual venceu e foi mantida.');
    }

    /** @return array{0:int, 1:list<string>|null} */
    private function scope(): array
    {
        $user = auth()->user();

        return [(int) $user->tenant_id, $user->isTeam() ? array_map('strval', app(TeamAccessService::class)->allowedProductIdsFor($user)) : null];
    }

    private function product(string $id): Product
    {
        [$tenantId, $allowed] = $this->scope();
        $product = Product::query()->whereKey($id)->where('tenant_id', $tenantId)->firstOrFail();
        abort_if($allowed !== null && ! in_array((string) $product->id, $allowed, true), 403);

        return $product;
    }

    private function authorizeExperiment(Experiment $experiment): void
    {
        abort_unless((int) $experiment->tenant_id === (int) auth()->user()->tenant_id, 404);
        $this->product($experiment->product_id);
    }
}

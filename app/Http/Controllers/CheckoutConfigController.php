<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LogsSellerActivity;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Services\SellerActivityLogService;
use App\Services\StorageService;
use App\Support\CheckoutConfigUrlSanitizer;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class CheckoutConfigController extends Controller
{
    use LogsSellerActivity;
    public function edit(Request $request, Product $produto): Response
    {
        $this->authorizeProduct($produto);

        $offerId = $request->integer('offer_id', 0) ?: null;
        $planId = $request->integer('plan_id', 0) ?: null;

        $defaults = Product::defaultCheckoutConfig();
        $checkoutSlug = $produto->checkout_slug;
        $scope = ['type' => 'main', 'offer_id' => null, 'plan_id' => null, 'checkout_slug' => $checkoutSlug, 'label' => 'Produto (preço base)'];

        if ($offerId) {
            $offer = ProductOffer::where('id', $offerId)->where('product_id', $produto->id)->firstOrFail();
            $stored = $offer->checkout_config ?? [];
            $config = array_replace_recursive($defaults, $stored);
            $checkoutSlug = $offer->checkout_slug;
            $scope = ['type' => 'offer', 'offer_id' => $offer->id, 'plan_id' => null, 'checkout_slug' => $checkoutSlug, 'label' => 'Oferta: ' . $offer->name];
        } elseif ($planId) {
            $plan = SubscriptionPlan::where('id', $planId)->where('product_id', $produto->id)->firstOrFail();
            $stored = $plan->checkout_config ?? [];
            $config = array_replace_recursive($defaults, $stored);
            $checkoutSlug = $plan->checkout_slug;
            $scope = ['type' => 'plan', 'offer_id' => null, 'plan_id' => $plan->id, 'checkout_slug' => $checkoutSlug, 'label' => 'Plano: ' . $plan->name];
        } else {
            $stored = $produto->checkout_config ?? [];
            $config = array_replace_recursive($defaults, $stored);
        }

        $tenantId = auth()->user()->tenant_id;
        $cupons = Coupon::forTenant($tenantId)
            ->whereHas('products', fn ($q) => $q->where('products.id', $produto->id))
            ->orderBy('code')
            ->get(['id', 'code', 'type', 'value'])
            ->map(fn (Coupon $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'type' => $c->type,
                'value' => (float) $c->value,
            ])
            ->values()
            ->all();

        return Inertia::render('Checkout/Builder', [
            'produto' => [
                'id' => $produto->id,
                'name' => $produto->name,
                'checkout_slug' => $checkoutSlug,
            ],
            'config' => $config,
            'checkout_scope' => $scope,
            'cupons' => $cupons,
            'layoutFullWidth' => true,
        ]);
    }

    public function update(Request $request, Product $produto): RedirectResponse
    {
        $this->authorizeProduct($produto);

        $defaults = Product::defaultCheckoutConfig();
        $validated = $request->validate([
            'config' => ['required', 'array'],
            'offer_id' => ['nullable', 'integer'],
            'plan_id' => ['nullable', 'integer'],
        ]);

        $offerId = $validated['offer_id'] ?? null;
        $planId = $validated['plan_id'] ?? null;

        $stored = [];
        if ($offerId) {
            $offer = ProductOffer::where('id', $offerId)->where('product_id', $produto->id)->firstOrFail();
            $stored = $offer->checkout_config ?? [];
        } elseif ($planId) {
            $plan = SubscriptionPlan::where('id', $planId)->where('product_id', $produto->id)->firstOrFail();
            $stored = $plan->checkout_config ?? [];
        } else {
            $stored = $produto->checkout_config ?? [];
        }

        $base = array_replace_recursive($defaults, is_array($stored) ? $stored : []);
        $merged = array_replace_recursive($base, $validated['config']);
        $merged = $this->applyCheckoutConfigIndexedArraysFromRequest($merged, $validated['config']);

        unset($merged['advanced']);

        $merged = CheckoutConfigUrlSanitizer::sanitize($merged);

        // Downsell só pode estar ativo se upsell estiver ativo
        if (!($merged['upsell']['enabled'] ?? false)) {
            $merged['downsell']['enabled'] = false;
        }

        // Oferta/plano: não persistir chaves mantidas só no produto. Upsell/downsell também:
        // o builder manda o padrão desativado e isso escondia a oferta depois da compra.
        if ($offerId || $planId) {
            foreach (['payment_gateways', 'card_installments', 'stripe_link_enabled', 'payment_methods_enabled', 'deliverable_link', 'email_template', 'upsell', 'downsell'] as $inheritKey) {
                unset($merged[$inheritKey]);
            }
        }

        $this->logSellerActivity(SellerActivityLogService::PRODUCT_CHECKOUT_UPDATED, $produto, [
            'name' => $produto->name,
            'offer_id' => $offerId,
            'plan_id' => $planId,
        ]);

        $scope = $offerId ? 'offer' : ($planId ? 'plan' : 'product');
        \App\Models\LandingPageVersion::recordIfChanged(
            $produto,
            $scope,
            $offerId ? (int) $offerId : ($planId ? (int) $planId : null),
            is_array($stored['landing'] ?? null) ? $stored['landing'] : null,
            is_array($merged['landing'] ?? null) ? $merged['landing'] : null,
            $request->user()?->id
        );

        if ($offerId) {
            $offer->update(['checkout_config' => $merged]);
            return $request->expectsJson()
                ? response()->json(['success' => true, 'message' => 'Checkout da oferta atualizado.'])
                : back()->with('success', 'Checkout da oferta atualizado.');
        }
        if ($planId) {
            $plan->update(['checkout_config' => $merged]);
            return $request->expectsJson()
                ? response()->json(['success' => true, 'message' => 'Checkout do plano atualizado.'])
                : back()->with('success', 'Checkout do plano atualizado.');
        }

        $produto->update(['checkout_config' => $merged]);
        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => 'Checkout atualizado.'])
            : back()->with('success', 'Checkout atualizado.');
    }

    /** Biblioteca de mídia do produto: imagens já enviadas para o checkout/landing. */
    public function mediaLibrary(Product $produto): JsonResponse
    {
        $this->authorizeProduct($produto);
        $storage = new StorageService((int) $produto->tenant_id);
        $disk = $storage->disk();
        $dir = 'checkout/'.$produto->id;

        try {
            $files = $disk->allFiles($dir);
        } catch (\Throwable) {
            $files = [];
        }

        $items = [];
        foreach ($files as $path) {
            if (! preg_match('/\.(jpe?g|png|webp|gif)$/i', $path)) {
                continue;
            }
            try {
                $modified = $disk->lastModified($path);
                $size = $disk->size($path);
            } catch (\Throwable) {
                $modified = 0;
                $size = null;
            }
            $items[] = [
                'path' => $path,
                'url' => $storage->url($path),
                'name' => basename($path),
                'size' => $size,
                'modified' => $modified,
            ];
        }
        usort($items, fn ($a, $b) => $b['modified'] <=> $a['modified']);

        return response()->json(['items' => array_slice($items, 0, 300)]);
    }

    /** Remove uma imagem da biblioteca — recusa se ainda estiver em uso no produto. */
    public function deleteMedia(Request $request, Product $produto): JsonResponse
    {
        $this->authorizeProduct($produto);
        $validated = $request->validate(['path' => ['required', 'string', 'max:500']]);
        $path = $validated['path'];

        if (str_contains($path, '..') || ! str_starts_with($path, 'checkout/'.$produto->id.'/')) {
            abort(403);
        }

        $storage = new StorageService((int) $produto->tenant_id);
        $url = $storage->url($path);
        $haystacks = array_merge(
            [json_encode($produto->checkout_config), json_encode($produto->member_area_config), (string) $produto->image],
            ProductOffer::query()->where('product_id', $produto->id)->pluck('checkout_config')->map(fn ($c) => json_encode($c))->all(),
            SubscriptionPlan::query()->where('product_id', $produto->id)->pluck('checkout_config')->map(fn ($c) => json_encode($c))->all(),
        );
        $needles = [$url, str_replace('/', '\/', $url), basename($path)];
        foreach ($haystacks as $h) {
            foreach ($needles as $n) {
                if ($h !== false && $h !== null && $n !== '' && str_contains((string) $h, $n)) {
                    return response()->json(['message' => 'Esta imagem está em uso no checkout, numa oferta, num plano ou na área de membros. Remova-a de lá antes de apagar.'], 422);
                }
            }
        }

        $storage->disk()->delete($path);

        return response()->json(['deleted' => true]);
    }

    /** Versões publicadas da landing (produto, oferta ou plano), mais recentes primeiro. */
    public function landingVersions(Request $request, Product $produto): JsonResponse
    {
        $this->authorizeProduct($produto);
        [$scope, $scopeId] = $this->landingScopeFromRequest($request);

        $versions = \App\Models\LandingPageVersion::query()
            ->where('product_id', (string) $produto->id)
            ->where('scope', $scope)
            ->where('scope_id', $scopeId)
            ->orderByDesc('id')
            ->limit(\App\Models\LandingPageVersion::KEEP)
            ->get(['id', 'landing', 'created_by_user_id', 'created_at'])
            ->map(fn ($v) => [
                'id' => $v->id,
                'created_at' => $v->created_at?->toIso8601String(),
                'mode' => ($v->landing['mode'] ?? 'sections') === 'blocks' ? 'blocks' : 'sections',
                'blocks_count' => is_array($v->landing['blocks'] ?? null) ? count($v->landing['blocks']) : 0,
            ]);

        return response()->json(['versions' => $versions]);
    }

    public function landingVersion(Product $produto, int $version): JsonResponse
    {
        $this->authorizeProduct($produto);
        $row = \App\Models\LandingPageVersion::query()
            ->where('product_id', (string) $produto->id)
            ->whereKey($version)
            ->firstOrFail();

        return response()->json(['landing' => $row->landing]);
    }

    /** @return array{0: string, 1: int|null} */
    private function landingScopeFromRequest(Request $request): array
    {
        if ($request->filled('offer_id')) {
            return ['offer', (int) $request->input('offer_id')];
        }
        if ($request->filled('plan_id')) {
            return ['plan', (int) $request->input('plan_id')];
        }

        return ['product', null];
    }

    public function uploadImage(Request $request, Product $produto): JsonResponse
    {
        $this->authorizeProduct($produto);

        $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        $storage = app(StorageService::class);
        $file = $request->file('image');
        $name = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $storage->putFileAs('checkout/' . $produto->id, $file, $name);
        $url = $storage->url($path);

        return response()->json(['url' => $url], HttpResponse::HTTP_CREATED);
    }

    private function authorizeProduct(Product $produto): void
    {
        $tenantId = auth()->user()->tenant_id;
        if ($produto->tenant_id !== $tenantId) {
            abort(403);
        }
    }

    /**
     * array_replace_recursive não substitui listas indexadas por [] (merge recursivo mantém índices antigos).
     * Sobrescreve chaves que são arrays sequenciais quando o cliente envia o valor completo.
     *
     * @param  array<string, mixed>  $merged
     * @param  array<string, mixed>  $requestConfig
     * @return array<string, mixed>
     */
    private function applyCheckoutConfigIndexedArraysFromRequest(array $merged, array $requestConfig): array
    {
        if (array_key_exists('reviews', $requestConfig)) {
            $merged['reviews'] = is_array($requestConfig['reviews'])
                ? array_values($requestConfig['reviews'])
                : [];
        }

        if (isset($requestConfig['landing']) && is_array($requestConfig['landing'])) {
            if (! isset($merged['landing']) || ! is_array($merged['landing'])) {
                $merged['landing'] = [];
            }
            foreach (['images', 'sections', 'blocks'] as $listKey) {
                if (array_key_exists($listKey, $requestConfig['landing'])) {
                    $merged['landing'][$listKey] = is_array($requestConfig['landing'][$listKey])
                        ? array_values($requestConfig['landing'][$listKey])
                        : [];
                }
            }
        }

        if (isset($requestConfig['appearance']) && is_array($requestConfig['appearance'])) {
            $appearance = $requestConfig['appearance'];
            if (! isset($merged['appearance']) || ! is_array($merged['appearance'])) {
                $merged['appearance'] = [];
            }
            if (array_key_exists('banners', $appearance)) {
                $merged['appearance']['banners'] = is_array($appearance['banners'])
                    ? array_values($appearance['banners'])
                    : [];
            }
            if (array_key_exists('side_banners', $appearance)) {
                $merged['appearance']['side_banners'] = is_array($appearance['side_banners'])
                    ? array_values($appearance['side_banners'])
                    : [];
            }
        }

        return $merged;
    }
}

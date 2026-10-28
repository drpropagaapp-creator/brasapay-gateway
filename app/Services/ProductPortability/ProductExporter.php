<?php

namespace App\Services\ProductPortability;

use App\Models\MemberCommunityPage;
use App\Models\MemberInternalProduct;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\ProductCoproducer;
use App\Models\ProductOffer;
use App\Models\ProductOrderBump;
use App\Models\SubscriptionPlan;
use App\Services\StorageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Gera o pacote .brasapay.zip de um produto e, opcionalmente, dos produtos de
 * que ele depende (bump, upsell, downsell, área de membros) — sempre do mesmo tenant.
 */
class ProductExporter
{
    /** Colunas comerciais/visuais do produto que viajam no pacote. */
    private const PRODUCT_FIELDS = [
        'name', 'notification_name', 'description', 'category', 'type', 'billing_type', 'price', 'currency',
        'refund_policy_days', 'physical_config', 'affiliate_enabled', 'affiliate_commission_percent',
        'affiliate_manual_approval', 'affiliate_show_in_showcase', 'affiliate_page_url',
        'affiliate_support_email', 'affiliate_showcase_description', 'affiliate_hide_customer_data',
    ];

    /**
     * Produtos do mesmo tenant referenciados pelo produto (candidatos a dependência).
     *
     * @return list<array{id: string, name: string, reasons: list<string>}>
     */
    public function dependencyCandidates(Product $product): array
    {
        $refs = $this->referencedProductIds($product);
        if ($refs === []) {
            return [];
        }

        $products = Product::query()
            ->where('tenant_id', $product->tenant_id)
            ->whereIn('id', array_keys($refs))
            ->get(['id', 'name']);

        return $products->map(fn (Product $p) => [
            'id' => (string) $p->id,
            'name' => (string) $p->name,
            'reasons' => array_values(array_unique($refs[(string) $p->id] ?? [])),
        ])->values()->all();
    }

    /**
     * @param  list<string>  $dependencyIds  dependências escolhidas (validadas contra o tenant)
     * @return string caminho do .zip temporário
     */
    public function export(Product $root, array $dependencyIds = []): string
    {
        $tenantId = (int) $root->tenant_id;
        $allowedDeps = array_column($this->dependencyCandidates($root), 'id');
        $dependencyIds = array_values(array_intersect(array_map('strval', $dependencyIds), $allowedDeps));

        $products = Product::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', array_merge([(string) $root->id], $dependencyIds))
            ->get()
            ->sortBy(fn (Product $p) => (string) $p->id === (string) $root->id ? 0 : 1)
            ->values();

        if ($products->count() > PackageFormat::MAX_PRODUCTS) {
            throw new PackageException('Selecione no máximo '.PackageFormat::MAX_PRODUCTS.' produtos por pacote.');
        }

        // Chaves locais: p1 = produto principal, p2… = dependências.
        $keys = [];
        foreach ($products as $i => $p) {
            $keys[(string) $p->id] = 'p'.($i + 1);
        }

        $assets = new AssetCollector(new StorageService($tenantId));
        $assets->allowProducts($products->map(fn ($p) => (string) $p->id)->all());
        foreach ($products as $p) {
            $assets->allowKey($p->getRawOriginal('image') ?: null);
        }

        $offerKeys = [];
        $planKeys = [];
        foreach ($products as $p) {
            foreach (ProductOffer::query()->where('product_id', $p->id)->orderBy('position')->get() as $o) {
                $offerKeys[(string) $o->id] = $keys[(string) $p->id].'.o'.$o->id;
            }
            foreach (SubscriptionPlan::query()->where('product_id', $p->id)->orderBy('position')->get() as $pl) {
                $planKeys[(string) $pl->id] = $keys[(string) $p->id].'.s'.$pl->id;
            }
        }

        $missing = [];
        $data = [];
        foreach ($products as $p) {
            $data[] = $this->exportProduct($p, $keys, $offerKeys, $planKeys, $assets, $missing);
        }

        $package = [
            'products' => $data,
            'missing_references' => array_values($missing),
        ];

        return $this->writeZip($package, $products->all(), $keys, $assets);
    }

    /**
     * @param  array<string, string>  $keys
     * @param  array<string, string>  $offerKeys
     * @param  array<string, string>  $planKeys
     * @param  array<string, array<string, mixed>>  $missing
     * @return array<string, mixed>
     */
    private function exportProduct(Product $p, array $keys, array $offerKeys, array $planKeys, AssetCollector $assets, array &$missing): array
    {
        $key = $keys[(string) $p->id];
        $ref = function (?string $productId, string $where) use ($keys, &$missing, $key): ?string {
            if ($productId === null || $productId === '') {
                return null;
            }
            if (isset($keys[$productId])) {
                return $keys[$productId];
            }
            $missing[$key.':'.$where.':'.$productId] = ['product' => $key, 'where' => $where];

            return null;
        };
        $offerRef = fn ($offerId) => $offerId !== null && isset($offerKeys[(string) $offerId]) ? $offerKeys[(string) $offerId] : null;

        $fields = [];
        foreach (self::PRODUCT_FIELDS as $f) {
            $raw = $p->getRawOriginal($f);
            $fields[$f] = is_string($raw) && in_array($f, ['physical_config'], true) ? json_decode($raw, true) : $raw;
        }

        $checkout = $this->decodeRaw($p->getRawOriginal('checkout_config'));
        $checkout = $this->cleanCheckoutConfig($checkout);
        // Upsell/downsell apontam para produtos/ofertas: viram chaves locais.
        if (isset($checkout['upsell']['products']) && is_array($checkout['upsell']['products'])) {
            foreach ($checkout['upsell']['products'] as $i => $item) {
                $checkout['upsell']['products'][$i]['product_id'] = $ref((string) ($item['product_id'] ?? ''), 'upsell');
                $checkout['upsell']['products'][$i]['product_offer_id'] = $offerRef($item['product_offer_id'] ?? null);
            }
        }
        if (isset($checkout['downsell']) && is_array($checkout['downsell'])) {
            $checkout['downsell']['product_id'] = $ref((string) ($checkout['downsell']['product_id'] ?? ''), 'downsell');
            $checkout['downsell']['product_offer_id'] = $offerRef($checkout['downsell']['product_offer_id'] ?? null);
        }

        $member = $this->decodeRaw($p->getRawOriginal('member_area_config'));
        unset($member['pwa']['vapid_public'], $member['pwa']['vapid_private'], $member['login']['default_password']);
        if (isset($member['lesson_offer']['product_id'])) {
            $member['lesson_offer']['product_id'] = $ref((string) $member['lesson_offer']['product_id'], 'lesson_offer');
        }

        return [
            'key' => $key,
            'fields' => $fields,
            'image' => $assets->collectPath($p->getRawOriginal('image') ?: null),
            'checkout_config' => $assets->rewriteTree($checkout),
            'offers' => ProductOffer::query()->where('product_id', $p->id)->orderBy('position')->get()
                ->map(fn (ProductOffer $o) => [
                    'key' => $offerKeys[(string) $o->id],
                    'name' => $o->name,
                    'price' => (string) $o->getRawOriginal('price'),
                    'currency' => $o->currency,
                    'position' => (int) $o->position,
                    'affiliate_share_enabled' => (bool) $o->affiliate_share_enabled,
                    'checkout_config' => $assets->rewriteTree($this->cleanCheckoutConfig($this->decodeRaw($o->getRawOriginal('checkout_config')))),
                ])->values()->all(),
            'plans' => SubscriptionPlan::query()->where('product_id', $p->id)->orderBy('position')->get()
                ->map(fn (SubscriptionPlan $pl) => [
                    'key' => $planKeys[(string) $pl->id],
                    'name' => $pl->name,
                    'price' => (string) $pl->getRawOriginal('price'),
                    'currency' => $pl->currency,
                    'interval' => $pl->interval,
                    'position' => (int) $pl->position,
                    'checkout_config' => $assets->rewriteTree($this->cleanCheckoutConfig($this->decodeRaw($pl->getRawOriginal('checkout_config')))),
                ])->values()->all(),
            'order_bumps' => ProductOrderBump::query()->where('product_id', $p->id)->orderBy('position')->get()
                ->map(fn (ProductOrderBump $b) => [
                    'target_product' => $ref((string) $b->target_product_id, 'order_bump'),
                    'target_offer' => $offerRef($b->target_product_offer_id),
                    'title' => $b->title,
                    'description' => $b->description,
                    'price_override' => $b->price_override !== null ? (string) $b->getRawOriginal('price_override') : null,
                    'cta_title' => $b->cta_title,
                    'position' => (int) $b->position,
                ])->values()->all(),
            'member_area' => [
                'config' => $assets->rewriteTree($member),
                'sections' => $this->exportMemberTree($p, $ref, $assets),
                'internal_products' => MemberInternalProduct::query()->where('product_id', $p->id)->orderBy('position')->get()
                    ->map(fn ($ip) => ['related_product' => $ref((string) $ip->related_product_id, 'member_internal_product'), 'position' => (int) $ip->position])
                    ->values()->all(),
                'community_pages' => MemberCommunityPage::query()->where('product_id', $p->id)->orderBy('position')->get()
                    ->map(fn ($cp) => [
                        'title' => $cp->title, 'icon' => $cp->icon, 'position' => (int) $cp->position,
                        'banner' => $this->mediaOrExternal($assets, $cp->getRawOriginal('banner')),
                        'is_public_posting' => (bool) $cp->is_public_posting, 'is_default' => (bool) $cp->is_default,
                    ])->values()->all(),
            ],
            // Só o modelo comercial: convites, aceites e contas nunca viajam.
            'coproduction_template' => ProductCoproducer::query()->where('product_id', $p->id)
                ->whereIn('status', [ProductCoproducer::STATUS_ACTIVE, ProductCoproducer::STATUS_PENDING])
                ->get()
                ->map(fn ($c) => [
                    'commission_percent' => (string) $c->getRawOriginal('commission_percent'),
                    'commission_on_direct_sales' => (bool) $c->commission_on_direct_sales,
                    'commission_on_affiliate_sales' => (bool) $c->commission_on_affiliate_sales,
                    'duration_preset' => $c->duration_preset,
                ])->values()->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function exportMemberTree(Product $p, callable $ref, AssetCollector $assets): array
    {
        return MemberSection::query()->where('product_id', $p->id)->orderBy('position')->get()
            ->map(fn (MemberSection $s) => [
                'title' => $s->title,
                'position' => (int) $s->position,
                'cover_mode' => $s->cover_mode,
                'section_type' => $s->section_type,
                'modules' => MemberModule::query()->where('member_section_id', $s->id)->orderBy('position')->get()
                    ->map(fn (MemberModule $m) => [
                        'title' => $m->title, 'position' => (int) $m->position,
                        'thumbnail' => $this->mediaOrExternal($assets, $m->getRawOriginal('thumbnail')),
                        'show_title_on_cover' => (bool) $m->show_title_on_cover,
                        'related_product' => $m->related_product_id ? $ref((string) $m->related_product_id, 'member_module') : null,
                        'access_type' => $m->access_type, 'external_url' => $m->external_url,
                        'release_after_days' => $m->release_after_days, 'release_at_date' => $m->getRawOriginal('release_at_date'),
                        'expire_after_days' => $m->expire_after_days, 'expire_at_date' => $m->getRawOriginal('expire_at_date'),
                        'renewal_price' => $m->getRawOriginal('renewal_price'),
                        'lessons' => MemberLesson::query()->where('member_module_id', $m->id)->orderBy('position')->get()
                            ->map(fn (MemberLesson $l) => [
                                'title' => $l->title, 'position' => (int) $l->position, 'type' => $l->type,
                                'content_url' => $assets->rewriteString((string) $l->content_url),
                                'link_title' => $l->link_title,
                                'content_files' => $assets->rewriteTree($this->decodeRaw($l->getRawOriginal('content_files'))),
                                'content_text' => $assets->rewriteString((string) $l->content_text),
                                'duration_seconds' => $l->duration_seconds, 'is_free' => (bool) $l->is_free,
                                'watermark_enabled' => (bool) $l->watermark_enabled,
                                'release_after_days' => $l->release_after_days, 'release_at_date' => $l->getRawOriginal('release_at_date'),
                            ])->values()->all(),
                    ])->values()->all(),
            ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function cleanCheckoutConfig(array $config): array
    {
        // Preferências de adquirente valem só para a conta de origem: o destino segue a própria política.
        unset($config['payment_gateways'], $config['advanced']);
        if (isset($config['exit_popup']) && is_array($config['exit_popup'])) {
            $config['exit_popup']['coupon_id'] = null;
        }

        return $config;
    }

    /** @return array<string, list<string>> productId => motivos */
    private function referencedProductIds(Product $p): array
    {
        $refs = [];
        $add = function ($id, string $reason) use (&$refs, $p) {
            $id = (string) $id;
            if ($id !== '' && $id !== (string) $p->id) {
                $refs[$id][] = $reason;
            }
        };

        foreach (ProductOrderBump::query()->where('product_id', $p->id)->pluck('target_product_id') as $id) {
            $add($id, 'order bump');
        }
        $checkout = $this->decodeRaw($p->getRawOriginal('checkout_config'));
        foreach ((array) ($checkout['upsell']['products'] ?? []) as $item) {
            $add($item['product_id'] ?? '', 'upsell');
        }
        $add($checkout['downsell']['product_id'] ?? '', 'downsell');
        foreach (MemberModule::query()->where('product_id', $p->id)->whereNotNull('related_product_id')->pluck('related_product_id') as $id) {
            $add($id, 'área de membros');
        }
        foreach (MemberInternalProduct::query()->where('product_id', $p->id)->pluck('related_product_id') as $id) {
            $add($id, 'área de membros');
        }
        $member = $this->decodeRaw($p->getRawOriginal('member_area_config'));
        $add($member['lesson_offer']['product_id'] ?? '', 'oferta dentro da aula');

        return $refs;
    }

    /**
     * @param  list<Product>  $products
     * @param  array<string, string>  $keys
     */
    private function writeZip(array $package, array $products, array $keys, AssetCollector $assets): string
    {
        $json = json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > PackageFormat::MAX_DATA_BYTES) {
            throw new PackageException('Os dados do produto passam do limite do pacote.');
        }

        $inventory = [['path' => PackageFormat::DATA, 'sha256' => hash('sha256', $json), 'bytes' => strlen($json)]];
        foreach ($assets->assets() as $asset) {
            $inventory[] = ['path' => 'assets/'.$asset['name'], 'sha256' => hash('sha256', $asset['bytes']), 'bytes' => strlen($asset['bytes'])];
        }

        $manifest = [
            'format' => PackageFormat::FORMAT,
            'schema_version' => PackageFormat::SCHEMA_VERSION,
            'min_schema_version' => 1,
            'brasapay_version' => PackageFormat::brasapayVersion(),
            'package_id' => (string) Str::uuid(),
            'generated_at' => now()->toIso8601String(),
            'products' => array_map(fn (Product $p) => [
                'key' => $keys[(string) $p->id],
                'name' => (string) $p->name,
                'type' => (string) $p->type,
                'billing_type' => (string) $p->billing_type,
            ], $products),
            'missing_references' => $package['missing_references'],
            'inventory' => $inventory,
        ];

        $path = tempnam(sys_get_temp_dir(), 'bpexp');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new PackageException('Não foi possível gerar o pacote.');
        }
        $zip->addFromString(PackageFormat::MANIFEST, (string) json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $zip->addFromString(PackageFormat::DATA, $json);
        foreach ($assets->assets() as $asset) {
            $zip->addFromString('assets/'.$asset['name'], $asset['bytes']);
        }
        $zip->close();

        Log::info('ProductExport: pacote gerado', [
            'root_product_id' => $products[0]->id ?? null,
            'products' => count($products),
            'assets' => count($assets->assets()),
        ]);

        return $path;
    }

    /** Mídia do pacote; senão só uma URL https externa — nunca um caminho de storage de origem. */
    private function mediaOrExternal(AssetCollector $assets, mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return null;
        }

        return $assets->collectPath($value) ?? (str_starts_with($value, 'https://') ? $value : null);
    }

    /** @return array<string, mixed> */
    private function decodeRaw(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $d = json_decode($raw, true);

            return is_array($d) ? $d : [];
        }

        return [];
    }
}

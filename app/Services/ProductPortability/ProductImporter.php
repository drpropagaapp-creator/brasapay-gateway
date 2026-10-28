<?php

namespace App\Services\ProductPortability;

use App\Models\MemberCommunityPage;
use App\Models\MemberInternalProduct;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\ProductOrderBump;
use App\Models\SellerAcquirerPolicy;
use App\Models\SubscriptionPlan;
use App\Services\Payments\AcquirerPolicyService;
use App\Services\ProductApprovalService;
use App\Services\StorageService;
use App\Support\CheckoutConfigUrlSanitizer;
use App\Support\HtmlSanitizer;
use App\Support\SafeUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recria um pacote numa conta: produtos, ofertas, planos, bumps, checkout,
 * landing e área de membros — tudo com IDs e slugs novos. Nada de credencial,
 * política de adquirente, cupom, integração ou dado de comprador entra.
 */
class ProductImporter
{
    private const TYPES = ['aplicativo', 'area_membros', 'area_membros_externa', 'link', 'link_pagamento', 'produto_fisico'];

    private const BILLING = ['one_time', 'subscription'];

    private const CURRENCIES = ['BRL', 'EUR', 'USD'];

    /** @var list<string> caminhos enviados ao storage (para compensar se der erro) */
    private array $uploaded = [];

    /** @var array<string, string> "dir|asset" => caminho */
    private array $uploadCache = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * Resumo para a tela de prévia (não grava nada).
     *
     * @return array<string, mixed>
     */
    public function preview(PackageReader $reader, int $tenantId): array
    {
        $data = $reader->data();
        $manifest = $reader->manifest();
        $policy = app(AcquirerPolicyService::class);

        $products = [];
        foreach ($data['products'] as $p) {
            $enabled = array_keys(array_filter((array) ($p['checkout_config']['payment_methods_enabled'] ?? []), fn ($v) => $v === true));
            $blocked = array_values(array_filter(
                $enabled,
                fn ($m) => $policy->ruleFor($tenantId, (string) $m)['mode'] === SellerAcquirerPolicy::MODE_DISABLED
            ));
            $sections = (array) ($p['member_area']['sections'] ?? []);

            $products[] = [
                'key' => (string) ($p['key'] ?? ''),
                'name' => HtmlSanitizer::plainText((string) ($p['fields']['name'] ?? ''), 255),
                'type' => (string) ($p['fields']['type'] ?? ''),
                'price' => (string) ($p['fields']['price'] ?? '0'),
                'currency' => (string) ($p['fields']['currency'] ?? 'BRL'),
                'offers' => count((array) ($p['offers'] ?? [])),
                'plans' => count((array) ($p['plans'] ?? [])),
                'order_bumps' => count((array) ($p['order_bumps'] ?? [])),
                'landing_mode' => ($p['checkout_config']['landing']['mode'] ?? 'sections') === 'blocks' ? 'blocks' : 'sections',
                'member_sections' => count($sections),
                'member_lessons' => array_sum(array_map(
                    fn ($s) => array_sum(array_map(fn ($m) => count((array) ($m['lessons'] ?? [])), (array) ($s['modules'] ?? []))),
                    $sections
                )),
                'coproduction_template' => count((array) ($p['coproduction_template'] ?? [])),
                'payment_methods_blocked_here' => $blocked,
            ];
        }

        return [
            'package_id' => (string) ($manifest['package_id'] ?? ''),
            'generated_at' => (string) ($manifest['generated_at'] ?? ''),
            'brasapay_version' => (string) ($manifest['brasapay_version'] ?? ''),
            'products' => $products,
            'assets' => count($reader->assetNames()),
            'missing_references' => array_values((array) ($data['missing_references'] ?? [])),
        ];
    }

    /**
     * @return array{products: list<array{key: string, id: string, name: string}>, warnings: list<string>}
     */
    public function import(PackageReader $reader, int $tenantId, ?int $userId = null): array
    {
        $this->uploaded = [];
        $this->uploadCache = [];
        $this->warnings = [];
        $storage = new StorageService($tenantId);
        $data = $reader->data();

        try {
            $created = DB::transaction(function () use ($reader, $tenantId, $data, $storage) {
                $map = [];
                $offerMap = [];
                $planMap = [];

                // 1) Produtos (sem configs ainda: precisam dos IDs novos para remapear).
                foreach ($data['products'] as $p) {
                    $key = (string) ($p['key'] ?? '');
                    if (! preg_match('/^p\d{1,3}$/', $key) || isset($map[$key])) {
                        throw new PackageException('Chave de produto inválida no pacote.');
                    }
                    $map[$key] = $this->createProduct($tenantId, (array) ($p['fields'] ?? []));
                }

                // 2) Ofertas e planos.
                foreach ($data['products'] as $p) {
                    $product = $map[$p['key']];
                    foreach ((array) ($p['offers'] ?? []) as $o) {
                        $offerMap[(string) ($o['key'] ?? '')] = ProductOffer::query()->create([
                            'product_id' => $product->id,
                            'name' => HtmlSanitizer::plainText((string) ($o['name'] ?? 'Oferta'), 255),
                            'price' => $this->money($o['price'] ?? 0),
                            'currency' => in_array($o['currency'] ?? null, self::CURRENCIES, true) ? $o['currency'] : null,
                            'checkout_slug' => ProductOffer::generateUniqueCheckoutSlug(),
                            'checkout_config' => [],
                            'position' => (int) ($o['position'] ?? 0),
                            'affiliate_share_enabled' => (bool) ($o['affiliate_share_enabled'] ?? false),
                        ]);
                    }
                    foreach ((array) ($p['plans'] ?? []) as $pl) {
                        $planMap[(string) ($pl['key'] ?? '')] = SubscriptionPlan::query()->create([
                            'product_id' => $product->id,
                            'name' => HtmlSanitizer::plainText((string) ($pl['name'] ?? 'Plano'), 255),
                            'price' => $this->money($pl['price'] ?? 0),
                            'currency' => in_array($pl['currency'] ?? null, self::CURRENCIES, true) ? $pl['currency'] : null,
                            'interval' => in_array($pl['interval'] ?? null, [
                                SubscriptionPlan::INTERVAL_WEEKLY, SubscriptionPlan::INTERVAL_MONTHLY, SubscriptionPlan::INTERVAL_QUARTERLY,
                                SubscriptionPlan::INTERVAL_SEMI_ANNUAL, SubscriptionPlan::INTERVAL_ANNUAL, SubscriptionPlan::INTERVAL_LIFETIME,
                            ], true) ? $pl['interval'] : SubscriptionPlan::INTERVAL_MONTHLY,
                            'checkout_slug' => SubscriptionPlan::generateUniqueCheckoutSlug(),
                            'checkout_config' => [],
                            'position' => (int) ($pl['position'] ?? 0),
                        ]);
                    }
                }

                $productId = fn (?string $k) => $k !== null && isset($map[$k]) ? (string) $map[$k]->id : null;
                $offerId = fn (?string $k) => $k !== null && isset($offerMap[$k]) ? (int) $offerMap[$k]->id : null;

                // 3) Configs, bumps e área de membros, com refs e mídias remapeadas.
                foreach ($data['products'] as $p) {
                    $product = $map[$p['key']];
                    $dir = 'checkout/'.$product->id.'/imported';

                    $checkout = $this->resolveAssets($reader, $storage, $dir, (array) ($p['checkout_config'] ?? []));
                    $checkout = $this->remapCheckoutRefs($checkout, $productId, $offerId, (string) $p['key']);
                    $product->checkout_config = CheckoutConfigUrlSanitizer::sanitize($checkout);

                    if (is_string($p['image'] ?? null) && $p['image'] !== '') {
                        $product->image = $this->uploadPlaceholder($reader, $storage, 'products', $p['image']);
                    }

                    $member = $this->resolveAssets($reader, $storage, 'member-area/'.$product->id.'/imported', (array) ($p['member_area']['config'] ?? []));
                    if (isset($member['lesson_offer']) && is_array($member['lesson_offer'])) {
                        $member['lesson_offer']['product_id'] = $productId($member['lesson_offer']['product_id'] ?? null);
                    }
                    unset($member['pwa']['vapid_public'], $member['pwa']['vapid_private'], $member['login']['default_password']);
                    $product->member_area_config = $this->cleanUrls($member);
                    $product->save();

                    foreach ((array) ($p['offers'] ?? []) as $o) {
                        $offer = $offerMap[(string) $o['key']] ?? null;
                        if ($offer !== null) {
                            $cfg = $this->resolveAssets($reader, $storage, $dir, (array) ($o['checkout_config'] ?? []));
                            $offer->update(['checkout_config' => CheckoutConfigUrlSanitizer::sanitize($cfg)]);
                        }
                    }
                    foreach ((array) ($p['plans'] ?? []) as $pl) {
                        $plan = $planMap[(string) $pl['key']] ?? null;
                        if ($plan !== null) {
                            $cfg = $this->resolveAssets($reader, $storage, $dir, (array) ($pl['checkout_config'] ?? []));
                            $plan->update(['checkout_config' => CheckoutConfigUrlSanitizer::sanitize($cfg)]);
                        }
                    }

                    foreach ((array) ($p['order_bumps'] ?? []) as $b) {
                        $target = $productId($b['target_product'] ?? null);
                        if ($target === null || $target === (string) $product->id) {
                            $this->warnings[] = "Order bump \"".HtmlSanitizer::plainText((string) ($b['title'] ?? ''), 80)."\" não foi recriado: o produto de destino não veio no pacote.";

                            continue;
                        }
                        ProductOrderBump::query()->create([
                            'product_id' => $product->id,
                            'target_product_id' => $target,
                            'target_product_offer_id' => $offerId($b['target_offer'] ?? null),
                            'title' => HtmlSanitizer::plainText((string) ($b['title'] ?? ''), 255),
                            'description' => HtmlSanitizer::plainTextMultiline((string) ($b['description'] ?? ''), 2000),
                            'price_override' => isset($b['price_override']) && $b['price_override'] !== null ? $this->money($b['price_override']) : null,
                            'cta_title' => HtmlSanitizer::plainText((string) ($b['cta_title'] ?? ''), 255),
                            'position' => (int) ($b['position'] ?? 0),
                        ]);
                    }

                    $this->importMemberArea($reader, $storage, $product, (array) ($p['member_area'] ?? []), $productId);

                    if (! empty($p['coproduction_template'])) {
                        $this->warnings[] = "\"{$product->name}\": a coprodução não foi copiada. Convide os coprodutores de novo; a divisão só vale após o aceite.";
                    }
                }

                return $map;
            });
        } catch (\Throwable $e) {
            $this->compensateUploads($storage);
            throw $e;
        }

        $approval = app(ProductApprovalService::class);
        foreach ($created as $product) {
            $approval->afterCreated($product->fresh());
        }

        Log::info('ProductImport: pacote importado', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'products' => array_map(fn ($p) => (string) $p->id, array_values($created)),
            'assets_uploaded' => count($this->uploaded),
        ]);

        return [
            'products' => array_values(array_map(fn ($k, $p) => ['key' => $k, 'id' => (string) $p->id, 'name' => (string) $p->name], array_keys($created), $created)),
            'warnings' => array_values(array_unique($this->warnings)),
        ];
    }

    /** @param  array<string, mixed>  $f */
    private function createProduct(int $tenantId, array $f): Product
    {
        $name = HtmlSanitizer::plainText((string) ($f['name'] ?? ''), 255) ?: 'Produto importado';
        $type = in_array($f['type'] ?? null, self::TYPES, true) ? $f['type'] : Product::TYPE_AREA_MEMBROS;
        $billing = in_array($f['billing_type'] ?? null, self::BILLING, true) ? $f['billing_type'] : 'one_time';

        $attrs = [
            'tenant_id' => $tenantId,
            'name' => $name,
            'slug' => Product::uniqueSlugForTenant($tenantId, $name),
            'notification_name' => isset($f['notification_name']) ? HtmlSanitizer::plainText((string) $f['notification_name'], 80) : null,
            'description' => isset($f['description']) ? HtmlSanitizer::sanitize((string) $f['description']) : null,
            'category' => isset($f['category']) ? HtmlSanitizer::plainText((string) $f['category'], 80) : null,
            'type' => $type,
            'billing_type' => $billing,
            'price' => $this->money($f['price'] ?? 0),
            'currency' => in_array($f['currency'] ?? null, self::CURRENCIES, true) ? $f['currency'] : 'BRL',
            'refund_policy_days' => in_array((int) ($f['refund_policy_days'] ?? 0), [7, 14, 30], true) ? (int) $f['refund_policy_days'] : null,
            'checkout_config' => [],
            'affiliate_enabled' => (bool) ($f['affiliate_enabled'] ?? false),
            'affiliate_commission_percent' => max(0, min(100, (float) ($f['affiliate_commission_percent'] ?? 0))),
            'affiliate_manual_approval' => (bool) ($f['affiliate_manual_approval'] ?? false),
            'affiliate_show_in_showcase' => (bool) ($f['affiliate_show_in_showcase'] ?? false),
            'affiliate_page_url' => SafeUrl::normalizeHttpUrl((string) ($f['affiliate_page_url'] ?? '')),
            'affiliate_support_email' => filter_var($f['affiliate_support_email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null,
            'affiliate_showcase_description' => isset($f['affiliate_showcase_description']) ? HtmlSanitizer::plainTextMultiline((string) $f['affiliate_showcase_description'], 4000) : null,
            'affiliate_hide_customer_data' => (bool) ($f['affiliate_hide_customer_data'] ?? false),
            'physical_config' => $type === Product::TYPE_PRODUTO_FISICO && is_array($f['physical_config'] ?? null)
                ? ['free_shipping' => (bool) ($f['physical_config']['free_shipping'] ?? false)]
                : null,
            'is_active' => false,
        ];

        // Importado entra inativo e passa pela aprovação da conta de destino.
        $attrs = array_merge($attrs, app(ProductApprovalService::class)->attributesForNewProduct(false));
        $product = new Product;
        $product->forceFill(array_filter($attrs, fn ($k) => \Illuminate\Support\Facades\Schema::hasColumn('products', $k), ARRAY_FILTER_USE_KEY));
        // products.id é UUID (Postgres/MySQL, gerado no `creating` do model); no SQLite segue
        // inteiro e o model não incrementa sozinho ($incrementing = false).
        if ($product->getConnection()->getDriverName() === 'sqlite') {
            $product->id = (string) ((int) Product::withTrashed()->max('id') + 1);
        }
        $product->save();

        return $product;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function remapCheckoutRefs(array $config, callable $productId, callable $offerId, string $key): array
    {
        unset($config['payment_gateways']);
        if (isset($config['upsell']['products']) && is_array($config['upsell']['products'])) {
            $kept = [];
            foreach ($config['upsell']['products'] as $item) {
                $pid = $productId($item['product_id'] ?? null);
                if ($pid === null) {
                    $this->warnings[] = 'Um produto de upsell não veio no pacote e foi removido do funil.';

                    continue;
                }
                $item['product_id'] = $pid;
                $item['product_offer_id'] = $offerId($item['product_offer_id'] ?? null);
                $kept[] = $item;
            }
            $config['upsell']['products'] = $kept;
            if ($kept === []) {
                $config['upsell']['enabled'] = false;
            }
        }
        if (isset($config['downsell']) && is_array($config['downsell'])) {
            $pid = $productId($config['downsell']['product_id'] ?? null);
            if ($pid === null && ! empty($config['downsell']['enabled'])) {
                $this->warnings[] = 'O produto de downsell não veio no pacote; o downsell foi desativado.';
                $config['downsell']['enabled'] = false;
            }
            $config['downsell']['product_id'] = $pid;
            $config['downsell']['product_offer_id'] = $offerId($config['downsell']['product_offer_id'] ?? null);
        }
        if (isset($config['exit_popup']) && is_array($config['exit_popup'])) {
            $config['exit_popup']['coupon_id'] = null;
        }
        if (isset($config['email_template']['body_html']) && is_string($config['email_template']['body_html'])) {
            $config['email_template']['body_html'] = HtmlSanitizer::sanitize($config['email_template']['body_html']);
        }

        return $config;
    }

    /** @param  array<string, mixed>  $member */
    private function importMemberArea(PackageReader $reader, StorageService $storage, Product $product, array $member, callable $productId): void
    {
        $dir = 'member-area/'.$product->id.'/imported';

        foreach ((array) ($member['sections'] ?? []) as $s) {
            $section = MemberSection::query()->create([
                'product_id' => $product->id,
                'title' => HtmlSanitizer::plainText((string) ($s['title'] ?? 'Seção'), 255),
                'position' => (int) ($s['position'] ?? 0),
                'cover_mode' => in_array($s['cover_mode'] ?? null, ['vertical', 'horizontal'], true) ? $s['cover_mode'] : 'vertical',
                'section_type' => in_array($s['section_type'] ?? null, ['courses', 'products', 'external_links'], true) ? $s['section_type'] : 'courses',
            ]);

            foreach ((array) ($s['modules'] ?? []) as $m) {
                $related = $productId($m['related_product'] ?? null);
                $module = MemberModule::query()->create([
                    'member_section_id' => $section->id,
                    'product_id' => $product->id,
                    'title' => HtmlSanitizer::plainText((string) ($m['title'] ?? 'Módulo'), 255),
                    'position' => (int) ($m['position'] ?? 0),
                    'thumbnail' => $this->relativeMedia($reader, $storage, $dir, $m['thumbnail'] ?? null),
                    'show_title_on_cover' => (bool) ($m['show_title_on_cover'] ?? true),
                    'related_product_id' => $related !== (string) $product->id ? $related : null,
                    'access_type' => in_array($m['access_type'] ?? null, ['paid', 'free'], true) ? $m['access_type'] : 'paid',
                    'external_url' => SafeUrl::normalizeHttpUrl((string) ($m['external_url'] ?? '')),
                    'release_after_days' => isset($m['release_after_days']) ? max(0, (int) $m['release_after_days']) : null,
                    'release_at_date' => $this->date($m['release_at_date'] ?? null),
                    'expire_after_days' => isset($m['expire_after_days']) ? max(0, (int) $m['expire_after_days']) : null,
                    'expire_at_date' => $this->date($m['expire_at_date'] ?? null),
                    'renewal_price' => isset($m['renewal_price']) && $m['renewal_price'] !== null ? $this->money($m['renewal_price']) : null,
                ]);

                foreach ((array) ($m['lessons'] ?? []) as $l) {
                    $files = $this->resolveAssets($reader, $storage, $dir, (array) ($l['content_files'] ?? []));
                    $files = array_values(array_filter(array_map(fn ($f) => is_array($f) && SafeUrl::normalizeHttpUrl((string) ($f['url'] ?? ''))
                        ? ['url' => SafeUrl::normalizeHttpUrl((string) $f['url']), 'name' => HtmlSanitizer::plainText((string) ($f['name'] ?? 'Arquivo'), 255)]
                        : null, array_slice($files, 0, 30))));

                    MemberLesson::query()->create([
                        'member_module_id' => $module->id,
                        'product_id' => $product->id,
                        'title' => HtmlSanitizer::plainText((string) ($l['title'] ?? 'Aula'), 255),
                        'position' => (int) ($l['position'] ?? 0),
                        'type' => in_array($l['type'] ?? null, ['video', 'link', 'pdf', 'text'], true) ? $l['type'] : 'text',
                        'content_url' => SafeUrl::normalizeHttpUrl($this->resolveAssets($reader, $storage, $dir, (string) ($l['content_url'] ?? ''))) ?? null,
                        'link_title' => isset($l['link_title']) ? HtmlSanitizer::plainText((string) $l['link_title'], 255) : null,
                        'content_files' => $files,
                        'content_text' => HtmlSanitizer::sanitizeLessonHtml($this->resolveAssets($reader, $storage, $dir, (string) ($l['content_text'] ?? ''))),
                        'duration_seconds' => isset($l['duration_seconds']) ? max(0, (int) $l['duration_seconds']) : null,
                        'is_free' => (bool) ($l['is_free'] ?? false),
                        'watermark_enabled' => (bool) ($l['watermark_enabled'] ?? false),
                        'release_after_days' => isset($l['release_after_days']) ? max(0, (int) $l['release_after_days']) : null,
                        'release_at_date' => $this->date($l['release_at_date'] ?? null),
                    ]);
                }
            }
        }

        foreach ((array) ($member['internal_products'] ?? []) as $ip) {
            $related = $productId($ip['related_product'] ?? null);
            if ($related === null || $related === (string) $product->id) {
                continue;
            }
            MemberInternalProduct::query()->create([
                'product_id' => $product->id,
                'related_product_id' => $related,
                'position' => (int) ($ip['position'] ?? 0),
            ]);
        }

        foreach ((array) ($member['community_pages'] ?? []) as $cp) {
            MemberCommunityPage::query()->create([
                'product_id' => $product->id,
                'title' => HtmlSanitizer::plainText((string) ($cp['title'] ?? 'Comunidade'), 255),
                'icon' => HtmlSanitizer::plainText((string) ($cp['icon'] ?? ''), 50) ?: null,
                'banner' => $this->relativeMedia($reader, $storage, $dir, $cp['banner'] ?? null),
                'position' => (int) ($cp['position'] ?? 0),
                'is_public_posting' => (bool) ($cp['is_public_posting'] ?? false),
                'is_default' => (bool) ($cp['is_default'] ?? false),
            ]);
        }
    }

    /**
     * Troca placeholders asset:// por URLs no storage do destino (em qualquer string da estrutura).
     */
    private function resolveAssets(PackageReader $reader, StorageService $storage, string $dir, mixed $data): mixed
    {
        if (is_string($data)) {
            return (string) preg_replace_callback(
                '#'.preg_quote(PackageFormat::ASSET_PREFIX, '#').'([a-f0-9]{64}\.[a-z0-9]{2,5})#',
                fn (array $m) => $storage->url($this->uploadAsset($reader, $storage, $dir, $m[1])),
                $data
            );
        }
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $data[$k] = $this->resolveAssets($reader, $storage, $dir, $v);
            }
        }

        return $data;
    }

    /** Colunas que guardam caminho relativo (imagem do produto, capas). */
    private function relativeMedia(PackageReader $reader, StorageService $storage, string $dir, mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        if (str_starts_with($value, PackageFormat::ASSET_PREFIX)) {
            return $this->uploadPlaceholder($reader, $storage, $dir, $value);
        }

        return SafeUrl::normalizeHttpUrl($value);
    }

    private function uploadPlaceholder(PackageReader $reader, StorageService $storage, string $dir, string $placeholder): ?string
    {
        $name = substr($placeholder, strlen(PackageFormat::ASSET_PREFIX));
        if (! preg_match('/^[a-f0-9]{64}\.[a-z0-9]{2,5}$/', $name)) {
            return null;
        }

        return $this->uploadAsset($reader, $storage, $dir, $name);
    }

    private function uploadAsset(PackageReader $reader, StorageService $storage, string $dir, string $name): string
    {
        $cacheKey = $dir.'|'.$name;
        if (isset($this->uploadCache[$cacheKey])) {
            return $this->uploadCache[$cacheKey];
        }

        $bytes = $reader->asset($name);
        $tmp = tempnam(sys_get_temp_dir(), 'bpimp');
        file_put_contents($tmp, $bytes);
        try {
            $file = new UploadedFile($tmp, $name, null, null, true);
            $path = $storage->putFileAs($dir, $file, $name);
        } finally {
            @unlink($tmp);
        }
        $this->uploaded[] = $path;

        return $this->uploadCache[$cacheKey] = $path;
    }

    private function compensateUploads(StorageService $storage): void
    {
        foreach ($this->uploaded as $path) {
            try {
                $storage->disk()->delete($path);
            } catch (\Throwable $e) {
                Log::warning('ProductImport: falha ao remover mídia de importação abortada', ['path' => $path, 'message' => $e->getMessage()]);
            }
        }
        $this->uploaded = [];
    }

    /**
     * Configs da área de membros: qualquer campo de mídia/URL precisa ser http(s).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function cleanUrls(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->cleanUrls($v);
            } elseif (is_string($v) && preg_match('/(url|image|logo|favicon|banner|link)(_[a-z]+)?$/i', (string) $k) && $v !== '') {
                $data[$k] = SafeUrl::normalizeHttpUrl($v) ?? '';
            } elseif (is_string($v)) {
                $data[$k] = HtmlSanitizer::plainTextMultiline($v, 4000);
            }
        }

        return $data;
    }

    private function money(mixed $value): string
    {
        return \App\Support\MoneyDecimal::normalize(is_numeric($value) && (float) $value >= 0 ? $value : 0);
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && strtotime($value) !== false ? date('Y-m-d H:i:s', (int) strtotime($value)) : null;
    }
}

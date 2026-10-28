<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceInstall;
use App\Models\MarketplaceReview;
use App\Models\MarketplaceTemplate;
use App\Models\MarketplaceTemplateVersion;
use App\Models\Product;
use App\Models\ProductImport;
use App\Models\User;
use App\Services\PlatformAuditService;
use App\Services\ProductPortability\PackageException;
use App\Services\ProductPortability\PackageReader;
use App\Services\ProductPortability\ProductExporter;
use App\Services\ProductPortability\ProductImporter;
use App\Support\RecoveryEmailSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Brasa Marketplace — biblioteca de templates.
 *
 * - Template de produto = pacote do formato oficial, gerado pelo exportador oficial SEM aulas/comunidade
 *   e SEM modelo de coprodução, e instalado pelo importador oficial (mesma tela de prévia/confirmação).
 *   Assim valem as garantias do importador: nada de credencial, política de adquirente, cupom,
 *   integração, coprodutor ativo ou dado de comprador; scripts e URLs passam pelos sanitizadores.
 * - Template de recuperação = só os textos/atrasos das etapas; instalar não liga a recuperação.
 * - Tudo passa por revisão do admin antes de aparecer no catálogo. Preço é sempre 0 por enquanto.
 */
class TemplateLibraryService
{
    private const DIR = 'marketplace';

    public function __construct(
        private ProductExporter $exporter,
        private ProductImporter $importer,
    ) {}

    /** Envia um produto do seller como template (nova versão se o template já for dele). */
    public function submitProduct(User $author, Product $product, array $meta, ?MarketplaceTemplate $template = null): MarketplaceTemplateVersion
    {
        $tenantId = $this->assertOwner($author);
        abort_unless((int) $product->tenant_id === $tenantId, 403);

        $tmp = $this->exporter->export($product, [], ['member_content' => false, 'coproduction' => false]);
        try {
            $stored = $this->storePackage($tmp);
        } finally {
            @unlink($tmp);
        }
        $summary = $this->previewPackage($stored['absolute'], $tenantId);

        return $this->createVersion($author, $template, $meta + ['kind' => MarketplaceTemplate::KIND_PACKAGE], [
            'package_path' => $stored['relative'], 'package_sha256' => $stored['sha256'], 'summary' => $summary,
        ]);
    }

    /** Envia os textos atuais da Recuperação de vendas do seller como template. */
    public function submitRecovery(User $author, array $meta, ?MarketplaceTemplate $template = null): MarketplaceTemplateVersion
    {
        $tenantId = $this->assertOwner($author);
        $content = ['events' => RecoveryEmailSettings::forTenant($tenantId)['events']];
        $steps = array_sum(array_map(fn ($e) => count($e['steps']), $content['events']));
        abort_if($steps === 0, 422, 'Configure ao menos uma etapa na Recuperação de vendas antes de publicar.');

        return $this->createVersion($author, $template, $meta + ['kind' => MarketplaceTemplate::KIND_RECOVERY, 'category' => 'recovery'], [
            'content' => $content, 'summary' => ['steps' => $steps, 'events' => array_keys(array_filter($content['events'], fn ($e) => $e['steps'] !== []))],
        ]);
    }

    /** Template oficial (admin) a partir de um pacote já exportado. Fica publicado direto, com auditoria. */
    public function createOfficialFromPackage(User $admin, string $uploadedPath, array $meta): MarketplaceTemplate
    {
        $stored = $this->storePackage($uploadedPath);
        $summary = $this->previewPackage($stored['absolute'], 0);
        $version = DB::transaction(function () use ($admin, $meta, $stored, $summary) {
            $template = MarketplaceTemplate::query()->create($this->templateAttributes($meta + ['kind' => MarketplaceTemplate::KIND_PACKAGE]) + [
                'author_tenant_id' => null, 'author_name' => 'Equipe da plataforma', 'is_official' => true, 'status' => MarketplaceTemplate::STATUS_PENDING,
            ]);

            return MarketplaceTemplateVersion::query()->create([
                'template_id' => $template->id, 'version' => 1, 'created_by' => $admin->id, 'changelog' => $meta['changelog'] ?? null,
                'package_path' => $stored['relative'], 'package_sha256' => $stored['sha256'], 'summary' => $summary,
            ]);
        });
        $this->approve($version, $admin);

        return MarketplaceTemplate::query()->findOrFail($version->template_id);
    }

    public function approve(MarketplaceTemplateVersion $version, User $admin): void
    {
        DB::transaction(function () use ($version, $admin) {
            $locked = MarketplaceTemplateVersion::query()->lockForUpdate()->findOrFail($version->id);
            abort_unless($locked->status === MarketplaceTemplate::STATUS_PENDING, 422, 'Esta versão já foi revisada.');
            $locked->update(['status' => MarketplaceTemplate::STATUS_PUBLISHED, 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'rejection_reason' => null]);
            MarketplaceTemplate::query()->whereKey($locked->template_id)->update([
                'current_version_id' => $locked->id, 'status' => MarketplaceTemplate::STATUS_PUBLISHED, 'rejection_reason' => null, 'updated_at' => now(),
            ]);
        });
        PlatformAuditService::log('marketplace.version.approved', ['template_id' => $version->template_id, 'version_id' => $version->id]);
    }

    public function reject(MarketplaceTemplateVersion $version, User $admin, string $reason): void
    {
        abort_unless($version->status === MarketplaceTemplate::STATUS_PENDING, 422, 'Esta versão já foi revisada.');
        $version->update(['status' => MarketplaceTemplate::STATUS_REJECTED, 'rejection_reason' => $reason, 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
        $template = MarketplaceTemplate::query()->findOrFail($version->template_id);
        if ($template->current_version_id === null) {
            $template->update(['status' => MarketplaceTemplate::STATUS_REJECTED, 'rejection_reason' => $reason]);
        }
        $this->deletePackage($version);
        PlatformAuditService::log('marketplace.version.rejected', ['template_id' => $version->template_id, 'version_id' => $version->id, 'reason' => $reason]);
    }

    public function setPublished(MarketplaceTemplate $template, bool $published, string $reason): void
    {
        abort_if($published && $template->current_version_id === null, 422, 'Não há versão aprovada para publicar.');
        $template->update(['status' => $published ? MarketplaceTemplate::STATUS_PUBLISHED : MarketplaceTemplate::STATUS_UNPUBLISHED]);
        PlatformAuditService::log($published ? 'marketplace.template.published' : 'marketplace.template.unpublished', ['template_id' => $template->id, 'reason' => $reason]);
    }

    /**
     * Instala a versão publicada atual. Pacote: abre a importação oficial já validada (o seller confirma
     * na mesma tela de sempre). Recuperação: grava os textos sem ligar a recuperação.
     *
     * @return array{install: MarketplaceInstall, import: ProductImport|null}
     */
    public function install(MarketplaceTemplate $template, User $user): array
    {
        $tenantId = $this->assertOwner($user);
        abort_unless($template->status === MarketplaceTemplate::STATUS_PUBLISHED && $template->current_version_id !== null, 404);
        $version = MarketplaceTemplateVersion::query()->whereKey($template->current_version_id)->where('status', MarketplaceTemplate::STATUS_PUBLISHED)->firstOrFail();

        if ($template->kind === MarketplaceTemplate::KIND_RECOVERY) {
            $current = RecoveryEmailSettings::forTenant($tenantId);
            $incoming = (array) ($version->content['events'] ?? []);
            foreach ($current['events'] as $event => $cfg) {
                if (isset($incoming[$event]['steps']) && is_array($incoming[$event]['steps']) && $incoming[$event]['steps'] !== []) {
                    $current['events'][$event]['steps'] = $incoming[$event]['steps'];
                }
            }
            RecoveryEmailSettings::save($tenantId, $current); // 'enabled' da conta é mantido como estava
            $install = $this->recordInstall($template, $version, $user, null, 'completed');

            return ['install' => $install, 'import' => null];
        }

        $path = $version->absolutePackagePath();
        if ($path === null || ! is_file($path) || ! hash_equals((string) $version->package_sha256, (string) hash_file('sha256', $path))) {
            abort(422, 'O pacote deste template não passou na verificação de integridade. Avise o suporte.');
        }

        $uuid = (string) Str::uuid();
        $relative = 'product-imports/'.$uuid.'.zip';
        Storage::disk('local')->put($relative, (string) file_get_contents($path));
        $import = ProductImport::query()->create([
            'uuid' => $uuid, 'tenant_id' => $tenantId, 'user_id' => $user->id, 'status' => ProductImport::STATUS_VALIDATING,
            'package_path' => $relative, 'package_sha256' => $version->package_sha256,
            'original_name' => mb_substr('Biblioteca: '.$template->title.' (v'.$version->version.')', 0, 255),
        ]);
        try {
            $summary = $this->previewPackage(Storage::disk('local')->path($relative), $tenantId);
            $import->update(['status' => ProductImport::STATUS_READY, 'summary' => $summary]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($relative);
            $import->update(['status' => ProductImport::STATUS_FAILED, 'error' => 'Não foi possível ler este template.']);
        }
        $install = $this->recordInstall($template, $version, $user, $import->id, $import->status === ProductImport::STATUS_READY ? 'started' : 'failed');

        return ['install' => $install, 'import' => $import];
    }

    /** Avaliação: só quem instalou; uma por conta; volta para moderação a cada edição. */
    public function review(MarketplaceTemplate $template, User $user, int $rating, ?string $comment): MarketplaceReview
    {
        $tenantId = $this->assertOwner($user);
        $installed = MarketplaceInstall::query()->where('template_id', $template->id)->where('tenant_id', $tenantId)->exists();
        abort_unless($installed, 403, 'Só quem instalou o template pode avaliar.');
        abort_if((int) $template->author_tenant_id === $tenantId, 403, 'O autor não avalia o próprio template.');

        return DB::transaction(function () use ($template, $user, $tenantId, $rating, $comment) {
            $existing = MarketplaceReview::query()->where('template_id', $template->id)->where('tenant_id', $tenantId)->lockForUpdate()->first();
            if ($existing && $existing->status === 'approved') {
                $this->adjustRating($template->id, -1, -(int) $existing->rating);
            }
            $data = ['user_id' => $user->id, 'rating' => max(1, min(5, $rating)), 'comment' => $comment !== null ? mb_substr(strip_tags($comment), 0, 1000) : null, 'status' => 'pending', 'moderated_by' => null];

            return $existing ? tap($existing)->update($data) : MarketplaceReview::query()->create($data + ['template_id' => $template->id, 'tenant_id' => $tenantId]);
        });
    }

    public function moderateReview(MarketplaceReview $review, User $admin, bool $approve): void
    {
        DB::transaction(function () use ($review, $admin, $approve) {
            $locked = MarketplaceReview::query()->lockForUpdate()->findOrFail($review->id);
            $was = $locked->status;
            $now = $approve ? 'approved' : 'rejected';
            if ($was === $now) {
                return;
            }
            if ($was === 'approved') {
                $this->adjustRating($locked->template_id, -1, -(int) $locked->rating);
            }
            if ($now === 'approved') {
                $this->adjustRating($locked->template_id, 1, (int) $locked->rating);
            }
            $locked->update(['status' => $now, 'moderated_by' => $admin->id]);
        });
        PlatformAuditService::log('marketplace.review.'.($approve ? 'approved' : 'rejected'), ['review_id' => $review->id, 'template_id' => $review->template_id]);
    }

    /** @return array{relative: string, absolute: string, sha256: string} */
    private function storePackage(string $sourcePath): array
    {
        $relative = self::DIR.'/'.Str::uuid().'.zip';
        Storage::disk('local')->put($relative, (string) file_get_contents($sourcePath));
        $absolute = Storage::disk('local')->path($relative);

        return ['relative' => $relative, 'absolute' => $absolute, 'sha256' => (string) hash_file('sha256', $absolute)];
    }

    /** Valida o pacote com o leitor oficial e gera o resumo da prévia (nada é gravado na conta). */
    private function previewPackage(string $absolute, int $tenantId): array
    {
        try {
            $reader = PackageReader::open($absolute);
            try {
                return $this->importer->preview($reader, $tenantId);
            } finally {
                $reader->close();
            }
        } catch (PackageException $e) {
            throw ValidationException::withMessages(['package' => $e->getMessage()]);
        }
    }

    private function createVersion(User $author, ?MarketplaceTemplate $template, array $meta, array $payload): MarketplaceTemplateVersion
    {
        $tenantId = (int) $author->tenant_id;

        return DB::transaction(function () use ($author, $template, $meta, $payload, $tenantId) {
            if ($template === null) {
                $template = MarketplaceTemplate::query()->create($this->templateAttributes($meta) + [
                    'author_tenant_id' => $tenantId, 'author_name' => mb_substr((string) ($author->name ?: 'Seller'), 0, 120),
                    'is_official' => false, 'status' => MarketplaceTemplate::STATUS_PENDING,
                ]);
            } else {
                abort_unless((int) $template->author_tenant_id === $tenantId && $template->kind === $meta['kind'], 403);
                abort_if(MarketplaceTemplateVersion::query()->where('template_id', $template->id)->where('status', MarketplaceTemplate::STATUS_PENDING)->exists(), 422, 'Já existe uma versão aguardando revisão.');
            }
            $next = (int) MarketplaceTemplateVersion::query()->where('template_id', $template->id)->lockForUpdate()->max('version') + 1;

            return MarketplaceTemplateVersion::query()->create($payload + [
                'template_id' => $template->id, 'version' => $next, 'status' => MarketplaceTemplate::STATUS_PENDING,
                'changelog' => isset($meta['changelog']) ? mb_substr((string) $meta['changelog'], 0, 1000) : null, 'created_by' => $author->id,
            ]);
        });
    }

    private function templateAttributes(array $meta): array
    {
        $title = mb_substr(trim(strip_tags((string) $meta['title'])), 0, 120);

        return [
            'kind' => $meta['kind'],
            'category' => array_key_exists($meta['category'] ?? '', MarketplaceTemplate::CATEGORIES) ? $meta['category'] : 'product',
            'title' => $title,
            'slug' => Str::slug($title ?: 'template').'-'.Str::lower(Str::random(6)),
            'tagline' => isset($meta['tagline']) ? mb_substr(strip_tags((string) $meta['tagline']), 0, 200) : null,
            'description' => isset($meta['description']) ? mb_substr(strip_tags((string) $meta['description']), 0, 5000) : null,
            'price_cents' => 0, // sem cobrança até existir operação comercial e fiscal para templates pagos
        ];
    }

    private function recordInstall(MarketplaceTemplate $template, MarketplaceTemplateVersion $version, User $user, ?int $importId, string $status): MarketplaceInstall
    {
        MarketplaceTemplate::query()->whereKey($template->id)->increment('installs_count');

        return MarketplaceInstall::query()->create([
            'template_id' => $template->id, 'version_id' => $version->id, 'tenant_id' => (int) $user->tenant_id,
            'user_id' => $user->id, 'product_import_id' => $importId, 'status' => $status,
        ]);
    }

    private function adjustRating(int $templateId, int $count, int $sum): void
    {
        MarketplaceTemplate::query()->whereKey($templateId)->update([
            'rating_count' => DB::raw('rating_count + ('.$count.')'),
            'rating_sum' => DB::raw('rating_sum + ('.$sum.')'),
        ]);
    }

    private function deletePackage(MarketplaceTemplateVersion $version): void
    {
        if ($version->package_path) {
            Storage::disk('local')->delete($version->package_path);
        }
    }

    /** Instalar e publicar criam/expõem conteúdo da conta: só o titular (equipe não). */
    private function assertOwner(User $user): int
    {
        abort_if($user->isTeam() || ! $user->isInfoprodutor() || (int) $user->tenant_id < 1, 403);

        return (int) $user->tenant_id;
    }
}

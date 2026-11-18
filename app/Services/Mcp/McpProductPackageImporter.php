<?php

namespace App\Services\Mcp;

use App\Models\McpProductImport;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductApprovalService;
use App\Services\StorageService;
use App\Support\MemberLessonMaterialUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Importa um pacote de produtos (zip + manifesto) para a conta de um infoprodutor.
 *
 * Cria produto, ofertas, order bumps e área de membros pelas mesmas ferramentas do MCP de
 * catálogo/membros (mesmas validações). Arquivos do zip (PDF, vídeo, imagem) vão para o
 * storage da plataforma; o produto nasce aprovado (criado pela própria plataforma).
 *
 * Formato do manifesto: docs/mcp-agencia-importacao.md.
 */
class McpProductPackageImporter
{
    public const MAX_DOWNLOAD_BYTES = 6 * 1024 * 1024 * 1024; // 6 GB

    public const MAX_UNCOMPRESSED_BYTES = 8 * 1024 * 1024 * 1024; // 8 GB

    public const MAX_ENTRIES = 3000;

    private const VIDEO_EXT = ['mp4', 'm4v', 'webm'];

    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    private const DOC_EXT = ['pdf'];

    private const MANIFEST_NAMES = ['brasapay.json', 'produto.json', 'produtos.json', 'manifest.json'];

    private string $workDir = '';

    /** @var array<string, string> caminho normalizado (minúsculo) => arquivo extraído */
    private array $files = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<array<string, mixed>> */
    private array $created = [];

    public function __construct(
        private readonly McpCatalogService $catalog,
        private readonly McpMembersService $members,
        private readonly ProductApprovalService $approval,
    ) {}

    public function run(McpProductImport $import): void
    {
        $claimed = McpProductImport::query()
            ->whereKey($import->id)
            ->where('status', McpProductImport::STATUS_QUEUED)
            ->update(['status' => McpProductImport::STATUS_RUNNING, 'started_at' => now()]);
        if ($claimed !== 1) {
            return; // já rodou ou está rodando (reentrega da fila)
        }
        $import->refresh();

        $this->workDir = storage_path('app/mcp-imports/'.$import->uuid);
        File::ensureDirectoryExists($this->workDir);

        try {
            $actor = User::query()->findOrFail((int) $import->actor_user_id);
            $tenantId = (int) $import->tenant_id;

            $this->progress($import, 'baixando o pacote');
            $zipPath = $this->download((string) $import->source_url);

            $this->progress($import, 'abrindo o zip');
            $this->extract($zipPath);
            @unlink($zipPath);

            $manifest = is_array($import->manifest) && $import->manifest !== [] ? $import->manifest : $this->manifestFromZip();
            $products = $this->productsFromManifest($manifest);

            $nameToId = [];
            foreach ($products as $i => $spec) {
                $this->progress($import, 'criando produto '.($i + 1).' de '.count($products).': '.($spec['nome'] ?? ''));
                $productId = $this->createProduct($tenantId, $spec, $actor);
                $nameToId[mb_strtolower(trim((string) $spec['nome']))] = $productId;
                $this->createMemberArea($import, $tenantId, $productId, $spec);
            }

            foreach ($products as $spec) {
                $this->createOrderBumps($tenantId, $nameToId, $spec);
            }

            $import->forceFill([
                'status' => McpProductImport::STATUS_DONE,
                'finished_at' => now(),
                'result' => ['produtos' => $this->created, 'avisos' => $this->warnings],
                'progress' => ['etapa' => 'concluído'],
            ])->save();
        } catch (\Throwable $e) {
            report($e);
            $import->forceFill([
                'status' => McpProductImport::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e instanceof McpCatalogException || $e instanceof RuntimeException ? $e->getMessage() : 'Falha interna na importação.', 0, 2000),
                'result' => ['produtos_criados_antes_da_falha' => $this->created, 'avisos' => $this->warnings],
            ])->save();
        } finally {
            File::deleteDirectory($this->workDir);
        }
    }

    // ------------------------------------------------------------ download e zip

    private function download(string $url): string
    {
        self::assertPublicHttpsUrl($url);

        $target = $this->workDir.'/pacote.zip';
        $response = Http::withOptions([
            'sink' => $target,
            'allow_redirects' => false,
            'connect_timeout' => 20,
            'timeout' => 3600,
            'on_headers' => function ($res) {
                $len = (int) $res->getHeaderLine('Content-Length');
                if ($len > self::MAX_DOWNLOAD_BYTES) {
                    throw new RuntimeException('Pacote maior que o limite de 6 GB.');
                }
            },
        ])->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('Não foi possível baixar o pacote (HTTP '.$response->status().'). Gere um link novo e tente de novo.');
        }
        clearstatcache(true, $target);
        if (! is_file($target) || filesize($target) === 0) {
            // Handler sem suporte a "sink" (ex.: Http::fake): grava o corpo da resposta.
            $body = $response->body();
            if ($body !== '' && strlen($body) <= self::MAX_DOWNLOAD_BYTES) {
                file_put_contents($target, $body);
                clearstatcache(true, $target);
            }
        }
        if (! is_file($target) || filesize($target) === 0) {
            throw new RuntimeException('O pacote baixado está vazio.');
        }
        if (filesize($target) > self::MAX_DOWNLOAD_BYTES) {
            throw new RuntimeException('Pacote maior que o limite de 6 GB.');
        }

        return $target;
    }

    /**
     * Só https e só host público: o servidor não pode ser usado para acessar a rede interna.
     */
    public static function assertPublicHttpsUrl(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new McpCatalogException('package_url precisa ser um link https.', 'invalid_params');
        }
        $host = (string) $parts['host'];
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            throw new McpCatalogException('Não foi possível resolver o host do package_url.', 'invalid_params');
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new McpCatalogException('package_url aponta para um endereço interno.', 'invalid_params');
            }
        }
    }

    private function extract(string $zipPath): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('O arquivo enviado não é um zip válido.');
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new RuntimeException('O zip tem arquivos demais (máximo '.self::MAX_ENTRIES.').');
            }

            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (! is_array($stat)) {
                    continue;
                }
                $total += (int) $stat['size'];
                if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new RuntimeException('O conteúdo descompactado passa de 8 GB.');
                }
            }

            $allowed = array_merge(self::VIDEO_EXT, self::IMAGE_EXT, self::DOC_EXT, ['json']);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $key = $this->normalizeKey($name);
                if ($key === null || str_ends_with($name, '/')) {
                    continue;
                }
                $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
                if (! in_array($ext, $allowed, true)) {
                    continue;
                }

                // Nome no disco gerado por nós: nada do zip vira caminho de verdade (zip-slip).
                $dest = $this->workDir.'/f'.$i.'.'.$ext;
                $in = $zip->getStream($name);
                $out = fopen($dest, 'wb');
                if ($in === false || $out === false) {
                    throw new RuntimeException('Falha ao extrair '.$name.'.');
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
                $this->files[$key] = $dest;
            }
        } finally {
            $zip->close();
        }

        if ($this->files === []) {
            throw new RuntimeException('O zip não tem nenhum arquivo aproveitável (PDF, vídeo, imagem ou manifesto).');
        }
    }

    private function normalizeKey(string $name): ?string
    {
        $name = str_replace('\\', '/', $name);
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, '../') || str_starts_with($name, '__MACOSX/')) {
            return null;
        }
        $base = basename($name);
        if (str_starts_with($base, '.')) {
            return null;
        }

        return mb_strtolower(trim($name, '/'));
    }

    /**
     * Acha o arquivo citado no manifesto. Aceita caminho relativo à raiz ou à pasta única
     * em que o zip costuma vir embrulhado.
     */
    private function fileFor(?string $ref): ?string
    {
        $ref = $ref !== null ? $this->normalizeKey($ref) : null;
        if ($ref === null) {
            return null;
        }
        if (isset($this->files[$ref])) {
            return $this->files[$ref];
        }
        foreach ($this->files as $key => $path) {
            if (str_ends_with($key, '/'.$ref)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestFromZip(): array
    {
        foreach ($this->files as $key => $path) {
            if (in_array(basename($key), self::MANIFEST_NAMES, true)) {
                $data = json_decode((string) file_get_contents($path), true);
                if (! is_array($data)) {
                    throw new RuntimeException('O manifesto '.basename($key).' não é um JSON válido.');
                }

                return $data;
            }
        }

        throw new RuntimeException('Manifesto não encontrado. Envie o manifesto na chamada ou inclua produto.json no zip (veja o formato na documentação).');
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array<string, mixed>>
     */
    private function productsFromManifest(array $manifest): array
    {
        $list = $manifest['produtos'] ?? (isset($manifest['nome']) ? [$manifest] : null);
        if (! is_array($list) || $list === []) {
            throw new RuntimeException('O manifesto precisa ter "produtos" (lista) ou os campos de um produto (nome, preco).');
        }
        if (count($list) > 50) {
            throw new RuntimeException('No máximo 50 produtos por pacote.');
        }

        foreach ($list as $i => $p) {
            if (! is_array($p) || trim((string) ($p['nome'] ?? '')) === '' || ! is_numeric($p['preco'] ?? null)) {
                throw new RuntimeException('Produto '.($i + 1).' do manifesto precisa de "nome" e "preco".');
            }
        }

        return array_values($list);
    }

    // ------------------------------------------------------------ criação

    /**
     * @param  array<string, mixed>  $spec
     */
    private function createProduct(int $tenantId, array $spec, User $actor): string
    {
        $args = array_filter([
            'name' => mb_substr(trim((string) $spec['nome']), 0, 255),
            'price' => (float) $spec['preco'],
            'type' => is_string($spec['tipo'] ?? null) ? $spec['tipo'] : Product::TYPE_AREA_MEMBROS,
            'billing_type' => is_string($spec['cobranca'] ?? null) ? $spec['cobranca'] : null,
            'description' => is_string($spec['descricao'] ?? null) ? $spec['descricao'] : null,
            'category' => is_string($spec['categoria'] ?? null) ? $spec['categoria'] : null,
            'support_email' => is_string($spec['email_suporte'] ?? null) ? $spec['email_suporte'] : null,
            'is_active' => true,
        ], fn ($v) => $v !== null);

        $res = $this->catalogWrite($tenantId, 'catalog.create_product', $args);
        $productId = (string) ($res['product']['id'] ?? '');
        if ($productId === '' && ! empty($res['product']['checkout_slug'])) {
            $productId = (string) Product::query()->where('tenant_id', $tenantId)
                ->where('checkout_slug', $res['product']['checkout_slug'])->value('id');
        }
        if ($productId === '') {
            throw new RuntimeException('O catálogo não devolveu o id do produto "'.$args['name'].'".');
        }

        $product = Product::query()->where('tenant_id', $tenantId)->findOrFail($productId);
        if ($product->approval_status !== Product::APPROVAL_APPROVED) {
            try {
                $this->approval->approve($product, $actor);
            } catch (\InvalidArgumentException $e) {
                $this->warnings[] = 'Produto "'.$product->name.'": '.$e->getMessage();
            }
        }

        $cover = $this->fileFor(is_string($spec['capa'] ?? null) ? $spec['capa'] : null);
        if ($cover !== null) {
            $product->update(['image' => $this->store('products', $cover, $spec['capa'])]);
        } elseif (! empty($spec['capa'])) {
            $this->warnings[] = 'Capa "'.$spec['capa'].'" não encontrada no zip.';
        }

        $offers = [];
        foreach ((array) ($spec['ofertas'] ?? []) as $offer) {
            if (! is_array($offer) || ! is_numeric($offer['preco'] ?? null)) {
                continue;
            }
            try {
                $r = $this->catalogWrite($tenantId, 'catalog.create_offer', [
                    'product_id' => $productId,
                    'name' => mb_substr((string) ($offer['nome'] ?? 'Oferta'), 0, 255),
                    'price' => (float) $offer['preco'],
                ]);
                $offers[] = $r['offer'] ?? $r;
            } catch (McpCatalogException $e) {
                $this->warnings[] = 'Oferta "'.($offer['nome'] ?? '').'" de "'.$product->name.'": '.$e->getMessage();
            }
        }

        $fresh = $product->fresh();
        $this->created[] = [
            'product_id' => $productId,
            'nome' => $fresh->name,
            'preco' => (float) $fresh->price,
            'aprovado' => $fresh->approval_status === Product::APPROVAL_APPROVED,
            'checkout_url' => $fresh->checkout_slug ? url('/c/'.$fresh->checkout_slug) : null,
            'ofertas' => $offers,
            'aulas' => 0,
        ];

        return $productId;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function createMemberArea(McpProductImport $import, int $tenantId, string $productId, array $spec): void
    {
        $sections = $spec['area_membros']['secoes'] ?? null;
        if (! is_array($sections) && is_array($spec['area_membros']['modulos'] ?? null)) {
            $sections = [['titulo' => 'Conteúdo', 'modulos' => $spec['area_membros']['modulos']]];
        }
        if (! is_array($sections)) {
            return;
        }

        $lessons = 0;
        foreach ($sections as $s) {
            if (! is_array($s)) {
                continue;
            }
            $section = $this->members->call('members.create_section', [
                'product_id' => $productId,
                'title' => mb_substr((string) ($s['titulo'] ?? 'Conteúdo'), 0, 255),
            ], $tenantId);
            $sectionId = $section['section']['id'] ?? null;

            foreach ((array) ($s['modulos'] ?? []) as $m) {
                if (! is_array($m)) {
                    continue;
                }
                $module = $this->members->call('members.create_module', [
                    'product_id' => $productId,
                    'section_id' => $sectionId,
                    'title' => mb_substr((string) ($m['titulo'] ?? 'Módulo'), 0, 255),
                ], $tenantId);
                $moduleId = $module['module']['id'] ?? null;

                foreach ($this->lessonSpecs($m) as $l) {
                    $this->progress($import, 'aula: '.($l['titulo'] ?? ''));
                    if ($this->createLesson($tenantId, $productId, $moduleId, $l)) {
                        $lessons++;
                    }
                }
            }
        }

        $last = array_key_last($this->created);
        if ($last !== null) {
            $this->created[$last]['aulas'] = $lessons;
        }
    }

    /**
     * Aulas listadas no manifesto ou, com "pasta", todos os PDFs/vídeos daquela pasta do zip
     * em ordem natural do nome do arquivo.
     *
     * @param  array<string, mixed>  $module
     * @return list<array<string, mixed>>
     */
    private function lessonSpecs(array $module): array
    {
        if (is_array($module['aulas'] ?? null)) {
            return array_values(array_filter($module['aulas'], 'is_array'));
        }

        $folder = is_string($module['pasta'] ?? null) ? $this->normalizeKey($module['pasta']) : null;
        if ($folder === null) {
            return [];
        }

        $found = [];
        foreach (array_keys($this->files) as $key) {
            $dir = dirname($key);
            if ($dir === $folder || str_ends_with($dir, '/'.$folder)) {
                $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
                if (in_array($ext, array_merge(self::VIDEO_EXT, self::DOC_EXT), true)) {
                    $found[] = $key;
                }
            }
        }
        natcasesort($found);

        return array_values(array_map(fn (string $key) => [
            'titulo' => Str::of(pathinfo($key, PATHINFO_FILENAME))->replace(['_', '-'], ' ')->squish()->title()->toString(),
            'arquivo' => $key,
        ], $found));
    }

    /**
     * @param  array<string, mixed>  $l
     */
    private function createLesson(int $tenantId, string $productId, mixed $moduleId, array $l): bool
    {
        $title = mb_substr(trim((string) ($l['titulo'] ?? 'Aula')), 0, 255);
        $args = ['product_id' => $productId, 'module_id' => $moduleId, 'title' => $title];

        $file = is_string($l['arquivo'] ?? null) ? $l['arquivo'] : null;
        if ($file !== null) {
            $path = $this->fileFor($file);
            if ($path === null) {
                $this->warnings[] = 'Aula "'.$title.'": arquivo "'.$file.'" não está no zip.';

                return false;
            }
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $type = in_array($ext, self::VIDEO_EXT, true) ? 'video' : (in_array($ext, self::DOC_EXT, true) ? 'pdf' : null);
            if ($type === null) {
                $this->warnings[] = 'Aula "'.$title.'": tipo de arquivo .'.$ext.' não vira aula (use PDF ou vídeo).';

                return false;
            }
            $stored = $this->store('member-area/'.$productId, $path, $file);
            $args['type'] = $type;
            $args['content_url'] = app(StorageService::class)->url($stored);
        } elseif (is_string($l['url'] ?? null) && trim($l['url']) !== '') {
            $args['type'] = in_array($l['tipo'] ?? '', ['video', 'link', 'pdf'], true) ? $l['tipo'] : 'link';
            $args['content_url'] = trim($l['url']);
        } elseif (is_string($l['texto'] ?? null) && trim($l['texto']) !== '') {
            $args['type'] = 'text';
            $args['content_text'] = $l['texto'];
        } else {
            $this->warnings[] = 'Aula "'.$title.'" sem arquivo, url ou texto: ignorada.';

            return false;
        }

        try {
            $this->members->call('members.create_lesson', $args, $tenantId);
        } catch (McpCatalogException $e) {
            $this->warnings[] = 'Aula "'.$title.'": '.$e->getMessage();

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, string>  $nameToId
     * @param  array<string, mixed>  $spec
     */
    private function createOrderBumps(int $tenantId, array $nameToId, array $spec): void
    {
        $productId = $nameToId[mb_strtolower(trim((string) $spec['nome']))] ?? null;
        foreach ((array) ($spec['order_bumps'] ?? []) as $bump) {
            if (! is_array($bump) || $productId === null) {
                continue;
            }
            $targetRef = trim((string) ($bump['produto'] ?? ''));
            $target = $nameToId[mb_strtolower($targetRef)]
                ?? (Product::query()->where('tenant_id', $tenantId)->whereKey($targetRef)->exists() ? $targetRef : null);
            if ($target === null) {
                $this->warnings[] = 'Order bump de "'.$spec['nome'].'": produto "'.$targetRef.'" não encontrado.';

                continue;
            }

            try {
                $this->catalogWrite($tenantId, 'catalog.create_order_bump', array_filter([
                    'product_id' => $productId,
                    'target_product_id' => $target,
                    'title' => mb_substr((string) ($bump['titulo'] ?? 'Aproveite também'), 0, 255),
                    'cta_title' => mb_substr((string) ($bump['chamada'] ?? 'Sim, quero adicionar'), 0, 255),
                    'description' => is_string($bump['descricao'] ?? null) ? $bump['descricao'] : null,
                    'price_override' => is_numeric($bump['preco'] ?? null) ? (float) $bump['preco'] : null,
                ], fn ($v) => $v !== null));
            } catch (McpCatalogException $e) {
                $this->warnings[] = 'Order bump de "'.$spec['nome'].'": '.$e->getMessage();
            }
        }
    }

    // ------------------------------------------------------------ apoio

    /**
     * Escrita no catálogo pelas mesmas validações do MCP. Quando a ferramenta pede confirmação
     * humana, quem confirma é a própria plataforma (admin dono da chave) e o plano é aplicado.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function catalogWrite(int $tenantId, string $tool, array $args): array
    {
        try {
            return $this->catalog->call($tool, $args, $tenantId);
        } catch (McpCatalogException $e) {
            $previewId = $e->details['preview_id'] ?? null;
            if ($e->errorCode !== 'needs_confirmation' || ! is_string($previewId)) {
                throw $e;
            }
            $applied = $this->catalog->call('catalog.apply_plan', ['preview_id' => $previewId], $tenantId);
            $first = $applied['results'][0] ?? $applied['applied'][0] ?? $applied;

            return is_array($first) ? $first : $applied;
        }
    }

    private function store(string $directory, string $path, string $originalName): string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $safeName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) ?: 'arquivo';
        $safeName = mb_substr($safeName, 0, 80).'-'.Str::lower(Str::random(6)).'.'.$ext;
        $mime = match (true) {
            in_array($ext, self::DOC_EXT, true) => 'application/pdf',
            $ext === 'webm' => 'video/webm',
            in_array($ext, self::VIDEO_EXT, true) => 'video/mp4',
            $ext === 'png' => 'image/png',
            $ext === 'webp' => 'image/webp',
            $ext === 'gif' => 'image/gif',
            default => 'image/jpeg',
        };

        $upload = new UploadedFile($path, $safeName, $mime, null, true);
        $options = $ext === 'pdf' ? MemberLessonMaterialUpload::storageUploadOptions($safeName, $mime) : [];

        return app(StorageService::class)->putFileAs($directory, $upload, $safeName, $options);
    }

    private function progress(McpProductImport $import, string $step): void
    {
        $import->forceFill(['progress' => [
            'etapa' => $step,
            'produtos_criados' => count($this->created),
            'avisos' => count($this->warnings),
        ]])->saveQuietly();
    }
}

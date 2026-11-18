<?php

namespace App\Services\Mcp;

use App\Http\Controllers\Platform\FinancialController;
use App\Http\Controllers\Platform\KycVerificationsController;
use App\Http\Controllers\Platform\MedDisputesController;
use App\Http\Controllers\Platform\PlatformProductsController;
use App\Http\Controllers\Platform\TransactionsController;
use App\Http\Controllers\Platform\UsersController;
use App\Jobs\ImportMcpProductPackageJob;
use App\Models\McpPlatformKey;
use App\Models\McpProductImport;
use App\Models\MedDispute;
use App\Models\OpsAlert;
use App\Models\Order;
use App\Models\Product;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\MerchantWalletAdminBlockService;
use App\Services\MerchantWithdrawalService;
use App\Services\OrderFeeBreakdownService;
use App\Services\PaymentOperationsHealthService;
use App\Services\Platform\CommandCenterService;
use App\Services\Platform\PlatformAdminAlertCounts;
use App\Services\Platform\PlatformDashboardAnalytics;
use App\Services\Platform\PlatformTotpService;
use App\Services\PlatformAuditService;
use App\Services\ProductApprovalService;
use App\Services\Withdrawal\WithdrawalPolicyService;
use App\Services\WithdrawalPixReceiptService;
use App\Support\MoneyDecimal;
use App\Support\OrderManualRefund;
use App\Support\PlatformDashboardPeriod;
use App\Support\PlatformTransactionsListing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use App\Support\NormalizedEmail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * MCP do administrador da plataforma: leitura de toda a operação e ações do painel
 * em duas etapas (prévia + confirmação com 2FA/PIN).
 */
class McpPlatformService
{
    private const PERIODS = ['hoje', 'ontem', '7dias', 'mes', 'ano', 'total', 'personalizado'];

    private const ACTIONS = [
        'withdrawal.approve',
        'withdrawal.reject',
        'kyc.approve',
        'kyc.reject',
        'account.approve',
        'product.approve',
        'product.reject',
        'order.refund',
        'med.resolve',
    ];

    private const MAX_LIMIT = 50;

    /** Fora do alcance da chave de agência: ações de dinheiro, KYC, MED e dados de saque. */
    private const AGENCY_BLOCKED = [
        'platform.preview_action',
        'platform.confirm_action',
        'platform.list_withdrawals',
        'platform.list_med_disputes',
    ];

    private const SELLER_PREFIX = 'seller.';

    /** Chave de integração (agência) em uso nesta requisição; null = admin logado por OAuth. */
    private ?McpPlatformKey $key = null;

    public function __construct(
        private readonly McpPreviewStore $previews,
        private readonly McpPanelActionRunner $runner,
        private readonly McpCatalogService $catalog,
        private readonly McpMembersService $members,
        private readonly ProductApprovalService $approval,
    ) {}

    public function useKey(?McpPlatformKey $key): void
    {
        $this->key = $key;
    }

    /**
     * Lista entregue ao cliente MCP. Com chave de agência: sem ações de dinheiro/KYC/MED e
     * nomes só com [a-z0-9_] (a API da Anthropic não aceita ponto em nome de ferramenta).
     *
     * @return list<array<string, mixed>>
     */
    public function toolDefinitions(): array
    {
        $defs = array_merge($this->allDefinitions(), $this->agencyDefinitions());
        if ($this->key === null) {
            return $defs;
        }

        $out = [];
        foreach ($defs as $def) {
            if (in_array($def['name'], self::AGENCY_BLOCKED, true)) {
                continue;
            }
            $def['name'] = $this->safeName($def['name']);
            $out[] = $def;
        }

        return $out;
    }

    private function safeName(string $name): string
    {
        return str_replace('.', '_', $name);
    }

    /** Nome recebido (com "_" na chave de agência) → nome interno. */
    private function resolveName(string $name): string
    {
        if ($this->key === null) {
            return $name;
        }
        foreach (array_merge($this->allDefinitions(), $this->agencyDefinitions()) as $def) {
            if ($this->safeName($def['name']) === $name || $def['name'] === $name) {
                return $def['name'];
            }
        }

        return $name;
    }

    public function agencyInstructions(): string
    {
        return ' Agência: platform.create_seller cria a conta do cliente (infoprodutor) e manda o e-mail para ele criar a senha; '
            .'platform.import_product_package importa um zip com manifesto (produtos, preços, ofertas, order bumps, capas, PDFs e vídeos) '
            .'para a conta e devolve import_id; acompanhe com platform.import_status. As ferramentas seller.* (catálogo e área de membros) '
            .'agem dentro da conta indicada em tenant_id; produto criado por aqui já nasce aprovado.';
    }

    public function instructions(): string
    {
        if ($this->key !== null) {
            return 'Servidor MCP da plataforma BrasaPay, acesso de agência (somente leitura e criação; sem saque, reembolso, KYC ou MED). '
                .'Leitura: platform_overview, platform_kpis, platform_list_orders, platform_get_order, platform_list_sellers, platform_get_seller, '
                .'platform_list_pending_approvals, platform_payment_health.'
                .str_replace('.', '_', $this->agencyInstructions());
        }

        return $this->adminInstructions().$this->agencyInstructions();
    }

    private function adminInstructions(): string
    {
        return 'Servidor MCP do administrador da plataforma BrasaPay. Leitura: platform.overview (pendências e números de hoje), '
            .'platform.kpis (GMV, taxas, líquido, meios de pagamento, top sellers por período), platform.list_orders e platform.get_order (vendas de todas as contas), '
            .'platform.list_sellers e platform.get_seller (saldo, MED, saques), platform.list_withdrawals, platform.list_pending_approvals (produtos, KYC, contas), '
            .'platform.list_med_disputes e platform.payment_health. '
            .'Ações (aprovar/recusar saque, aprovar/recusar KYC, aprovar conta, aprovar/recusar produto, reembolsar pedido, resolver MED) são sempre em duas etapas: '
            .'platform.preview_action devolve o resumo e um preview_id; mostre o resumo ao administrador e só chame platform.confirm_action depois que ele confirmar '
            .'e informar o código 2FA (ou o PIN de operação). Nunca confirme por conta própria nem reaproveite um código.';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allDefinitions(): array
    {
        $period = [
            'type' => 'string',
            'enum' => self::PERIODS,
            'description' => 'Período. Padrão: 7dias. Use personalizado com date_from e date_to (YYYY-MM-DD).',
        ];
        $paging = [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT],
            'offset' => ['type' => 'integer', 'minimum' => 0],
        ];

        return [
            $this->tool('platform.overview', 'Visão geral da operação agora: pendências (KYC, contas, produtos, saques, MED, reembolsos), volume e taxas de hoje e 7 dias, alertas abertos. Somente leitura.', [
                'type' => 'object',
                'properties' => new \stdClass,
                'additionalProperties' => false,
            ]),
            $this->tool('platform.kpis', 'Indicadores da plataforma no período: vendas (GMV), quantidade, ticket, taxas cobradas, custo de adquirente, faturamento líquido, saques, meios de pagamento, adquirentes, top sellers e top produtos, comparação com o período anterior. Somente leitura.', [
                'type' => 'object',
                'properties' => [
                    'period' => $period,
                    'date_from' => ['type' => 'string'],
                    'date_to' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ]),
            $this->tool('platform.list_orders', 'Lista vendas de todas as contas. Filtros: período, status, busca (id, e-mail, comprador, vendedor, produto, documento), gateway, meio de pagamento, tenant_id. Somente leitura.', [
                'type' => 'object',
                'properties' => [
                    'period' => $period,
                    'date_from' => ['type' => 'string'],
                    'date_to' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'enum' => ['all', 'pending', 'completed', 'disputed', 'cancelled', 'refunded', 'refund_requests']],
                    'q' => ['type' => 'string'],
                    'gateway' => ['type' => 'string'],
                    'payment_method' => ['type' => 'string'],
                    'tenant_id' => ['type' => 'integer'],
                ] + $paging,
                'additionalProperties' => false,
            ]),
            $this->tool('platform.get_order', 'Detalhe de um pedido de qualquer conta: valores, taxas, status, produto, vendedor, comprador e se pode ser reembolsado. Somente leitura.', [
                'type' => 'object',
                'properties' => [
                    'order_id' => ['type' => 'integer'],
                    'public_reference' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ]),
            $this->tool('platform.list_sellers', 'Lista infoprodutores com status da conta, KYC e saldo. Somente leitura.', [
                'type' => 'object',
                'properties' => [
                    'q' => ['type' => 'string', 'description' => 'Nome, e-mail ou documento.'],
                    'status' => ['type' => 'string', 'enum' => ['approved', 'pending', 'rejected', 'suspended', 'blocked']],
                    'sort' => ['type' => 'string', 'enum' => ['created_at', 'balance']],
                ] + $paging,
                'additionalProperties' => false,
            ]),
            $this->tool('platform.get_seller', 'Detalhe de um infoprodutor: conta, KYC, saldo por bucket, valor retido em MED, bloqueio administrativo, vendas e últimos saques. Somente leitura.', [
                'type' => 'object',
                'properties' => [
                    'user_id' => ['type' => 'integer', 'description' => 'ID do usuário infoprodutor (igual ao tenant_id).'],
                ],
                'required' => ['user_id'],
                'additionalProperties' => false,
            ]),
            $this->tool('platform.list_withdrawals', 'Lista saques dos infoprodutores (pendentes por padrão), com valor, taxa, líquido, adquirente de payout e erros. Somente leitura.', [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['pending', 'paid', 'rejected', 'failed', 'all']],
                    'tenant_id' => ['type' => 'integer'],
                ] + $paging,
                'additionalProperties' => false,
            ]),
            $this->tool('platform.list_pending_approvals', 'O que está esperando o administrador: produtos aguardando aprovação, KYC em análise e contas aguardando aprovação básica. Somente leitura.', [
                'type' => 'object',
                'properties' => new \stdClass,
                'additionalProperties' => false,
            ]),
            $this->tool('platform.list_med_disputes', 'Disputas MED (abertas por padrão) com conta, pedido, valor e responsável. Somente leitura; não consulta o gateway.', [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['open', 'resolved', 'all']],
                    'tenant_id' => ['type' => 'integer'],
                ] + $paging,
                'additionalProperties' => false,
            ]),
            $this->tool('platform.payment_health', 'Saúde dos pagamentos: infraestrutura, webhooks, pedidos pendentes e problemas detectados. Somente leitura.', [
                'type' => 'object',
                'properties' => new \stdClass,
                'additionalProperties' => false,
            ]),
            $this->tool('platform.preview_action', 'Etapa 1 de uma ação do painel. Valida e devolve o resumo do que vai acontecer e um preview_id (vale 15 min). Não executa nada. '
                .'Ações: withdrawal.approve (id do saque; mode gateway envia o PIX pela adquirente, mode manual registra PIX já pago por fora e exige payout_acquirer), '
                .'withdrawal.reject (devolve o saldo; note opcional), kyc.approve, kyc.reject (reason), account.approve (aprovação básica para vender), '
                .'product.approve, product.reject (reason de 20 a 1000 caracteres), order.refund (reason; estorna no gateway quando suportado), '
                .'med.resolve (outcome won, lost ou cancelled; note opcional).', [
                    'type' => 'object',
                    'properties' => [
                        'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                        'id' => ['type' => 'integer', 'description' => 'ID do saque, usuário, produto, pedido ou disputa.'],
                        'reason' => ['type' => 'string'],
                        'note' => ['type' => 'string'],
                        'outcome' => ['type' => 'string', 'enum' => ['won', 'lost', 'cancelled']],
                        'mode' => ['type' => 'string', 'enum' => ['gateway', 'manual']],
                        'payout_acquirer' => ['type' => 'string'],
                    ],
                    'required' => ['action', 'id'],
                    'additionalProperties' => false,
                ]),
            $this->tool('platform.confirm_action', 'Etapa 2: executa a ação de um preview_id. Só chame depois que o administrador leu o resumo e confirmou. Exige o código 2FA atual do administrador (ou o PIN de operação, se ele não usa 2FA). Segue as mesmas regras do painel e fica na auditoria.', [
                'type' => 'object',
                'properties' => [
                    'preview_id' => ['type' => 'string'],
                    'totp_code' => ['type' => 'string', 'description' => 'Código de 6 dígitos do app autenticador.'],
                    'manual_approval_pin' => ['type' => 'string', 'description' => 'PIN de operação (só quando o admin não tem 2FA).'],
                ],
                'required' => ['preview_id'],
                'additionalProperties' => false,
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function call(string $name, array $arguments, User $admin): array
    {
        $name = $this->resolveName($name);
        if ($this->key !== null && in_array($name, self::AGENCY_BLOCKED, true)) {
            throw new McpCatalogException('A chave de agência não tem acesso a esta ferramenta.', 'forbidden');
        }

        if (str_starts_with($name, self::SELLER_PREFIX)) {
            return $this->callSellerTool(substr($name, strlen(self::SELLER_PREFIX)), $arguments, $admin);
        }

        return match ($name) {
            'platform.create_seller' => $this->createSeller($arguments, $admin),
            'platform.import_product_package' => $this->queueImport($arguments, $admin),
            'platform.import_status' => $this->importStatus($arguments),
            'platform.overview' => $this->overview(),
            'platform.kpis' => $this->kpis($arguments),
            'platform.list_orders' => $this->listOrders($arguments),
            'platform.get_order' => $this->getOrder($arguments),
            'platform.list_sellers' => $this->listSellers($arguments),
            'platform.get_seller' => $this->getSeller($arguments),
            'platform.list_withdrawals' => $this->listWithdrawals($arguments),
            'platform.list_pending_approvals' => $this->listPendingApprovals(),
            'platform.list_med_disputes' => $this->listMedDisputes($arguments),
            'platform.payment_health' => app(PaymentOperationsHealthService::class)->buildDashboard(20),
            'platform.preview_action' => $this->previewAction($arguments, $admin),
            'platform.confirm_action' => $this->confirmAction($arguments, $admin),
            default => throw new McpCatalogException('Ferramenta indisponível no MCP da plataforma.', 'unknown_tool'),
        };
    }

    // ---------------------------------------------------------------- agência

    /**
     * @return list<array<string, mixed>>
     */
    private function agencyDefinitions(): array
    {
        $defs = [
            $this->tool('platform.create_seller', 'Cria a conta de um cliente (infoprodutor) e envia a ele o e-mail para criar a senha. A conta já sai liberada para vender; saque continua exigindo KYC, que o cliente faz no painel. Se o e-mail já for de um infoprodutor, devolve a conta existente.', [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Nome do cliente.'],
                    'email' => ['type' => 'string', 'description' => 'E-mail de login do cliente.'],
                    'send_password_email' => ['type' => 'boolean', 'description' => 'Padrão: true.'],
                ],
                'required' => ['name', 'email'],
                'additionalProperties' => false,
            ]),
            $this->tool('platform.import_product_package', 'Importa um pacote (zip) de produtos para a conta do cliente: cria produtos já aprovados, preços, ofertas, order bumps, capas e área de membros com PDFs e vídeos do zip. Roda em segundo plano e devolve import_id. '
                .'package_url: link https do zip (ex.: link assinado do storage). manifest: opcional; sem ele, o zip precisa ter produto.json. '
                .'Formato: {"produtos":[{"nome","preco","descricao","categoria","capa":"capa.jpg","ofertas":[{"nome","preco"}],"order_bumps":[{"produto":"nome de outro produto","titulo","chamada","descricao","preco"}],'
                .'"area_membros":{"secoes":[{"titulo","modulos":[{"titulo","aulas":[{"titulo","arquivo":"modulo1/aula1.mp4"}|{"titulo","url","tipo":"video|link|pdf"}|{"titulo","texto"}]} ou {"titulo","pasta":"modulo1"}]}]}}]}.', [
                    'type' => 'object',
                    'properties' => [
                        'tenant_id' => ['type' => 'integer', 'description' => 'Conta do cliente (user_id do infoprodutor).'],
                        'package_url' => ['type' => 'string'],
                        'manifest' => ['type' => 'object'],
                    ],
                    'required' => ['tenant_id', 'package_url'],
                    'additionalProperties' => false,
                ]),
            $this->tool('platform.import_status', 'Situação de uma importação de pacote: etapa atual, produtos criados (ids e links de checkout), avisos e erro.', [
                'type' => 'object',
                'properties' => [
                    'import_id' => ['type' => 'string'],
                ],
                'required' => ['import_id'],
                'additionalProperties' => false,
            ]),
        ];

        foreach (array_merge($this->catalog->toolDefinitions(), $this->members->toolDefinitions()) as $def) {
            if (($def['name'] ?? '') === 'catalog.ping') {
                continue;
            }
            $schema = is_array($def['inputSchema'] ?? null) ? $def['inputSchema'] : ['type' => 'object'];
            $props = $schema['properties'] ?? [];
            $props = $props instanceof \stdClass ? (array) $props : (is_array($props) ? $props : []);
            $schema['properties'] = ['tenant_id' => ['type' => 'integer', 'description' => 'Conta do cliente (user_id do infoprodutor).']] + $props;
            $schema['required'] = array_values(array_unique(array_merge(['tenant_id'], (array) ($schema['required'] ?? []))));

            $defs[] = [
                'name' => self::SELLER_PREFIX.$def['name'],
                'description' => '[Conta do cliente] '.(string) ($def['description'] ?? ''),
                'inputSchema' => $schema,
            ];
        }

        return $defs;
    }

    /**
     * Ferramenta de catálogo/membros executada dentro da conta do cliente. Produto que essa
     * chamada deixar pendente de aprovação é aprovado pela plataforma (o admin dono do acesso).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callSellerTool(string $tool, array $arguments, User $admin): array
    {
        $tenantId = (int) ($arguments['tenant_id'] ?? 0);
        $seller = $this->findSeller($tenantId);
        $tenantId = (int) ($seller->tenant_id ?? $seller->id);
        unset($arguments['tenant_id']);

        $isCatalog = str_starts_with($tool, 'catalog.');
        $pendingBefore = $isCatalog ? $this->pendingProductIds($tenantId) : [];

        $result = $isCatalog
            ? $this->catalog->call($tool, $arguments, $tenantId)
            : $this->members->call($tool, $arguments, $tenantId);

        if ($isCatalog) {
            $approved = [];
            foreach (array_diff($this->pendingProductIds($tenantId), $pendingBefore) as $productId) {
                $product = Product::query()->find($productId);
                if ($product === null) {
                    continue;
                }
                try {
                    $this->approval->approve($product, $admin, $this->auditRequest($admin));
                    $approved[] = $product->id;
                } catch (\InvalidArgumentException) {
                    // já aprovado ou recusado: segue o estado atual
                }
            }
            if ($approved !== []) {
                $result['aprovados_pela_plataforma'] = $approved;
            }
        }

        PlatformAuditService::log('platform.mcp.seller_tool', [
            'tool' => $tool,
            'tenant_id' => $tenantId,
            'key_id' => $this->key?->id,
        ], $this->auditRequest($admin));

        return $result;
    }

    /**
     * @return list<string>
     */
    private function pendingProductIds(int $tenantId): array
    {
        return Product::query()->where('tenant_id', $tenantId)
            ->where('approval_status', Product::APPROVAL_PENDING)
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function createSeller(array $arguments, User $admin): array
    {
        $name = $this->str($arguments, 'name');
        $email = NormalizedEmail::normalize((string) ($arguments['email'] ?? ''));
        if ($name === null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new McpCatalogException('Informe name e um email válido.', 'invalid_params');
        }

        $existing = User::query()->where('email', $email)->first();
        if ($existing !== null) {
            if ($existing->role !== User::ROLE_INFOPRODUTOR) {
                throw new McpCatalogException('Este e-mail já pertence a outro tipo de conta.', 'email_in_use');
            }

            return $this->sellerCreatedPayload($existing, alreadyExisted: true, emailSent: false);
        }

        $password = Str::password(24);
        $created = $this->runner->run($admin, '/plataforma/usuarios', [
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ], fn (Request $r) => app()->call([app(UsersController::class), 'store'], ['request' => $r]));
        if (! $created['ok']) {
            throw new McpCatalogException($created['message'], 'create_failed');
        }

        $user = User::query()->where('email', $email)->firstOrFail();

        // Criada pela plataforma: já pode vender. Saque segue exigindo KYC.
        if ((string) ($user->account_status ?? '') === 'pending') {
            $approved = $this->runner->run($admin, '/plataforma/usuarios/'.$user->id.'/aprovacao-basica', [],
                fn (Request $r) => app()->call([app(UsersController::class), 'approveBasic'], ['request' => $r, 'user' => $user]));
            if (! $approved['ok']) {
                throw new McpCatalogException('Conta criada, mas não foi aprovada para vender: '.$approved['message'], 'approve_failed');
            }
        }

        $emailSent = false;
        if (($arguments['send_password_email'] ?? true) !== false) {
            try {
                $emailSent = Password::sendResetLink(['email' => $email]) === Password::RESET_LINK_SENT;
            } catch (\Throwable $e) {
                // A conta já existe; falha de e-mail não desfaz o cadastro. O cliente pode usar "Esqueci a senha".
                report($e);
            }
        }

        PlatformAuditService::log('platform.mcp.seller_created', [
            'user_id' => $user->id,
            'key_id' => $this->key?->id,
            'email_sent' => $emailSent,
        ], $this->auditRequest($admin));

        return $this->sellerCreatedPayload($user->fresh(), alreadyExisted: false, emailSent: $emailSent);
    }

    /**
     * @return array<string, mixed>
     */
    private function sellerCreatedPayload(User $user, bool $alreadyExisted, bool $emailSent): array
    {
        return [
            'user_id' => $user->id,
            'tenant_id' => (int) ($user->tenant_id ?? $user->id),
            'name' => $user->name,
            'email' => $user->email,
            'account_status' => $user->account_status,
            'kyc_status' => $user->kyc_status,
            'ja_existia' => $alreadyExisted,
            'email_de_senha_enviado' => $emailSent,
            'login_url' => url('/login'),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function queueImport(array $arguments, User $admin): array
    {
        $seller = $this->findSeller((int) ($arguments['tenant_id'] ?? 0));
        $url = $this->str($arguments, 'package_url') ?? '';
        McpProductPackageImporter::assertPublicHttpsUrl($url);
        $manifest = is_array($arguments['manifest'] ?? null) ? $arguments['manifest'] : null;

        $import = McpProductImport::query()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => (int) ($seller->tenant_id ?? $seller->id),
            'platform_key_id' => $this->key?->id,
            'actor_user_id' => $admin->id,
            'source_url' => $url,
            'manifest' => $manifest,
            'status' => McpProductImport::STATUS_QUEUED,
            'progress' => ['etapa' => 'na fila'],
        ]);

        PlatformAuditService::log('platform.mcp.import_queued', [
            'import_id' => $import->uuid,
            'tenant_id' => $import->tenant_id,
            'key_id' => $this->key?->id,
        ], $this->auditRequest($admin));

        ImportMcpProductPackageJob::dispatch($import->id);

        return $import->fresh()->toPublicArray() + [
            'proximo_passo' => 'Acompanhe com '.($this->key !== null ? 'platform_import_status' : 'platform.import_status').' usando o import_id.',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function importStatus(array $arguments): array
    {
        $import = McpProductImport::query()->where('uuid', $this->str($arguments, 'import_id') ?? '')->first();
        if ($import === null) {
            throw new McpCatalogException('Importação não encontrada.', 'not_found');
        }

        return $import->toPublicArray();
    }

    // ---------------------------------------------------------------- leitura

    /**
     * @return array<string, mixed>
     */
    private function overview(): array
    {
        $counts = app(PlatformAdminAlertCounts::class);
        $snapshot = Cache::remember('command_center:v2', 60, fn () => app(CommandCenterService::class)->snapshot());

        $alerts = Schema::hasTable('ops_alerts')
            ? OpsAlert::query()->whereIn('status', ['open', 'acknowledged'])->latest('last_seen_at')->limit(20)
                ->get(['id', 'severity', 'area', 'title', 'hint', 'status', 'first_seen_at', 'last_seen_at'])->toArray()
            : [];

        return [
            'pendencias' => [
                'kyc_em_analise' => $counts->kycPendingCount(),
                'contas_aguardando_aprovacao' => $counts->pendingAccountApprovalCount(),
                'produtos_aguardando_aprovacao' => $counts->productsPendingCount(),
                'saques_pendentes' => $counts->withdrawalsPendingCount(),
                'saques_com_falha' => $counts->withdrawalsFailedCount(),
                'med_abertas' => $counts->medDisputesOpenCount(),
                'pedidos_de_reembolso' => $counts->refundRequestsPendingCount(),
                'reembolsos_aguardando_gateway' => $counts->refundPendingOrdersCount(),
            ],
            'executivo' => $snapshot['executive'] ?? [],
            'saques' => $snapshot['withdrawals'] ?? [],
            'adquirentes' => $snapshot['acquirers'] ?? [],
            'alertas_calculados' => $snapshot['alerts'] ?? [],
            'alertas_abertos' => $alerts,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function kpis(array $arguments): array
    {
        [$period, $start, $end] = $this->range($arguments);
        $data = PlatformDashboardAnalytics::compute($period, $start, $end);

        return [
            'period' => $period,
            'start' => $start,
            'end' => $end,
            'kpis' => $data['kpis'] ?? [],
            'comparacao_periodo_anterior' => $data['comparisons'] ?? [],
            'meios_de_pagamento' => $data['payment_methods'] ?? [],
            'adquirentes' => $data['acquirers'] ?? [],
            'top_sellers' => $data['top_sellers'] ?? [],
            'top_produtos' => $data['top_products'] ?? [],
            'funil' => $data['funnel'] ?? [],
            'alertas' => $data['alerts'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function listOrders(array $arguments): array
    {
        [$period, $start, $end] = $this->range($arguments);
        [$limit, $offset] = $this->paging($arguments);

        $query = Order::query()->with(['product', 'tenantOwner'])->orderByDesc('created_at');
        PlatformDashboardAnalytics::applyPeriod($query, $start, $end);
        PlatformTransactionsListing::applyStatusFilter($query, PlatformTransactionsListing::normalizeStatus($this->str($arguments, 'status') ?? 'all'));
        PlatformTransactionsListing::applyGatewayFilter($query, PlatformTransactionsListing::normalizeGateway($arguments['gateway'] ?? null));
        PlatformTransactionsListing::applyPaymentMethodFilter($query, PlatformTransactionsListing::normalizePaymentMethod($arguments['payment_method'] ?? null));
        $q = $this->str($arguments, 'q');
        if ($q !== null) {
            PlatformTransactionsListing::applySearchFilter($query, $q);
        }
        if (isset($arguments['tenant_id']) && (int) $arguments['tenant_id'] > 0) {
            $query->where('tenant_id', (int) $arguments['tenant_id']);
        }

        $total = (clone $query)->count();
        $rows = $query->skip($offset)->take($limit)->get();

        return [
            'period' => $period,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'orders' => $rows->map(fn (Order $o) => $this->orderRow($o))->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function getOrder(array $arguments): array
    {
        $order = $this->findOrder($arguments);
        $order->loadMissing(['product', 'tenantOwner', 'orderItems']);

        $row = $this->orderRow($order);
        $row['taxas'] = OrderFeeBreakdownService::forOrder($order);
        $row['itens'] = $order->orderItems->map(fn ($item) => [
            'product_id' => $item->product_id,
            'amount' => MoneyDecimal::toFloat($item->amount ?? 0),
        ])->values()->all();
        $row['pode_reembolsar'] = OrderManualRefund::canManualRefund($order);
        $row['reembolso'] = OrderManualRefund::snapshot($order);
        $row['med_aberta'] = MedDispute::query()->where('order_id', $order->id)->open()->exists();

        return $row;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function listSellers(array $arguments): array
    {
        [$limit, $offset] = $this->paging($arguments);
        $query = User::query()->where('role', User::ROLE_INFOPRODUTOR);

        $q = $this->str($arguments, 'q');
        if ($q !== null) {
            $digits = preg_replace('/\D/', '', $q) ?? '';
            $query->where(function (Builder $w) use ($q, $digits) {
                $like = '%'.mb_strtolower($q).'%';
                $w->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(email) LIKE ?', [$like]);
                if (strlen($digits) >= 6) {
                    $w->orWhere('document', 'like', '%'.$digits.'%');
                }
            });
        }

        $status = $this->str($arguments, 'status');
        if ($status === 'approved') {
            $query->where(fn (Builder $w) => $w->where('account_status', 'approved')->orWhereNull('account_status'));
        } elseif ($status !== null) {
            $query->where('account_status', $status);
        }

        $total = (clone $query)->count();
        if ($this->str($arguments, 'sort') === 'balance' && Schema::hasTable('tenant_wallets')) {
            $query->leftJoin('tenant_wallets', 'tenant_wallets.tenant_id', '=', 'users.id')
                ->select('users.*')
                ->orderByRaw('COALESCE(tenant_wallets.available_pix,0)+COALESCE(tenant_wallets.available_card,0)+COALESCE(tenant_wallets.available_boleto,0) DESC');
        } else {
            $query->orderByDesc('users.created_at');
        }

        $users = $query->skip($offset)->take($limit)->get();
        $wallets = TenantWallet::query()
            ->whereIn('tenant_id', $users->map(fn (User $u) => (int) ($u->tenant_id ?? $u->id))->all())
            ->get()->keyBy('tenant_id');

        return [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'sellers' => $users->map(function (User $u) use ($wallets) {
                $tenantId = (int) ($u->tenant_id ?? $u->id);
                $w = $wallets->get($tenantId);

                return [
                    'user_id' => $u->id,
                    'tenant_id' => $tenantId,
                    'name' => $u->name,
                    'email' => $u->email,
                    'account_status' => $u->account_status ?? 'approved',
                    'kyc_status' => $u->kyc_status,
                    'created_at' => $u->created_at?->toIso8601String(),
                    'saldo_disponivel' => $w ? round((float) $w->available_pix + (float) $w->available_card + (float) $w->available_boleto, 2) : 0.0,
                    'saldo_a_liberar' => $w ? round((float) $w->pending_pix + (float) $w->pending_card + (float) $w->pending_boleto, 2) : 0.0,
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function getSeller(array $arguments): array
    {
        $user = $this->findSeller((int) ($arguments['user_id'] ?? 0));
        $tenantId = (int) ($user->tenant_id ?? $user->id);
        $wallet = TenantWallet::query()->where('tenant_id', $tenantId)->first();

        $carteira = null;
        if ($wallet !== null) {
            MerchantWalletAdminBlockService::expireAdminBlockIfNeeded($wallet);
            $carteira = [
                'disponivel' => [
                    'pix' => (float) $wallet->available_pix,
                    'card' => (float) $wallet->available_card,
                    'boleto' => (float) $wallet->available_boleto,
                    'total' => round((float) $wallet->available_pix + (float) $wallet->available_card + (float) $wallet->available_boleto, 2),
                ],
                'a_liberar' => [
                    'pix' => (float) $wallet->pending_pix,
                    'card' => (float) $wallet->pending_card,
                    'boleto' => (float) $wallet->pending_boleto,
                    'total' => round((float) $wallet->pending_pix + (float) $wallet->pending_card + (float) $wallet->pending_boleto, 2),
                ],
                'sacavel_pix' => MerchantWalletAdminBlockService::effectiveAvailableForWithdrawal($wallet, 'pix'),
                'retido_em_med' => MerchantWalletAdminBlockService::totalMedHoldAmountForTenant($tenantId),
                'bloqueio_admin' => [
                    'saque_bloqueado' => (bool) $wallet->admin_withdrawal_blocked,
                    'valor_bloqueado' => (float) ($wallet->admin_blocked_amount ?? 0),
                    'ate' => $wallet->admin_block_until ? (string) $wallet->admin_block_until : null,
                    'nota' => $wallet->admin_block_note,
                ],
            ];
        }

        $completed = Order::query()->where('tenant_id', $tenantId)->where('status', 'completed');

        return [
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'name' => $user->name,
            'email' => $user->email,
            'account_status' => $user->account_status ?? 'approved',
            'kyc_status' => $user->kyc_status,
            'created_at' => $user->created_at?->toIso8601String(),
            'carteira' => $carteira,
            'vendas' => [
                'total_pago' => MoneyDecimal::toFloat((clone $completed)->sum('amount')),
                'quantidade_paga' => (clone $completed)->count(),
                'ultimos_30_dias' => MoneyDecimal::toFloat((clone $completed)->where('created_at', '>=', now()->subDays(30))->sum('amount')),
            ],
            'med_abertas' => MedDispute::query()->where('tenant_id', $tenantId)->open()->count(),
            'produtos' => Product::query()->where('tenant_id', $tenantId)->count(),
            'ultimos_saques' => Withdrawal::query()->where('tenant_id', $tenantId)->orderByDesc('id')->limit(5)
                ->get(['id', 'amount', 'fee_amount', 'net_amount', 'bucket', 'status', 'created_at'])->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function listWithdrawals(array $arguments): array
    {
        [$limit, $offset] = $this->paging($arguments);
        $status = $this->str($arguments, 'status') ?? 'pending';

        $query = Withdrawal::query()->with(['tenantOwner', 'apiApplication'])->orderByDesc('created_at');
        if ($status === 'pending') {
            $query->whereIn('status', [MerchantWithdrawalService::STATUS_PENDING, MerchantWithdrawalService::STATUS_PROCESSING]);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        if (isset($arguments['tenant_id']) && (int) $arguments['tenant_id'] > 0) {
            $query->where('tenant_id', (int) $arguments['tenant_id']);
        }

        $total = (clone $query)->count();
        $mapper = app(WithdrawalPixReceiptService::class);

        return [
            'status' => $status,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'withdrawals' => $query->skip($offset)->take($limit)->get()
                ->map(fn (Withdrawal $w) => $mapper->mapWithdrawalListItem($w))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listPendingApprovals(): array
    {
        $products = Product::query()
            ->where('approval_status', Product::APPROVAL_PENDING)
            ->orderBy('created_at')
            ->limit(self::MAX_LIMIT)
            ->get(['id', 'name', 'type', 'price', 'currency', 'tenant_id', 'checkout_slug', 'created_at']);
        $owners = User::query()->whereIn('id', $products->pluck('tenant_id')->filter()->unique()->all())->get(['id', 'name', 'email'])->keyBy('id');

        return [
            'produtos' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'type' => $p->type,
                'price' => (float) $p->price,
                'tenant_id' => $p->tenant_id,
                'infoprodutor' => $owners->get($p->tenant_id)?->only(['id', 'name', 'email']),
                'checkout_slug' => $p->checkout_slug,
                'created_at' => $p->created_at?->toIso8601String(),
            ])->values()->all(),
            'kyc_em_analise' => User::query()
                ->where('role', User::ROLE_INFOPRODUTOR)
                ->whereNotNull('tenant_id')
                ->where('kyc_status', User::KYC_PENDING_REVIEW)
                ->orderBy('updated_at')
                ->limit(self::MAX_LIMIT)
                ->get(['id', 'name', 'email', 'person_type', 'kyc_status', 'updated_at'])->toArray(),
            'contas_aguardando_aprovacao' => User::query()
                ->where('role', User::ROLE_INFOPRODUTOR)
                ->where('account_status', 'pending')
                ->orderBy('created_at')
                ->limit(self::MAX_LIMIT)
                ->get(['id', 'name', 'email', 'kyc_status', 'created_at'])->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function listMedDisputes(array $arguments): array
    {
        [$limit, $offset] = $this->paging($arguments);
        $status = $this->str($arguments, 'status') ?? 'open';

        $query = MedDispute::query()->with(['order', 'tenantOwner'])->orderByDesc('opened_at');
        if ($status === 'open') {
            $query->open();
        } elseif ($status === 'resolved') {
            $query->whereIn('status', [MedDispute::STATUS_RESOLVED_WON, MedDispute::STATUS_RESOLVED_LOST, MedDispute::STATUS_CANCELLED]);
        }
        if (isset($arguments['tenant_id']) && (int) $arguments['tenant_id'] > 0) {
            $query->where('tenant_id', (int) $arguments['tenant_id']);
        }

        $total = (clone $query)->count();

        return [
            'status' => $status,
            'total' => $total,
            'disputes' => $query->skip($offset)->take($limit)->get()->map(fn (MedDispute $d) => [
                'id' => $d->id,
                'status' => $d->status,
                'outcome' => $d->outcome,
                'responsavel' => $d->responsible_party,
                'valor' => round(((int) $d->amount_cents) / 100, 2),
                'motivo' => $d->reason,
                'aberta_em' => $d->opened_at?->toIso8601String(),
                'defendida_em' => $d->defended_at?->toIso8601String(),
                'resolvida_em' => $d->resolved_at?->toIso8601String(),
                'tenant' => $d->tenantOwner?->only(['id', 'name', 'email']),
                'pedido' => $d->order ? [
                    'id' => $d->order->id,
                    'public_reference' => $d->order->public_reference,
                    'amount' => MoneyDecimal::toFloat($d->order->amount),
                    'status' => $d->order->status,
                    'gateway' => $d->order->gateway,
                ] : null,
            ])->values()->all(),
        ];
    }

    // ---------------------------------------------------------------- ações

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function previewAction(array $arguments, User $admin): array
    {
        $action = $this->str($arguments, 'action') ?? '';
        if (! in_array($action, self::ACTIONS, true)) {
            throw new McpCatalogException('Ação desconhecida.', 'invalid_action');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if ($id < 1) {
            throw new McpCatalogException('Informe o id do registro.', 'invalid_id');
        }

        [$summary, $params] = match ($action) {
            'withdrawal.approve' => $this->previewWithdrawalApprove($id, $arguments),
            'withdrawal.reject' => $this->previewWithdrawalReject($id, $arguments),
            'kyc.approve', 'kyc.reject' => $this->previewKyc($action, $id, $arguments),
            'account.approve' => $this->previewAccountApprove($id),
            'product.approve', 'product.reject' => $this->previewProduct($action, $id, $arguments),
            'order.refund' => $this->previewRefund($id, $arguments),
            'med.resolve' => $this->previewMed($id, $arguments),
        };

        $stored = $this->previews->put($this->previewScope($admin), [[
            'action' => $action,
            'id' => $id,
            'params' => $params,
        ]]);

        return [
            'preview_id' => $stored['preview_id'],
            'expires_at' => $stored['expires_at'],
            'action' => $action,
            'resumo' => $summary,
            'confirmacao' => $this->stepUpHint($admin),
            'proximo_passo' => 'Mostre este resumo ao administrador. Só chame platform.confirm_action com o preview_id depois que ele confirmar e informar o código.',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function confirmAction(array $arguments, User $admin): array
    {
        $previewId = $this->str($arguments, 'preview_id') ?? '';
        $scope = $this->previewScope($admin);
        $body = $this->previews->get($scope, $previewId);
        if ($body === null) {
            throw new McpCatalogException('Prévia inexistente ou expirada. Gere outra com platform.preview_action.', 'preview_not_found');
        }
        if (! empty($body['consumed_at'])) {
            return ['ja_executada' => true] + (is_array($body['result'] ?? null) ? $body['result'] : []);
        }

        $this->assertStepUp($admin, $arguments);

        $op = $body['operations'][0] ?? null;
        if (! is_array($op)) {
            throw new McpCatalogException('Prévia inválida.', 'preview_invalid');
        }

        $lock = Cache::lock('mcp:platform:confirm:'.$previewId, 120);
        if (! $lock->get()) {
            throw new McpCatalogException('Esta ação já está sendo executada.', 'in_progress');
        }

        try {
            $codes = array_filter([
                'totp_code' => $this->str($arguments, 'totp_code'),
                'manual_approval_pin' => $this->str($arguments, 'manual_approval_pin'),
            ], fn ($v) => $v !== null);

            $result = $this->execute((string) $op['action'], (int) $op['id'], is_array($op['params'] ?? null) ? $op['params'] : [], $codes, $admin);

            PlatformAuditService::log('platform.mcp.action', [
                'action' => $op['action'],
                'id' => $op['id'],
                'ok' => $result['ok'],
                'message' => $result['message'],
            ], $this->auditRequest($admin));

            $payload = [
                'action' => $op['action'],
                'id' => $op['id'],
                'ok' => $result['ok'],
                'mensagem' => $result['message'],
            ];
            if (isset($result['errors'])) {
                $payload['erros'] = $result['errors'];
            }

            // Uso único, com sucesso ou não: uma falha pode ter sido parcial (ex.: saque registrado
            // e envio ao gateway falhou). Para tentar de novo, gere outra prévia e revise o estado.
            $this->previews->markConsumed($scope, $previewId, $payload);

            return $payload;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $codes
     * @return array{ok: bool, message: string, errors?: array<string, list<string>>}
     */
    private function execute(string $action, int $id, array $params, array $codes, User $admin): array
    {
        switch ($action) {
            case 'withdrawal.approve':
                $w = Withdrawal::query()->findOrFail($id);
                $manual = ($params['mode'] ?? 'gateway') === 'manual';
                $input = $codes + [
                    'payout_manual' => $manual ? '1' : '0',
                    'manual_confirm_external' => $manual ? '1' : '0',
                ];
                if (($params['payout_acquirer'] ?? '') !== '') {
                    $input['payout_acquirer'] = (string) $params['payout_acquirer'];
                }

                return $this->runner->run($admin, '/plataforma/financeiro/saques/'.$w->id.'/aprovar', $input,
                    fn (Request $r) => app()->call([app(FinancialController::class), 'approveWithdrawal'], ['request' => $r, 'withdrawal' => $w]));

            case 'withdrawal.reject':
                $w = Withdrawal::query()->findOrFail($id);
                $input = $codes + ['admin_note' => (string) ($params['note'] ?? '')];

                return $this->runner->run($admin, '/plataforma/financeiro/saques/'.$w->id.'/rejeitar', $input,
                    fn (Request $r) => app()->call([app(FinancialController::class), 'rejectWithdrawal'], ['request' => $r, 'withdrawal' => $w]));

            case 'kyc.approve':
            case 'kyc.reject':
                $u = $this->findSeller($id);
                $method = $action === 'kyc.approve' ? 'approve' : 'reject';
                $input = $codes + ($action === 'kyc.reject' ? ['reason' => (string) ($params['reason'] ?? '')] : []);

                return $this->runner->run($admin, '/plataforma/verificacoes-kyc/usuario/'.$u->id.'/'.($method === 'approve' ? 'aprovar' : 'rejeitar'), $input,
                    fn (Request $r) => app()->call([app(KycVerificationsController::class), $method], ['request' => $r, 'user' => $u]));

            case 'account.approve':
                $u = $this->findSeller($id);

                return $this->runner->run($admin, '/plataforma/infoprodutores/'.$u->id.'/aprovacao-basica', $codes,
                    fn (Request $r) => app()->call([app(UsersController::class), 'approveBasic'], ['request' => $r, 'user' => $u]));

            case 'product.approve':
            case 'product.reject':
                $p = Product::query()->findOrFail($id);
                $method = $action === 'product.approve' ? 'approve' : 'reject';
                $input = $codes + ($action === 'product.reject' ? ['reason' => (string) ($params['reason'] ?? '')] : []);

                return $this->runner->run($admin, '/plataforma/produtos/'.$p->id.'/'.($method === 'approve' ? 'aprovar' : 'rejeitar'), $input,
                    fn (Request $r) => app()->call([app(PlatformProductsController::class), $method], ['request' => $r, 'product' => $p]));

            case 'order.refund':
                $o = Order::query()->findOrFail($id);
                $input = $codes + ['reason' => (string) ($params['reason'] ?? '')];

                return $this->runner->run($admin, '/plataforma/transacoes/pedidos/'.$o->id.'/reembolsar', $input,
                    fn (Request $r) => app()->call([app(TransactionsController::class), 'refundOrder'], ['request' => $r, 'order' => $o]));

            case 'med.resolve':
                $d = MedDispute::query()->findOrFail($id);
                $input = $codes + ['outcome' => (string) ($params['outcome'] ?? ''), 'note' => (string) ($params['note'] ?? '')];
                if ($input['note'] === '') {
                    unset($input['note']);
                }

                return $this->runner->run($admin, '/plataforma/disputas/'.$d->id.'/resolver', $input,
                    fn (Request $r) => app()->call([app(MedDisputesController::class), 'resolve'], ['request' => $r, 'dispute' => $d]));
        }

        throw new McpCatalogException('Ação desconhecida.', 'invalid_action');
    }

    /**
     * Toda ação de plataforma pelo MCP exige 2FA (ou PIN, para quem não usa 2FA), mesmo onde
     * o painel não pede: o pedido chega por um assistente, então a confirmação precisa vir
     * de algo que só o administrador tem.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function assertStepUp(User $admin, array $arguments): void
    {
        if (PlatformTotpService::isEnabledFor($admin)) {
            $code = preg_replace('/\D/', '', (string) ($arguments['totp_code'] ?? '')) ?? '';
            if ($code === '') {
                throw new McpCatalogException('Informe o código 2FA atual do administrador (totp_code).', 'step_up_required');
            }
            if (! PlatformTotpService::verifyCodeForUser($admin, $code)) {
                throw new McpCatalogException('Código 2FA inválido ou expirado.', 'step_up_invalid');
            }
            // Cada código vale uma vez no MCP.
            if (! Cache::add('mcp:platform:totp-used:'.$admin->id.':'.$code, 1, 120)) {
                throw new McpCatalogException('Este código 2FA já foi usado. Espere o próximo.', 'step_up_reused');
            }

            return;
        }

        if (WithdrawalPolicyService::hasManualApprovalPin()) {
            $pin = (string) ($arguments['manual_approval_pin'] ?? '');
            if ($pin === '' || ! WithdrawalPolicyService::verifyManualApprovalPin($pin)) {
                throw new McpCatalogException('PIN de operação ausente ou inválido.', 'step_up_invalid');
            }

            return;
        }

        throw new McpCatalogException('Para executar ações pelo MCP, ative o 2FA em Meu perfil ou cadastre o PIN de operação em Financeiro > Saques.', 'step_up_unavailable');
    }

    /**
     * @return array<string, mixed>
     */
    private function stepUpHint(User $admin): array
    {
        if (PlatformTotpService::isEnabledFor($admin)) {
            return ['exige' => 'totp_code', 'texto' => 'Peça o código de 6 dígitos do app autenticador do administrador.'];
        }
        if (WithdrawalPolicyService::hasManualApprovalPin()) {
            return ['exige' => 'manual_approval_pin', 'texto' => 'Peça o PIN de operação da plataforma.'];
        }

        return ['exige' => null, 'texto' => 'Nenhum 2FA ou PIN cadastrado: a confirmação será recusada até o administrador cadastrar um.'];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function previewWithdrawalApprove(int $id, array $arguments): array
    {
        $w = Withdrawal::query()->with('tenantOwner')->find($id);
        if ($w === null) {
            throw new McpCatalogException('Saque não encontrado.', 'not_found');
        }
        if ($w->status !== MerchantWithdrawalService::STATUS_PENDING) {
            throw new McpCatalogException('Este saque não está pendente (status: '.$w->status.').', 'invalid_state');
        }

        $mode = $this->str($arguments, 'mode') ?? 'gateway';
        $acquirer = $this->str($arguments, 'payout_acquirer');
        if ($mode === 'manual' && $acquirer === null) {
            throw new McpCatalogException('No modo manual, informe payout_acquirer (a adquirente usada no PIX ou "external").', 'invalid_params');
        }

        return [[
            'o_que_acontece' => $mode === 'manual'
                ? 'Marca o saque como PAGO, registrando que o PIX já foi enviado por fora do sistema. Nada é enviado à adquirente.'
                : 'Envia o PIX do saque pela adquirente de payout'.($acquirer ? ' "'.$acquirer.'"' : ' ativa').'. O dinheiro sai da conta da plataforma.',
            'saque' => $this->withdrawalSummary($w),
        ], ['mode' => $mode, 'payout_acquirer' => $acquirer]];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function previewWithdrawalReject(int $id, array $arguments): array
    {
        $w = Withdrawal::query()->with('tenantOwner')->find($id);
        if ($w === null) {
            throw new McpCatalogException('Saque não encontrado.', 'not_found');
        }
        if (! in_array($w->status, [MerchantWithdrawalService::STATUS_PENDING, MerchantWithdrawalService::STATUS_PROCESSING], true)) {
            throw new McpCatalogException('Este saque não pode ser recusado no status '.$w->status.'.', 'invalid_state');
        }

        return [[
            'o_que_acontece' => 'Cancela o saque e devolve R$ '.number_format((float) $w->amount, 2, ',', '.').' ao saldo disponível ('.$w->bucket.') do infoprodutor.',
            'saque' => $this->withdrawalSummary($w),
        ], ['note' => $this->str($arguments, 'note')]];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function previewKyc(string $action, int $id, array $arguments): array
    {
        $u = $this->findSeller($id);
        if ($u->kyc_status !== User::KYC_PENDING_REVIEW) {
            throw new McpCatalogException('O KYC deste infoprodutor não está em análise (status: '.$u->kyc_status.').', 'invalid_state');
        }
        $reason = $this->str($arguments, 'reason');
        if ($action === 'kyc.reject' && $reason === null) {
            throw new McpCatalogException('Informe o motivo (reason) da recusa.', 'invalid_params');
        }

        return [[
            'o_que_acontece' => $action === 'kyc.approve'
                ? 'Aprova o KYC e a conta: o infoprodutor passa a poder sacar. Ele recebe e-mail.'
                : 'Recusa o KYC com o motivo informado. O infoprodutor recebe e-mail e pode reenviar documentos.',
            'infoprodutor' => $u->only(['id', 'name', 'email', 'person_type', 'kyc_status', 'account_status']),
            'motivo' => $reason,
        ], ['reason' => $reason]];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function previewAccountApprove(int $id): array
    {
        $u = $this->findSeller($id);
        if ((string) ($u->account_status ?? '') !== 'pending') {
            throw new McpCatalogException('Esta conta não está aguardando aprovação.', 'invalid_state');
        }

        return [[
            'o_que_acontece' => 'Aprova a conta para vender. O saque continua exigindo KYC aprovado.',
            'infoprodutor' => $u->only(['id', 'name', 'email', 'kyc_status', 'account_status']),
        ], []];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function previewProduct(string $action, int $id, array $arguments): array
    {
        $p = Product::query()->find($id);
        if ($p === null) {
            throw new McpCatalogException('Produto não encontrado.', 'not_found');
        }
        $reason = $this->str($arguments, 'reason');
        if ($action === 'product.reject' && ($reason === null || mb_strlen($reason) < 20)) {
            throw new McpCatalogException('Informe o motivo (reason) com pelo menos 20 caracteres; ele é enviado ao infoprodutor.', 'invalid_params');
        }
        $owner = User::query()->find($p->tenant_id);

        return [[
            'o_que_acontece' => $action === 'product.approve'
                ? 'Aprova o produto e libera o checkout (ativa se não estiver bloqueado). O infoprodutor recebe e-mail.'
                : 'Não aprova o produto e o desativa. O motivo vai por e-mail ao infoprodutor.',
            'produto' => [
                'id' => $p->id,
                'name' => $p->name,
                'price' => (float) $p->price,
                'approval_status' => $p->approval_status,
                'is_active' => (bool) $p->is_active,
            ],
            'infoprodutor' => $owner?->only(['id', 'name', 'email']),
            'motivo' => $reason,
        ], ['reason' => $reason]];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function previewRefund(int $id, array $arguments): array
    {
        $o = Order::query()->with(['product', 'tenantOwner'])->find($id);
        if ($o === null) {
            throw new McpCatalogException('Pedido não encontrado.', 'not_found');
        }
        if (! OrderManualRefund::canManualRefund($o)) {
            throw new McpCatalogException('Este pedido não pode ser reembolsado (status: '.$o->status.').', 'invalid_state');
        }
        $reason = $this->str($arguments, 'reason');
        if ($reason === null || mb_strlen($reason) < 3) {
            throw new McpCatalogException('Informe o motivo (reason); ele é mostrado ao infoprodutor.', 'invalid_params');
        }

        return [[
            'o_que_acontece' => 'Reembolsa R$ '.number_format(MoneyDecimal::toFloat($o->amount), 2, ',', '.').' ao comprador pelo gateway '.($o->gateway ?: '?')
                .' (quando o gateway suporta; senão só no sistema), estorna o crédito do infoprodutor e revoga o acesso do comprador.',
            'pedido' => $this->orderRow($o),
            'motivo' => $reason,
        ], ['reason' => $reason]];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function previewMed(int $id, array $arguments): array
    {
        $d = MedDispute::query()->with(['order', 'tenantOwner'])->find($id);
        if ($d === null) {
            throw new McpCatalogException('Disputa não encontrada.', 'not_found');
        }
        if (! $d->isOpen()) {
            throw new McpCatalogException('Esta disputa não está aberta.', 'invalid_state');
        }
        $outcome = $this->str($arguments, 'outcome');
        if (! in_array($outcome, ['won', 'lost', 'cancelled'], true)) {
            throw new McpCatalogException('Informe outcome: won, lost ou cancelled.', 'invalid_params');
        }

        $effect = match ($outcome) {
            'lost' => 'Disputa PERDIDA: o pedido é reembolsado e o valor sai do saldo do infoprodutor quando ele é o responsável.',
            'won' => 'Disputa GANHA: libera o valor retido e o pedido volta a pago.',
            default => 'Disputa CANCELADA: libera o valor retido e o pedido volta a pago.',
        };

        return [[
            'o_que_acontece' => $effect.' O infoprodutor recebe e-mail.',
            'disputa' => [
                'id' => $d->id,
                'status' => $d->status,
                'responsavel' => $d->responsible_party,
                'valor' => round(((int) $d->amount_cents) / 100, 2),
                'tenant' => $d->tenantOwner?->only(['id', 'name', 'email']),
                'pedido_id' => $d->order_id,
            ],
        ], ['outcome' => $outcome, 'note' => $this->str($arguments, 'note')]];
    }

    // ---------------------------------------------------------------- apoio

    /**
     * @return array<string, mixed>
     */
    private function withdrawalSummary(Withdrawal $w): array
    {
        return [
            'id' => $w->id,
            'status' => $w->status,
            'valor_solicitado' => (float) $w->amount,
            'taxa' => (float) $w->fee_amount,
            'valor_liquido_pix' => (float) $w->net_amount,
            'bucket' => $w->bucket,
            'criado_em' => $w->created_at?->toIso8601String(),
            'infoprodutor' => $w->tenantOwner?->only(['id', 'name', 'email']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderRow(Order $o): array
    {
        return [
            'id' => $o->id,
            'public_reference' => $o->public_reference,
            'created_at' => $o->created_at?->toIso8601String(),
            'status' => $o->status,
            'amount' => MoneyDecimal::toFloat($o->amount),
            'payment_method' => $o->payment_method,
            'gateway' => $o->gateway,
            'produto' => $o->product ? ['id' => $o->product->id, 'name' => $o->product->name] : null,
            'vendedor' => $o->tenantOwner?->only(['id', 'name', 'email']),
            'comprador_email' => $o->email,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function findOrder(array $arguments): Order
    {
        $id = (int) ($arguments['order_id'] ?? 0);
        $ref = $this->str($arguments, 'public_reference');
        $order = $id > 0
            ? Order::query()->find($id)
            : ($ref !== null ? Order::query()->where('public_reference', $ref)->first() : null);

        if ($order === null) {
            throw new McpCatalogException('Pedido não encontrado. Informe order_id ou public_reference.', 'not_found');
        }

        return $order;
    }

    private function findSeller(int $id): User
    {
        $user = $id > 0 ? User::query()->find($id) : null;
        if ($user === null || $user->role !== User::ROLE_INFOPRODUTOR) {
            throw new McpCatalogException('Infoprodutor não encontrado.', 'not_found');
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private function range(array $arguments): array
    {
        $period = PlatformDashboardPeriod::normalize($this->str($arguments, 'period') ?? '7dias');
        [$start, $end] = PlatformDashboardPeriod::range(
            $period,
            PlatformDashboardPeriod::normalizeDate($arguments['date_from'] ?? null),
            PlatformDashboardPeriod::normalizeDate($arguments['date_to'] ?? null),
        );

        return [$period, $start, $end];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: int, 1: int}
     */
    private function paging(array $arguments): array
    {
        $limit = (int) ($arguments['limit'] ?? 20);
        $offset = (int) ($arguments['offset'] ?? 0);

        return [max(1, min(self::MAX_LIMIT, $limit)), max(0, $offset)];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function str(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Prévias do admin ficam num escopo próprio (negativo), separado dos tenants. */
    private function previewScope(User $admin): int
    {
        return -1 * (int) $admin->id;
    }

    private function auditRequest(User $admin): Request
    {
        $original = request();
        $r = Request::create('/mcp', 'POST', [], [], [], [
            'REMOTE_ADDR' => $original->ip(),
            'HTTP_USER_AGENT' => 'MCP: '.(string) $original->userAgent(),
        ]);
        $r->setUserResolver(fn () => $admin);

        return $r;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function tool(string $name, string $description, array $schema): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $schema,
        ];
    }
}

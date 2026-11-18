<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\McpCredential;
use App\Models\McpOauthAccessToken;
use App\Models\McpPlatformKey;
use App\Models\User;
use App\Services\Mcp\McpCatalogException;
use App\Services\Mcp\McpCatalogService;
use App\Services\Mcp\McpCredentialService;
use App\Services\Mcp\McpFinanceService;
use App\Services\Mcp\McpMembersService;
use App\Services\Mcp\McpOauthService;
use App\Services\Mcp\McpPlatformService;
use App\Services\Mcp\McpSalesService;
use App\Services\Mcp\McpSellerMoneyService;
use App\Services\SellerIntegrationVisibility;
use App\Services\Stacker\LicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class McpServerController extends Controller
{
    private const PROTOCOL_VERSION = '2025-03-26';

    private const SUPPORTED_PROTOCOLS = [
        '2024-11-05',
        '2025-03-26',
        '2025-06-18',
    ];

    private const MAX_BATCH = 20;

    private ?int $tenantId = null;

    /** Preenchido quando o token é de admin da plataforma (OAuth audience "platform"). */
    private ?User $platformAdmin = null;

    private bool $oauthTokenResolved = false;

    private ?McpOauthAccessToken $resolvedOauthToken = null;

    public function __construct(
        private readonly McpCredentialService $credentials,
        private readonly McpOauthService $oauth,
        private readonly McpCatalogService $catalog,
        private readonly McpSalesService $sales,
        private readonly McpMembersService $members,
        private readonly McpFinanceService $finance,
        private readonly McpPlatformService $platform,
        private readonly McpSellerMoneyService $sellerMoney,
    ) {}

    public function handle(Request $request): JsonResponse|Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->withCors(response('', HttpResponse::HTTP_NO_CONTENT));
        }

        $plain = $this->extractToken($request);
        if ($plain === null) {
            return $this->unauthorized('Token MCP ausente.');
        }

        if (! $request->isMethod('POST')) {
            return $this->withCors(response()->json([
                'error' => 'Use POST com JSON-RPC neste endpoint MCP.',
            ], HttpResponse::HTTP_METHOD_NOT_ALLOWED));
        }

        $platformKey = str_starts_with($plain, McpPlatformKey::TOKEN_PREFIX) ? McpPlatformKey::findActiveByPlain($plain) : null;
        $platformToken = $platformKey === null ? $this->resolvePlatformToken($plain) : null;

        if (str_starts_with($plain, McpPlatformKey::TOKEN_PREFIX) && $platformKey === null) {
            return $this->unauthorized('Chave de integração inválida ou revogada.');
        }

        if ($platformKey !== null || $platformToken !== null) {
            $admin = $platformKey !== null
                ? $this->platformKeyAdmin($platformKey)
                : $this->oauth->platformUserForToken($platformToken);
            if ($admin === null) {
                return $this->unauthorized('Acesso de plataforma revogado.');
            }
            // Mesma trava do painel /plataforma (middleware stacker.license).
            $license = app(LicenseService::class);
            if (! $license->isDisabled() && ! $license->isLicenseValid()) {
                return $this->withCors(response()->json(['error' => 'Painel da plataforma indisponível.'], HttpResponse::HTTP_NOT_FOUND));
            }
            $platformKey?->touchLastUsed();
            $platformToken?->touchLastUsed();
            $this->platformAdmin = $admin;
            $this->platform->useKey($platformKey);
        } else {
            $tenantId = $this->resolveTenantId($plain);
            if ($tenantId === null) {
                return $this->unauthorized('Token MCP inválido ou revogado.');
            }

            if (! SellerIntegrationVisibility::effectiveForTenant(SellerIntegrationVisibility::MCP, $tenantId)) {
                return $this->withCors(response()->json([
                    'error' => 'MCP não está habilitado para esta conta.',
                ], HttpResponse::HTTP_FORBIDDEN));
            }

            $this->tenantId = $tenantId;
        }

        $payload = $request->json()->all();
        if ($payload === []) {
            $decoded = json_decode((string) $request->getContent(), true);
            $payload = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($payload)) {
            return $this->withCors($this->rpcError(null, -32700, 'Parse error'));
        }

        if ($this->isList($payload)) {
            // O limitador conta requisições; sem teto, um lote driblaria o limite por minuto.
            if (count($payload) > self::MAX_BATCH) {
                return $this->withCors($this->rpcError(null, -32600, 'Lote JSON-RPC acima de '.self::MAX_BATCH.' mensagens.'));
            }
            $responses = [];
            foreach ($payload as $message) {
                if (! is_array($message)) {
                    continue;
                }
                $result = $this->dispatch($message);
                if ($result !== null) {
                    $responses[] = $result;
                }
            }

            if ($responses === []) {
                return $this->withCors(response('', HttpResponse::HTTP_ACCEPTED));
            }

            return $this->withCors(response()->json($responses));
        }

        $result = $this->dispatch($payload);
        if ($result === null) {
            return $this->withCors(response('', HttpResponse::HTTP_ACCEPTED));
        }

        return $this->withCors(response()->json($result));
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    private function dispatch(array $message): ?array
    {
        $method = isset($message['method']) && is_string($message['method']) ? $message['method'] : '';
        $id = $message['id'] ?? null;
        $isNotification = ! array_key_exists('id', $message);

        if ($method === '') {
            return $isNotification ? null : $this->rpcErrorBody($id, -32600, 'Invalid request');
        }

        if ($isNotification) {
            return null;
        }

        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        return match ($method) {
            'initialize' => $this->rpcResult($id, $this->initializeResult($params)),
            'ping' => $this->rpcResult($id, (object) []),
            'tools/list' => $this->rpcResult($id, ['tools' => $this->tools()]),
            'tools/call' => $this->callTool($id, is_array($message['params'] ?? null) ? $message['params'] : []),
            default => $this->rpcErrorBody($id, -32601, 'Method not found'),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function initializeResult(array $params): array
    {
        $requested = is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : self::PROTOCOL_VERSION;
        $version = in_array($requested, self::SUPPORTED_PROTOCOLS, true) ? $requested : self::PROTOCOL_VERSION;

        if ($this->platformAdmin !== null) {
            return [
                'protocolVersion' => $version,
                'capabilities' => [
                    'tools' => new \stdClass,
                ],
                'serverInfo' => [
                    'name' => 'brasapay-plataforma',
                    'version' => '1.0.0',
                ],
                'instructions' => $this->platform->instructions(),
            ];
        }

        return [
            'protocolVersion' => $version,
            'capabilities' => [
                'tools' => new \stdClass,
            ],
            'serverInfo' => [
                'name' => 'getfy-catalog',
                'version' => '0.4.0',
            ],
            'instructions' => 'Servidor MCP deste tenant. Catálogo: catalog.list_products e catalog.get_product; escritas de catálogo passam por catalog.preview_plan + catalog.apply_plan. Área de membros: members.get_structure, CRUD de seções/módulos/aulas, turmas, comentários e config (sem domínio/senha/VAPID); exclusões e revogação usam members.apply_plan. Alunos: students.list, students.grant, students.revoke, students.get_progress. Vendas: sales.summary, sales.list_orders e sales.get_order (somente leitura, sem CPF/telefone/e-mail/IP). Saldo: finance.get_balance e finance.list_withdrawals. MED: med.list_disputes. Saque e reembolso são em duas etapas: finance.preview_withdrawal / sales.preview_refund devolvem um resumo e um preview_id; mostre o resumo ao dono da conta e só chame finance.confirm_withdrawal / sales.confirm_refund depois que ele confirmar e informar o código 2FA. Nunca confirme por conta própria. Não há tools de cobrança, pixel, importação em massa, push ou checkout_config bruto.',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tools(): array
    {
        if ($this->platformAdmin !== null) {
            return $this->platform->toolDefinitions();
        }

        return array_merge(
            $this->catalog->toolDefinitions(),
            $this->sales->toolDefinitions(),
            $this->members->toolDefinitions(),
            $this->finance->toolDefinitions(),
            $this->sellerMoney->toolDefinitions(),
        );
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callTool(mixed $id, array $params): array
    {
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if ($this->platformAdmin !== null) {
            return $this->callPlatformTool($id, $name, $arguments, $this->platformAdmin);
        }

        $tenantId = (int) ($this->tenantId ?? 0);
        if ($tenantId < 1 || $name === '') {
            return $this->toolResult($id, [
                'error' => true,
                'code' => 'invalid_request',
                'message' => 'Chamada de ferramenta inválida.',
            ], isError: true);
        }

        try {
            $payload = match (true) {
                $this->sellerMoney->handles($name) => $this->sellerMoney->call($name, $arguments, $tenantId),
                str_starts_with($name, 'sales.') => $this->sales->call($name, $arguments, $tenantId),
                str_starts_with($name, 'members.') || str_starts_with($name, 'students.') => $this->members->call($name, $arguments, $tenantId),
                str_starts_with($name, 'finance.') => $this->finance->call($name, $arguments, $tenantId),
                default => $this->catalog->call($name, $arguments, $tenantId),
            };
        } catch (McpCatalogException $e) {
            return $this->toolResult($id, $e->toPayload(), isError: true);
        } catch (\Throwable $e) {
            report($e);

            return $this->toolResult($id, [
                'error' => true,
                'code' => 'internal',
                'message' => 'Falha ao executar a ferramenta MCP.',
            ], isError: true);
        }

        return $this->toolResult($id, $payload);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callPlatformTool(mixed $id, string $name, array $arguments, User $admin): array
    {
        if ($name === '') {
            return $this->toolResult($id, [
                'error' => true,
                'code' => 'invalid_request',
                'message' => 'Chamada de ferramenta inválida.',
            ], isError: true);
        }

        try {
            $payload = $this->platform->call($name, $arguments, $admin);
        } catch (McpCatalogException $e) {
            return $this->toolResult($id, $e->toPayload(), isError: true);
        } catch (\Throwable $e) {
            report($e);

            return $this->toolResult($id, [
                'error' => true,
                'code' => 'internal',
                'message' => 'Falha ao executar a ferramenta MCP.',
            ], isError: true);
        }

        return $this->toolResult($id, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function toolResult(mixed $id, array $payload, bool $isError = false): array
    {
        $result = [
            'content' => [
                [
                    'type' => 'text',
                    'text' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
        ];
        if ($isError) {
            $result['isError'] = true;
        }

        return $this->rpcResult($id, $result);
    }

    private function extractToken(Request $request): ?string
    {
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        $header = trim((string) $request->header('X-MCP-Token', ''));

        return $header !== '' ? $header : null;
    }

    private function resolveTenantId(string $plain): ?int
    {
        if (str_starts_with($plain, McpCredential::TOKEN_PREFIX)) {
            $credential = $this->credentials->findActiveByPlainToken($plain);
            if ($credential === null) {
                return null;
            }
            $credential->touchLastUsed();

            return (int) $credential->tenant_id;
        }

        $oauthToken = $this->oauthToken($plain);
        if ($oauthToken === null || $oauthToken->isPlatform()) {
            return null;
        }
        $oauthToken->touchLastUsed();

        return (int) $oauthToken->tenant_id;
    }

    /** A chave age em nome do admin que a criou, enquanto ele continuar admin da plataforma. */
    private function platformKeyAdmin(McpPlatformKey $key): ?User
    {
        $user = User::query()->find((int) $key->created_by_user_id);

        return $user instanceof User && $user->isPlatformAdmin() ? $user : null;
    }

    private function resolvePlatformToken(string $plain): ?McpOauthAccessToken
    {
        if (str_starts_with($plain, McpCredential::TOKEN_PREFIX)) {
            return null;
        }

        $token = $this->oauthToken($plain);

        return $token !== null && $token->isPlatform() ? $token : null;
    }

    /** Uma busca só por requisição: a verificação do hash é cara. */
    private function oauthToken(string $plain): ?McpOauthAccessToken
    {
        if ($this->oauthTokenResolved) {
            return $this->resolvedOauthToken;
        }
        $this->oauthTokenResolved = true;
        $this->resolvedOauthToken = $this->oauth->findActiveAccessToken($plain);

        return $this->resolvedOauthToken;
    }

    private function unauthorized(string $message): JsonResponse
    {
        return $this->withCors(
            response()->json(['error' => $message], HttpResponse::HTTP_UNAUTHORIZED)
                ->header('WWW-Authenticate', $this->oauth->wwwAuthenticate())
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function rpcResult(mixed $id, array|object $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rpcErrorBody(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    private function rpcError(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json($this->rpcErrorBody($id, $code, $message), HttpResponse::HTTP_OK);
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isList(array $value): bool
    {
        return array_is_list($value);
    }

    private function withCors(JsonResponse|Response $response): JsonResponse|Response
    {
        return $response
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Headers', 'Authorization, Content-Type, MCP-Protocol-Version, X-MCP-Token')
            ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->header('Access-Control-Expose-Headers', 'WWW-Authenticate')
            ->header('Access-Control-Max-Age', '86400');
    }
}

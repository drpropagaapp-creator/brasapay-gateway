<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LogsSellerActivity;
use App\Models\McpCredential;
use App\Services\Mcp\McpCredentialService;
use App\Services\SellerActivityLogService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class McpIntegrationController extends Controller
{
    use LogsSellerActivity;

    public function __construct(
        private readonly McpCredentialService $credentials,
    ) {}

    public function store(): JsonResponse
    {
        if ($denied = $this->denyTeamMember()) {
            return $denied;
        }
        $tenantId = $this->tenantId();
        $existing = McpCredential::query()->forTenant($tenantId)->first();
        $issued = $this->credentials->activate($tenantId);

        $this->logSellerActivity(
            $existing === null
                ? SellerActivityLogService::INTEGRATION_MCP_ACTIVATED
                : SellerActivityLogService::INTEGRATION_MCP_ROTATED,
            $issued['credential'],
            ['name' => 'MCP']
        );

        return response()->json([
            'mcp' => $this->credentials->toPublicArray($issued['credential'], $this->serverUrl()),
            'token' => $issued['token'],
            'server_url' => $this->serverUrl(),
        ], $existing === null ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function rotate(): JsonResponse
    {
        if ($denied = $this->denyTeamMember()) {
            return $denied;
        }
        $tenantId = $this->tenantId();
        $credential = McpCredential::query()->forTenant($tenantId)->first();
        if ($credential === null) {
            return response()->json(['message' => 'Ative o MCP antes de rotacionar o token.'], Response::HTTP_NOT_FOUND);
        }

        $issued = $this->credentials->rotate($credential);
        $this->logSellerActivity(SellerActivityLogService::INTEGRATION_MCP_ROTATED, $issued['credential'], [
            'name' => 'MCP',
        ]);

        return response()->json([
            'mcp' => $this->credentials->toPublicArray($issued['credential'], $this->serverUrl()),
            'token' => $issued['token'],
            'server_url' => $this->serverUrl(),
        ]);
    }

    public function destroy(): JsonResponse
    {
        if ($denied = $this->denyTeamMember()) {
            return $denied;
        }
        $tenantId = $this->tenantId();
        $credential = McpCredential::query()->forTenant($tenantId)->first();
        if ($credential === null) {
            return response()->json(['message' => 'MCP não está ativo.'], Response::HTTP_NOT_FOUND);
        }

        $this->logSellerActivity(SellerActivityLogService::INTEGRATION_MCP_REVOKED, $credential, [
            'name' => 'MCP',
        ]);
        $credential->delete();

        return response()->json([
            'mcp' => $this->credentials->toPublicArray(null, $this->serverUrl()),
        ]);
    }

    /**
     * O token MCP lê vendas e altera qualquer produto da conta, sem o recorte de
     * permissões/produtos da equipe. Por isso só o titular da conta gerencia.
     */
    private function denyTeamMember(): ?JsonResponse
    {
        if (auth()->user()?->isTeam()) {
            return response()->json(['message' => 'Somente o titular da conta pode gerenciar o MCP.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function tenantId(): int
    {
        return (int) auth()->user()->tenant_id;
    }

    private function serverUrl(): string
    {
        return url('/mcp');
    }
}

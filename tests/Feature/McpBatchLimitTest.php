<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\User;
use App\Services\SellerIntegrationVisibility;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** O limitador do MCP conta requisições; um lote JSON-RPC grande não pode driblá-lo. */
class McpBatchLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureInstalled::class, ValidateCsrfToken::class, ThrottleRequests::class]);
        Cache::forget('setting.global.'.SellerIntegrationVisibility::settingKey(SellerIntegrationVisibility::MCP));
    }

    public function test_lote_acima_de_20_mensagens_e_recusado(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id, 'kyc_status' => User::KYC_APPROVED, 'account_status' => 'approved'])->save();
        $seller = $seller->fresh();

        $admin = User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null]);
        $this->actingAs($admin)->put(route('plataforma.usuarios.update', $seller), [
            'name' => $seller->name,
            'email' => $seller->email,
            'integration_modes' => ['mcp' => SellerIntegrationVisibility::MODE_ENABLED],
        ])->assertRedirect();

        $token = $this->actingAs($seller)->postJson(route('integrations.mcp.store'))->assertCreated()->json('token');

        $ping = fn (int $i) => ['jsonrpc' => '2.0', 'id' => $i, 'method' => 'ping'];
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->postJson('/mcp', array_map($ping, range(1, 21)), $headers)
            ->assertJsonPath('error.code', -32600);

        $this->postJson('/mcp', array_map($ping, range(1, 20)), $headers)
            ->assertOk()
            ->assertJsonCount(20);
    }
}

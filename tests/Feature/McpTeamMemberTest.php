<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\McpCredential;
use App\Models\TeamRole;
use App\Models\User;
use App\Services\SellerIntegrationVisibility;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** O token MCP ignora o recorte da equipe; só o titular da conta pode gerenciá-lo. */
class McpTeamMemberTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureInstalled::class, ValidateCsrfToken::class, ThrottleRequests::class]);
        Cache::forget('setting.global.'.SellerIntegrationVisibility::settingKey(SellerIntegrationVisibility::MCP));
    }

    public function test_membro_da_equipe_nao_gera_rotaciona_nem_revoga_token_mcp(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $owner->forceFill(['tenant_id' => $owner->id, 'kyc_status' => User::KYC_APPROVED, 'account_status' => 'approved'])->save();
        $owner = $owner->fresh();

        $admin = User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null]);
        $this->actingAs($admin)->put(route('plataforma.usuarios.update', $owner), [
            'name' => $owner->name,
            'email' => $owner->email,
            'integration_modes' => ['mcp' => SellerIntegrationVisibility::MODE_ENABLED],
        ])->assertRedirect();

        $role = TeamRole::create([
            'tenant_id' => $owner->tenant_id,
            'name' => 'Integrações',
            'permissions' => ['dashboard.view' => true, 'integracoes.view' => true],
        ]);
        $member = User::factory()->create([
            'role' => User::ROLE_TEAM,
            'tenant_id' => $owner->tenant_id,
            'team_role_id' => $role->id,
        ]);

        $this->actingAs($member)->postJson(route('integrations.mcp.store'))->assertForbidden();
        $this->assertSame(0, McpCredential::query()->forTenant($owner->tenant_id)->count());

        $this->actingAs($owner)->postJson(route('integrations.mcp.store'))->assertCreated();
        $hash = McpCredential::query()->forTenant($owner->tenant_id)->value('token_hash');

        $this->actingAs($member)->postJson(route('integrations.mcp.rotate'))->assertForbidden();
        $this->actingAs($member)->deleteJson(route('integrations.mcp.destroy'))->assertForbidden();
        $this->assertSame($hash, McpCredential::query()->forTenant($owner->tenant_id)->value('token_hash'));
    }
}

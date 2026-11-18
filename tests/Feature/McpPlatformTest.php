<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\McpOauthAccessToken;
use App\Models\PlatformAuditLog;
use App\Models\Setting;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\SellerIntegrationVisibility;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class McpPlatformTest extends TestCase
{
    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            ValidateCsrfToken::class,
            ThrottleRequests::class,
        ]);
        config(['getfy.stacker.license_disabled' => true]);
        Cache::forget('setting.global.'.SellerIntegrationVisibility::settingKey(SellerIntegrationVisibility::MCP));
    }

    public function test_platform_admin_gets_platform_tools_only(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->authorize($admin);

        $row = McpOauthAccessToken::query()->latest('id')->first();
        $this->assertSame(McpOauthAccessToken::AUDIENCE_PLATFORM, $row->audience);
        $this->assertSame($admin->id, (int) $row->user_id);

        $this->rpc($token, 'initialize', ['protocolVersion' => '2025-06-18'])
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'brasapay-plataforma');

        $names = collect($this->rpc($token, 'tools/list')->json('result.tools'))->pluck('name')->all();
        $this->assertContains('platform.overview', $names);
        $this->assertContains('platform.confirm_action', $names);
        $this->assertNotContains('catalog.ping', $names);
        $this->assertNotContains('finance.preview_withdrawal', $names);

        $this->assertSame(1, PlatformAuditLog::query()->where('action', 'platform.mcp.authorized')->count());
    }

    public function test_platform_token_dies_when_admin_role_is_removed(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->authorize($admin);
        $this->rpc($token, 'initialize')->assertOk();

        $admin->forceFill(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => $admin->id])->save();

        $this->rpc($token, 'initialize')->assertStatus(401);
    }

    public function test_action_needs_step_up_and_runs_once_with_pin(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->authorize($admin);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id, 'account_status' => 'pending'])->save();

        $preview = $this->tool($token, 'platform.preview_action', ['action' => 'account.approve', 'id' => $seller->id]);
        $this->assertArrayHasKey('preview_id', $preview);
        $this->assertSame('pending', $seller->fresh()->account_status);

        // Sem 2FA nem PIN cadastrados: recusa.
        $refused = $this->tool($token, 'platform.confirm_action', ['preview_id' => $preview['preview_id']], expectError: true);
        $this->assertSame('step_up_unavailable', $refused['code']);
        $this->assertSame('pending', $seller->fresh()->account_status);

        Setting::set('platform_manual_approval_pin_hash', Hash::make('4321'), null);

        $wrong = $this->tool($token, 'platform.confirm_action', ['preview_id' => $preview['preview_id'], 'manual_approval_pin' => '0000'], expectError: true);
        $this->assertSame('step_up_invalid', $wrong['code']);
        $this->assertSame('pending', $seller->fresh()->account_status);

        $done = $this->tool($token, 'platform.confirm_action', ['preview_id' => $preview['preview_id'], 'manual_approval_pin' => '4321']);
        $this->assertTrue($done['ok']);
        $this->assertSame('approved', $seller->fresh()->account_status);
        $this->assertSame(1, PlatformAuditLog::query()->where('action', 'platform.merchant.basic_approved')->where('user_id', $admin->id)->count());
        $this->assertSame(1, PlatformAuditLog::query()->where('action', 'platform.mcp.action')->where('user_id', $admin->id)->count());

        $again = $this->tool($token, 'platform.confirm_action', ['preview_id' => $preview['preview_id'], 'manual_approval_pin' => '4321']);
        $this->assertTrue($again['ja_executada']);
        $this->assertSame(1, PlatformAuditLog::query()->where('action', 'platform.mcp.action')->count());
    }

    public function test_withdrawal_reject_returns_balance_through_panel_code(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->authorize($admin);
        Setting::set('platform_manual_approval_pin_hash', Hash::make('4321'), null);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id, 'account_status' => 'approved'])->save();
        TenantWallet::query()->create([
            'tenant_id' => $seller->id,
            'available_balance' => 0,
            'pending_balance' => 0,
            'available_pix' => 0,
            'available_card' => 0,
            'available_boleto' => 0,
            'pending_pix' => 0,
            'pending_card' => 0,
            'pending_boleto' => 0,
            'currency' => 'BRL',
        ]);
        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 100,
            'fee_amount' => 2,
            'net_amount' => 98,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
        ]);

        $preview = $this->tool($token, 'platform.preview_action', ['action' => 'withdrawal.reject', 'id' => $withdrawal->id, 'note' => 'teste']);
        $done = $this->tool($token, 'platform.confirm_action', ['preview_id' => $preview['preview_id'], 'manual_approval_pin' => '4321']);

        $this->assertTrue($done['ok'], $done['mensagem']);
        $this->assertSame('rejected', $withdrawal->fresh()->status);
        $this->assertEquals(100.0, (float) TenantWallet::query()->where('tenant_id', $seller->id)->value('available_pix'));
        $this->assertSame(1, PlatformAuditLog::query()->where('action', 'platform.withdrawal.rejected')->where('user_id', $admin->id)->count());
    }

    public function test_seller_money_tools_need_owner_2fa(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id, 'kyc_status' => User::KYC_APPROVED, 'account_status' => 'approved'])->save();
        SellerIntegrationVisibility::setTenantMode(SellerIntegrationVisibility::MCP, (int) $seller->id, SellerIntegrationVisibility::MODE_ENABLED);
        $token = $this->actingAs($seller->fresh())->postJson(route('integrations.mcp.store'))->assertCreated()->json('token');
        Auth::logout();
        $this->app['auth']->forgetGuards();

        TenantWallet::query()->create([
            'tenant_id' => $seller->id,
            'available_balance' => 50,
            'pending_balance' => 0,
            'available_pix' => 50,
            'available_card' => 0,
            'available_boleto' => 0,
            'pending_pix' => 0,
            'pending_card' => 0,
            'pending_boleto' => 0,
            'currency' => 'BRL',
        ]);

        $names = collect($this->rpc($token, 'tools/list')->json('result.tools'))->pluck('name')->all();
        $this->assertContains('finance.preview_withdrawal', $names);
        $this->assertNotContains('platform.overview', $names);

        $tooMuch = $this->tool($token, 'finance.preview_withdrawal', ['amount' => 80], expectError: true);
        $this->assertSame('insufficient_balance', $tooMuch['code']);

        $preview = $this->tool($token, 'finance.preview_withdrawal', ['amount' => 30]);
        $refused = $this->tool($token, 'finance.confirm_withdrawal', ['preview_id' => $preview['preview_id'], 'totp_code' => '123456'], expectError: true);
        $this->assertSame('step_up_unavailable', $refused['code']);
        $this->assertSame(0, Withdrawal::query()->where('tenant_id', $seller->id)->count());

        $this->assertSame([], $this->tool($token, 'med.list_disputes', [])['disputes']);
    }

    public function test_preview_rejects_invalid_state(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->authorize($admin);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id, 'account_status' => 'approved'])->save();

        $err = $this->tool($token, 'platform.preview_action', ['action' => 'account.approve', 'id' => $seller->id], expectError: true);
        $this->assertSame('invalid_state', $err['code']);
    }

    public function test_platform_read_tools_answer(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->authorize($admin);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id, 'account_status' => 'pending'])->save();

        $pending = $this->tool($token, 'platform.list_pending_approvals', []);
        $this->assertSame([$seller->id], collect($pending['contas_aguardando_aprovacao'])->pluck('id')->all());

        $sellers = $this->tool($token, 'platform.list_sellers', ['status' => 'pending']);
        $this->assertSame(1, $sellers['total']);

        $detail = $this->tool($token, 'platform.get_seller', ['user_id' => $seller->id]);
        $this->assertSame($seller->email, $detail['email']);

        $this->assertArrayHasKey('total', $this->tool($token, 'platform.list_withdrawals', []));
        $this->assertArrayHasKey('orders', $this->tool($token, 'platform.list_orders', ['period' => 'total']));
        $this->assertArrayHasKey('disputes', $this->tool($token, 'platform.list_med_disputes', []));
    }

    public function test_guest_sees_both_login_options(): void
    {
        $clientId = $this->registerClient();
        [, $challenge] = $this->pkcePair();

        $this->get('/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]))->assertOk()->assertSee('/login')->assertSee('/plataforma/login');
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN]);
        $admin->forceFill(['tenant_id' => null])->save();

        return $admin->fresh();
    }

    private function authorize(User $admin): string
    {
        [$verifier, $challenge] = $this->pkcePair();
        $clientId = $this->registerClient();

        $this->actingAs($admin)->get('/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => 'st',
        ]))->assertOk()->assertSee('administrador da plataforma');

        $approve = $this->actingAs($admin)->post('/oauth/authorize', ['decision' => 'approve']);
        $approve->assertRedirect();
        parse_str((string) parse_url((string) $approve->headers->get('Location'), PHP_URL_QUERY), $query);

        Auth::logout();
        $this->app['auth']->forgetGuards();

        return $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $query['code'],
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $verifier,
        ])->assertOk()->json('access_token');
    }

    private function registerClient(): string
    {
        return $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => [self::REDIRECT],
            'token_endpoint_auth_method' => 'none',
        ])->assertCreated()->json('client_id');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function pkcePair(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [$verifier, $challenge];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function rpc(string $token, string $method, array $params = [])
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ], ['Authorization' => 'Bearer '.$token]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function tool(string $token, string $name, array $arguments, bool $expectError = false): array
    {
        $res = $this->rpc($token, 'tools/call', ['name' => $name, 'arguments' => (object) $arguments])->assertOk();
        $this->assertSame($expectError, (bool) $res->json('result.isError'), (string) $res->json('result.content.0.text'));

        return json_decode((string) $res->json('result.content.0.text'), true);
    }
}

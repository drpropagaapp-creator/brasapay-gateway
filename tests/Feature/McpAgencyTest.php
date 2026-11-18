<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\McpPlatformKey;
use App\Models\MemberLesson;
use App\Models\PlatformAuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use ZipArchive;

class McpAgencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            ValidateCsrfToken::class,
            ThrottleRequests::class,
        ]);
        config(['getfy.stacker.license_disabled' => true]);
    }

    public function test_agency_key_lists_safe_tool_names_without_money_actions(): void
    {
        [, $key] = $this->agencyKey();

        $this->rpc($key, 'initialize', ['protocolVersion' => '2025-06-18'])
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'brasapay-plataforma');

        $names = collect($this->rpc($key, 'tools/list')->json('result.tools'))->pluck('name')->all();
        $this->assertContains('platform_create_seller', $names);
        $this->assertContains('platform_import_product_package', $names);
        $this->assertContains('seller_catalog_create_product', $names);
        $this->assertContains('seller_members_create_lesson', $names);
        $this->assertNotContains('platform_confirm_action', $names);
        $this->assertNotContains('platform_preview_action', $names);
        $this->assertNotContains('platform_list_withdrawals', $names);
        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,64}$/', $name);
        }

        $blocked = $this->tool($key, 'platform_confirm_action', ['preview_id' => 'x'], expectError: true);
        $this->assertSame('forbidden', $blocked['code']);
    }

    public function test_revoked_key_and_demoted_admin_stop_working(): void
    {
        [$admin, $key, $model] = $this->agencyKey();
        $this->rpc($key, 'initialize')->assertOk();

        $model->forceFill(['revoked_at' => now()])->save();
        $this->rpc($key, 'initialize')->assertStatus(401);

        [$admin2, $key2] = $this->agencyKey();
        $admin2->forceFill(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => $admin2->id])->save();
        $this->rpc($key2, 'initialize')->assertStatus(401);
    }

    public function test_create_seller_is_approved_and_idempotent(): void
    {
        Notification::fake();
        Mail::fake();
        [$admin, $key] = $this->agencyKey();

        $created = $this->tool($key, 'platform_create_seller', ['name' => 'Cliente Novo', 'email' => 'Cliente@Exemplo.com']);
        $this->assertFalse($created['ja_existia']);
        $this->assertTrue($created['email_de_senha_enviado']);
        $this->assertSame('approved', $created['account_status']);

        $user = User::query()->findOrFail($created['user_id']);
        $this->assertSame(User::ROLE_INFOPRODUTOR, $user->role);
        $this->assertSame($user->id, (int) $user->tenant_id);
        $this->assertSame(1, PlatformAuditLog::query()->where('action', 'platform.merchant.created')->where('user_id', $admin->id)->count());

        $again = $this->tool($key, 'platform_create_seller', ['name' => 'Cliente Novo', 'email' => 'cliente@exemplo.com']);
        $this->assertTrue($again['ja_existia']);
        $this->assertSame($created['user_id'], $again['user_id']);
    }

    public function test_seller_catalog_tool_runs_in_client_account_and_product_is_approved(): void
    {
        [, $key] = $this->agencyKey();
        $seller = $this->seller();

        $args = [
            'tenant_id' => $seller->id,
            'name' => 'Ebook Teste',
            'price' => 47,
            'type' => 'area_membros',
        ];
        $res = $this->rpc($key, 'tools/call', ['name' => 'seller_catalog_create_product', 'arguments' => $args])->json('result');
        if (($res['isError'] ?? false) === true) {
            // Aprovação automática ligada: o catálogo pede confirmação, que a agência aplica.
            $pending = json_decode((string) $res['content'][0]['text'], true);
            $this->assertSame('needs_confirmation', $pending['code']);
            $this->tool($key, 'seller_catalog_apply_plan', ['tenant_id' => $seller->id, 'preview_id' => $pending['preview_id']]);
        }

        $product = Product::query()->where('tenant_id', $seller->id)->where('name', 'Ebook Teste')->firstOrFail();
        $this->assertSame($seller->id, (int) $product->tenant_id);
        $this->assertSame(Product::APPROVAL_APPROVED, $product->approval_status);
    }

    public function test_import_package_creates_product_lessons_and_bump(): void
    {
        [, $key] = $this->agencyKey();
        $seller = $this->seller();

        $zipPath = tempnam(sys_get_temp_dir(), 'pkg').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('pacote/modulo1/01-aula.pdf', "%PDF-1.4\n%teste\n");
        $zip->addFromString('pacote/modulo1/02-video.mp4', str_repeat('0', 64));
        $zip->addFromString('pacote/capa.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $zip->addFromString('pacote/../fora.pdf', 'x');
        $zip->close();
        Http::fake(['*' => Http::response((string) file_get_contents($zipPath), 200)]);

        $manifest = [
            'produtos' => [
                [
                    'nome' => 'Curso Pacote',
                    'preco' => 197,
                    'capa' => 'capa.png',
                    'ofertas' => [['nome' => 'Promo', 'preco' => 97]],
                    'order_bumps' => [['produto' => 'Bônus Pacote', 'titulo' => 'Leve o bônus']],
                    'area_membros' => ['modulos' => [['titulo' => 'Módulo 1', 'pasta' => 'modulo1']]],
                ],
                ['nome' => 'Bônus Pacote', 'preco' => 19.9],
            ],
        ];

        $queued = $this->tool($key, 'platform_import_product_package', [
            'tenant_id' => $seller->id,
            'package_url' => 'https://93.184.216.34/pacote.zip',
            'manifest' => $manifest,
        ]);

        $status = $this->tool($key, 'platform_import_status', ['import_id' => $queued['import_id']]);
        $this->assertSame('done', $status['status'], json_encode($status));
        $this->assertCount(2, $status['resultado']['produtos']);
        $this->assertSame(2, $status['resultado']['produtos'][0]['aulas']);

        $product = Product::query()->where('tenant_id', $seller->id)->where('name', 'Curso Pacote')->firstOrFail();
        $this->assertSame(Product::APPROVAL_APPROVED, $product->approval_status);
        $this->assertNotEmpty($product->image);
        $this->assertSame(['pdf', 'video'], MemberLesson::query()->where('product_id', $product->id)->orderBy('position')->pluck('type')->all());
        $this->assertSame([], $status['resultado']['avisos']);
    }

    public function test_import_refuses_internal_urls(): void
    {
        [, $key] = $this->agencyKey();
        $seller = $this->seller();

        $err = $this->tool($key, 'platform_import_product_package', [
            'tenant_id' => $seller->id,
            'package_url' => 'https://127.0.0.1/pacote.zip',
        ], expectError: true);
        $this->assertSame('invalid_params', $err['code']);

        $err = $this->tool($key, 'platform_import_product_package', [
            'tenant_id' => $seller->id,
            'package_url' => 'http://93.184.216.34/pacote.zip',
        ], expectError: true);
        $this->assertSame('invalid_params', $err['code']);
    }

    /**
     * @return array{0: User, 1: string, 2: McpPlatformKey}
     */
    private function agencyKey(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN]);
        $admin->forceFill(['tenant_id' => null])->save();
        $issued = McpPlatformKey::issue('MetaTrack', $admin->fresh());

        return [$admin->fresh(), $issued['plain'], $issued['model']];
    }

    private function seller(): User
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id, 'account_status' => 'approved'])->save();

        return $seller->fresh();
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

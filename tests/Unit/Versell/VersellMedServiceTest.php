<?php

namespace Tests\Unit\Versell;

use App\Models\GatewayCredential;
use App\Models\MedDispute;
use App\Models\Order;
use App\Models\User;
use App\Services\Med\MedPolicyService;
use App\Services\MedEmailNotifications;
use App\Services\Versell\VersellHttpClient;
use App\Services\Versell\VersellMedService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class VersellMedServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_infraction_from_docs_shape(): void
    {
        $svc = $this->makeService();
        $n = $svc->normalizeInfraction([
            'id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
            'type' => 'MED',
            'status' => 'WAITING_ADJUSTMENTS',
            'endToEndId' => 'E1234567820260101120000000000001',
            'reportDetails' => 'Suspected fraud transaction',
            'transactionAmount' => ['currency' => 'BRL', 'amount' => 500.00],
            'deadline' => '2026-01-22T23:59:59Z',
        ]);

        $this->assertSame('a1b2c3d4-e5f6-7890-abcd-ef1234567890', $n['id']);
        $this->assertSame('WAITING_ADJUSTMENTS', $n['status']);
        $this->assertSame('E1234567820260101120000000000001', $n['end_to_end_id']);
        $this->assertSame(50000, $n['amount_cents']);
        $this->assertSame('Suspected fraud transaction', $n['reason']);
    }

    public function test_sync_opened_marks_disputed_and_creates_med_dispute(): void
    {
        if (! Schema::hasTable('med_disputes') || ! Schema::hasTable('orders')) {
            $this->markTestSkipped('tables');
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $order = Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'gateway' => 'versell',
            'gateway_id' => 'txid-versell-1',
            'amount' => 100,
            'status' => 'completed',
            'payment_method' => 'pix',
            'currency' => 'BRL',
            'metadata' => ['versell_end_to_end_id' => 'E1234567820260101120000000000001'],
        ]);

        $notifications = Mockery::mock(MedEmailNotifications::class);
        $notifications->shouldReceive('medOpened')->once();

        $svc = new VersellMedService(
            app(MedPolicyService::class),
            $notifications,
        );

        $dispute = $svc->syncFromInfractionPayload([
            'id' => 'infraction-uuid-1',
            'type' => 'MED',
            'status' => 'WAITING_ADJUSTMENTS',
            'endToEndId' => 'E1234567820260101120000000000001',
            'reportDetails' => 'Suspected fraud',
            'transactionAmount' => ['amount' => 100],
        ]);

        $this->assertNotNull($dispute);
        $this->assertTrue(VersellMedService::isVersellDispute($dispute));
        $this->assertSame(MedDispute::STATUS_OPEN, $dispute->status);
        $this->assertSame('versell:infraction-uuid-1', $dispute->cajupay_dispute_id);
        $this->assertSame((int) $order->id, (int) $dispute->order_id);
        // Hold/disputed só quando a policy atribui responsabilidade ao tenant (mesma regra CajuPay).
    }

    public function test_reconcile_recent_logs_http_rejection_instead_of_failing_silently(): void
    {
        if (! Schema::hasTable('gateway_credentials')) {
            $this->markTestSkipped('tables');
        }

        $tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'versell_med_'.uniqid('', true);
        mkdir($tmpDir, 0700, true);
        $cert = $tmpDir.DIRECTORY_SEPARATOR.'cash_out.crt';
        $key = $tmpDir.DIRECTORY_SEPARATOR.'cash_out.key';
        file_put_contents($cert, "-----BEGIN CERTIFICATE-----\nOUT\n-----END CERTIFICATE-----\n");
        file_put_contents($key, "-----BEGIN PRIVATE KEY-----\nOUT\n-----END PRIVATE KEY-----\n");

        try {
            if (! config('app.key')) {
                config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
            }

            $cred = GatewayCredential::query()->firstOrNew([
                'tenant_id' => null,
                'gateway_slug' => 'versell',
            ]);
            $cred->is_connected = true;
            $cred->is_enabled = true;
            $cred->setEncryptedCredentials([
                'cash_out' => [
                    'client_id' => 'co_client',
                    'client_secret' => 'co_secret',
                    'certificate_path' => $cert,
                    'private_key_path' => $key,
                ],
            ]);
            $cred->save();

            $http = Mockery::mock(VersellHttpClient::class);
            $http->shouldReceive('request')->once()->andReturn(new Response(new PsrResponse(
                503,
                ['Content-Type' => 'application/problem+json'],
                json_encode([
                    'title' => 'Service Unavailable',
                    'detail' => 'upstream timeout',
                    'status' => 503,
                ])
            )));

            $logged = [];
            Log::listen(function (MessageLogged $event) use (&$logged) {
                $logged[] = $event;
            });

            $svc = new VersellMedService(
                app(MedPolicyService::class),
                Mockery::mock(MedEmailNotifications::class)->shouldIgnoreMissing(),
                $http,
            );

            $result = $svc->reconcileRecent(72);

            $this->assertSame(1, $result['errors']);
            $this->assertSame(0, $result['synced']);
            $this->assertTrue(collect($logged)->contains(function (MessageLogged $event) {
                return $event->level === 'warning'
                    && $event->message === 'VersellMed: listagem de infrações recusada'
                    && ($event->context['status'] ?? null) === 503
                    && str_contains((string) ($event->context['error'] ?? ''), 'upstream timeout');
            }));
        } finally {
            @unlink($cert);
            @unlink($key);
            if (is_dir($tmpDir)) {
                @rmdir($tmpDir);
            }
        }
    }

    private function makeService(): VersellMedService
    {
        $notifications = Mockery::mock(MedEmailNotifications::class);
        $notifications->shouldIgnoreMissing();

        return new VersellMedService(
            app(MedPolicyService::class),
            $notifications,
        );
    }
}

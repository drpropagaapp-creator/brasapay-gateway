<?php

namespace App\Services\Api;

use App\Models\ApiApplication;
use App\Models\ApiKey;
use App\Models\SandboxTransaction;
use Illuminate\Validation\ValidationException;

/**
 * Sandbox da API (Brasa Developers). Simula o ciclo de pagamentos e saques com os mesmos formatos de
 * resposta e de webhook dos endpoints reais, mas grava só em sandbox_transactions: não cria pedido,
 * não chama adquirente, não mexe em carteira nem em saque real. Webhooks saem com "livemode": false.
 */
class SandboxService
{
    /** Token de cartão que simula recusa; qualquer outro token é aprovado. */
    public const CARD_DECLINED_TOKEN = 'tok_sandbox_declined';

    /** @var array<string, array{from: list<string>, to: string, event: string|null}> */
    private const PAYMENT_ACTIONS = [
        'approve' => ['from' => ['pending'], 'to' => 'completed', 'event' => 'order.completed'],
        'expire' => ['from' => ['pending'], 'to' => 'cancelled', 'event' => 'order.cancelled'],
        'refund' => ['from' => ['completed'], 'to' => 'refunded', 'event' => 'order.refunded'],
    ];

    /** @var array<string, array{to: string, event: string}> */
    private const WITHDRAWAL_ACTIONS = [
        'process' => ['to' => 'processing', 'event' => 'withdrawal.processing'],
        'pay' => ['to' => 'completed', 'event' => 'withdrawal.completed'],
        'fail' => ['to' => 'failed', 'event' => 'withdrawal.failed'],
    ];

    public function __construct(private ApiWebhookDeliveryService $webhooks) {}

    public function createPayment(ApiApplication $app, ?ApiKey $key, string $method, array $data): SandboxTransaction
    {
        $declined = $method === 'card' && ($data['card']['payment_token'] ?? null) === self::CARD_DECLINED_TOKEN;
        $tx = SandboxTransaction::query()->create([
            'tenant_id' => (int) $app->tenant_id,
            'api_application_id' => $app->id,
            'api_key_id' => $key?->id,
            'kind' => SandboxTransaction::KIND_PAYMENT,
            'method' => $method,
            'status' => $method === 'card' ? ($declined ? 'refused' : 'completed') : 'pending',
            'amount_cents' => $this->cents($data['amount']),
            'currency' => strtoupper((string) ($data['currency'] ?? 'BRL')),
            'customer_email' => $data['customer']['email'] ?? null,
            'metadata' => $data['metadata'] ?? [],
            'failed_reason' => $declined ? 'card_declined' : null,
        ]);

        if (! $declined) {
            $this->emit($app, $key, 'order.pending', $tx);
            if ($method === 'pix') {
                $this->emit($app, $key, 'pix.generated', $tx, $this->pixData($tx));
            }
            if ($tx->status === 'completed') {
                $this->emit($app, $key, 'order.completed', $tx);
            }
        }

        return $tx;
    }

    public function simulatePayment(SandboxTransaction $tx, string $action, ApiApplication $app, ?ApiKey $key): SandboxTransaction
    {
        $rule = self::PAYMENT_ACTIONS[$action] ?? null;
        if ($rule === null || $tx->kind !== SandboxTransaction::KIND_PAYMENT) {
            throw ValidationException::withMessages(['action' => 'Ação inválida.']);
        }
        if (! in_array($tx->status, $rule['from'], true)) {
            throw ValidationException::withMessages(['action' => "Não é possível '{$action}' um pagamento com status '{$tx->status}'."]);
        }
        $tx->update(['status' => $rule['to']]);
        $this->emit($app, $key, $rule['event'], $tx);

        return $tx;
    }

    public function createWithdrawal(ApiApplication $app, ?ApiKey $key, array $data): SandboxTransaction
    {
        $amount = $this->cents($data['amount']);
        if ($amount > $this->availableCents($app)) {
            throw ValidationException::withMessages(['amount' => 'Saldo de sandbox insuficiente. Aprove pagamentos de teste para gerar saldo.']);
        }
        $tx = SandboxTransaction::query()->create([
            'tenant_id' => (int) $app->tenant_id,
            'api_application_id' => $app->id,
            'api_key_id' => $key?->id,
            'kind' => SandboxTransaction::KIND_WITHDRAWAL,
            'method' => 'pix',
            'status' => 'pending',
            'amount_cents' => $amount,
            'metadata' => ['notes' => $data['notes'] ?? null, 'pix_key_type' => $data['pix_key_type'] ?? null],
        ]);
        $this->emit($app, $key, 'withdrawal.created', $tx);

        return $tx;
    }

    public function simulateWithdrawal(SandboxTransaction $tx, string $action, ApiApplication $app, ?ApiKey $key, ?string $reason = null): SandboxTransaction
    {
        $rule = self::WITHDRAWAL_ACTIONS[$action] ?? null;
        if ($rule === null || $tx->kind !== SandboxTransaction::KIND_WITHDRAWAL) {
            throw ValidationException::withMessages(['action' => 'Ação inválida.']);
        }
        if (in_array($tx->status, ['completed', 'failed'], true)) {
            throw ValidationException::withMessages(['action' => "O saque já está '{$tx->status}'."]);
        }
        $tx->update(['status' => $rule['to'], 'failed_reason' => $action === 'fail' ? ($reason ?: 'sandbox_simulated_failure') : null]);
        $this->emit($app, $key, $rule['event'], $tx);

        return $tx;
    }

    /** Saldo de sandbox = pagamentos aprovados − saques não falhos. Nada aqui lê a carteira real. */
    public function availableCents(ApiApplication $app): int
    {
        $q = SandboxTransaction::query()->where('api_application_id', $app->id);
        $in = (int) (clone $q)->where('kind', SandboxTransaction::KIND_PAYMENT)->where('status', 'completed')->sum('amount_cents');
        $out = (int) (clone $q)->where('kind', SandboxTransaction::KIND_WITHDRAWAL)->where('status', '!=', 'failed')->sum('amount_cents');

        return max(0, $in - $out);
    }

    /** Envia um evento de exemplo para os webhooks da aplicação (simulador de entrega). */
    public function sendTestEvent(ApiApplication $app, string $event): int
    {
        $tx = new SandboxTransaction([
            'kind' => str_starts_with($event, 'withdrawal.') ? SandboxTransaction::KIND_WITHDRAWAL : SandboxTransaction::KIND_PAYMENT,
            'method' => 'pix', 'status' => $this->sampleStatus($event), 'amount_cents' => 9700, 'currency' => 'BRL',
            'customer_email' => 'comprador.teste@example.com', 'metadata' => ['sandbox_test' => true],
        ]);
        $tx->id = 'test_'.bin2hex(random_bytes(8));
        $tx->created_at = now();
        $tx->updated_at = now();
        $before = \App\Models\ApiWebhookDelivery::query()->where('api_application_id', $app->id)->count();
        $this->emit($app, null, $event, $tx, $event === 'pix.generated' ? $this->pixData($tx) : []);

        return \App\Models\ApiWebhookDelivery::query()->where('api_application_id', $app->id)->count() - $before;
    }

    /** @return array<string, mixed> */
    public function paymentResponse(SandboxTransaction $tx): array
    {
        $base = ['order_id' => $tx->id, 'transaction_id' => 'sbx_'.$tx->id, 'status' => $tx->status, 'livemode' => false];

        return match ($tx->method) {
            'pix' => $base + $this->pixData($tx),
            'boleto' => $base + ['amount' => $tx->amountDecimal(), 'barcode' => $this->fakeBarcode($tx), 'boleto_url' => null],
            default => $base,
        };
    }

    /** @return array<string, mixed> */
    public function statusResponse(SandboxTransaction $tx): array
    {
        return [
            'order_id' => $tx->id, 'status' => $tx->status, 'amount' => $tx->amountDecimal(), 'email' => $tx->customer_email,
            'payment_method' => $tx->method, 'metadata' => $tx->metadata ?? [], 'livemode' => false,
            'created_at' => $tx->created_at?->toIso8601String(), 'updated_at' => $tx->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function withdrawalResponse(SandboxTransaction $tx): array
    {
        return [
            'withdrawal_id' => $tx->id, 'status' => $tx->status, 'amount' => $tx->amountDecimal(), 'fee_amount' => 0.0,
            'net_amount' => $tx->amountDecimal(), 'bucket' => 'pix', 'failed_reason' => $tx->failed_reason,
            'notes' => $tx->metadata['notes'] ?? null, 'payout_provider' => 'sandbox', 'payout_external_id' => null,
            'livemode' => false, 'created_at' => $tx->created_at?->toIso8601String(), 'updated_at' => $tx->updated_at?->toIso8601String(),
        ];
    }

    private function emit(ApiApplication $app, ?ApiKey $key, string $event, SandboxTransaction $tx, array $extra = []): void
    {
        $payload = $tx->kind === SandboxTransaction::KIND_WITHDRAWAL
            ? ['event' => $event] + $this->withdrawalResponse($tx)
            : ['event' => $event, 'order_id' => $tx->id, 'amount' => $tx->amountDecimal(), 'status' => $tx->status,
                'email' => $tx->customer_email, 'metadata' => $tx->metadata ?? [],
                'created_at' => $tx->created_at?->toIso8601String(), 'updated_at' => $tx->updated_at?->toIso8601String()]
                + ($event === 'order.completed' ? ['transaction_id' => 'sbx_'.$tx->id, 'payment_method' => $tx->method, 'paid_at' => now()->toIso8601String()] : []);
        unset($payload['livemode']);

        $this->webhooks->dispatch($app, $event, $payload + $extra, $key?->id, null, false);
    }

    /** @return array{qrcode: null, copy_paste: string} */
    private function pixData(SandboxTransaction $tx): array
    {
        return ['qrcode' => null, 'copy_paste' => 'SANDBOX-PIX-'.strtoupper(substr(str_replace('-', '', (string) $tx->id), 0, 24))];
    }

    private function fakeBarcode(SandboxTransaction $tx): string
    {
        return '00000.00000 00000.000000 00000.000000 0 '.str_pad((string) $tx->amount_cents, 14, '0', STR_PAD_LEFT);
    }

    private function sampleStatus(string $event): string
    {
        return match ($event) {
            'order.completed', 'withdrawal.completed' => 'completed',
            'order.refunded' => 'refunded',
            'order.cancelled' => 'cancelled',
            'withdrawal.failed' => 'failed',
            'withdrawal.processing' => 'processing',
            default => 'pending',
        };
    }

    private function cents(mixed $amount): int
    {
        return (int) bcmul(number_format((float) $amount, 2, '.', ''), '100', 0);
    }
}

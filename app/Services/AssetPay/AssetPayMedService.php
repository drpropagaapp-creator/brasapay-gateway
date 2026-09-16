<?php

namespace App\Services\AssetPay;

use App\Models\MedDispute;
use App\Models\Order;
use App\Services\Med\MedPolicyService;
use App\Services\Med\MedResolutionService;
use App\Services\MedEmailNotifications;
use App\Services\PlatformOrderAdminService;
use Illuminate\Support\Facades\Log;

/**
 * Infrações PIX (MED) da AssetPay → MedDispute + pedido/carteira.
 *
 * Fluxo de webhooks (doc AssetPay):
 *  1. transaction.infraction + transaction.blocked — infração aberta, saldo bloqueado
 *  2. transaction.infraction.closed — infração encerrada favoravelmente ou cancelada
 *  3. transaction.refunded — MED perdido, valor devolvido ao pagador
 *
 * A defesa é feita no painel da AssetPay (não há endpoint de reply na API).
 */
class AssetPayMedService
{
    public const REMOTE_ID_PREFIX = 'assetpay:';

    public function __construct(
        protected MedPolicyService $policy,
        protected MedResolutionService $resolution,
        protected MedEmailNotifications $notifications,
    ) {}

    public static function isAssetPayDispute(MedDispute $dispute): bool
    {
        $meta = is_array($dispute->metadata) ? $dispute->metadata : [];
        if (($meta['provider'] ?? null) === 'assetpay') {
            return true;
        }

        $id = trim((string) ($dispute->cajupay_dispute_id ?? ''));

        return str_starts_with($id, self::REMOTE_ID_PREFIX);
    }

    /**
     * @param  array<string, mixed>  $payload  corpo completo do webhook
     */
    public function handleWebhookEvent(string $event, array $payload, Order $order): ?MedDispute
    {
        $infraction = is_array($payload['infraction'] ?? null) ? $payload['infraction'] : [];

        return match ($event) {
            'transaction.infraction', 'transaction.blocked' => $this->syncOpened($order, $infraction, $payload),
            'transaction.infraction.closed' => $this->syncClosed($order, $infraction, $payload),
            'transaction.refunded' => $this->syncRefundedAsLostIfOpen($order, $infraction, $payload),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $infraction
     * @param  array<string, mixed>  $raw
     */
    public function syncOpened(Order $order, array $infraction, array $raw = []): ?MedDispute
    {
        $infractionId = trim((string) ($infraction['id'] ?? ''));
        if ($infractionId === '') {
            // transaction.blocked pode chegar sem objeto infraction; usa a disputa aberta se existir.
            $open = $this->openDisputeForOrder($order);
            if ($open !== null) {
                return $open;
            }
            Log::info('AssetPayMed: webhook sem infraction.id', [
                'order_id' => $order->id,
                'event' => $raw['event'] ?? null,
            ]);

            return null;
        }

        $remoteKey = self::REMOTE_ID_PREFIX.$infractionId;
        $existing = MedDispute::query()->where('cajupay_dispute_id', $remoteKey)->first();
        if ($existing !== null && ! $existing->isOpen()) {
            $meta = is_array($existing->metadata) ? $existing->metadata : [];
            $meta['last_sync'] = $infraction;
            $existing->update(['metadata' => $meta]);

            return $existing->fresh();
        }

        $created = $existing === null;
        $responsibleParty = $this->policy->responsiblePartyForOrder($order);
        $status = $existing?->status === MedDispute::STATUS_DEFENSE_SUBMITTED
            ? MedDispute::STATUS_DEFENSE_SUBMITTED
            : MedDispute::STATUS_OPEN;

        $amountCents = $this->amountCents($infraction, $order);
        $type = strtoupper(trim((string) ($infraction['type'] ?? 'MED')));
        $reason = trim((string) ($infraction['report_details'] ?? $infraction['reported_by'] ?? ''));
        $e2e = trim((string) ($infraction['end_to_end_id'] ?? ''));

        $dispute = MedDispute::query()->updateOrCreate(
            ['cajupay_dispute_id' => $remoteKey],
            [
                'order_id' => $order->id,
                'tenant_id' => (int) $order->tenant_id,
                'responsible_party' => $responsibleParty,
                'cajupay_payment_id' => trim((string) ($order->gateway_id ?? '')),
                'status' => $status,
                'outcome' => null,
                'amount_cents' => $amountCents,
                'currency' => strtoupper(trim((string) ($infraction['currency'] ?? 'BRL'))) ?: 'BRL',
                'txid' => $e2e !== '' ? $e2e : null,
                'reason' => $reason !== '' ? $reason : null,
                'reason_code' => $type !== '' ? $type : 'MED',
                'opened_at' => $existing?->opened_at ?? now(),
                'metadata' => [
                    'provider' => 'assetpay',
                    'assetpay_infraction_id' => $infractionId,
                    'assetpay_status' => (string) ($infraction['status'] ?? 'OPEN'),
                    'assetpay_provider' => (string) ($infraction['provider'] ?? ''),
                    'defense_note' => 'Defesa desta infração é feita no painel da AssetPay.',
                    'webhook' => $raw !== [] ? $raw : $infraction,
                ],
            ]
        );

        if ($this->policy->shouldHoldTenantBalance($dispute) && ! in_array($order->fresh()->status, ['disputed'], true)) {
            try {
                PlatformOrderAdminService::markDisputed($order->fresh());
            } catch (\InvalidArgumentException) {
                //
            }
        }

        if ($created) {
            $this->notifications->medOpened($dispute->fresh());
        }

        return $dispute->fresh();
    }

    /**
     * transaction.infraction.closed: encerrada favoravelmente (won) ou cancelada.
     * analysis_result AGREED = acatada em favor do pagador (perda vem via transaction.refunded).
     *
     * @param  array<string, mixed>  $infraction
     * @param  array<string, mixed>  $raw
     */
    public function syncClosed(Order $order, array $infraction, array $raw = []): ?MedDispute
    {
        $dispute = $this->findDispute($order, $infraction);
        if ($dispute === null) {
            return null;
        }

        $analysis = strtoupper(trim((string) ($infraction['analysis_result'] ?? '')));
        $remoteStatus = strtoupper(trim((string) ($infraction['status'] ?? '')));

        if ($analysis === 'AGREED') {
            // Pagador ganhou — o estorno (transaction.refunded) consolida a perda.
            return $this->resolve($dispute, 'lost', $infraction, $raw);
        }

        $outcome = $remoteStatus === 'CANCELLED' ? 'cancelled' : 'won';

        return $this->resolve($dispute, $outcome, $infraction, $raw);
    }

    /**
     * transaction.refunded: só vira MED perdido quando há disputa AssetPay aberta para o pedido.
     *
     * @param  array<string, mixed>  $infraction
     * @param  array<string, mixed>  $raw
     */
    public function syncRefundedAsLostIfOpen(Order $order, array $infraction, array $raw = []): ?MedDispute
    {
        $dispute = $this->findDispute($order, $infraction);
        if ($dispute === null) {
            return null;
        }
        if (! $dispute->isOpen() && $dispute->status !== MedDispute::STATUS_DEFENSE_SUBMITTED) {
            return $dispute;
        }

        return $this->resolve($dispute, 'lost', $infraction, $raw);
    }

    /**
     * @param  array<string, mixed>  $infraction
     * @param  array<string, mixed>  $raw
     */
    private function resolve(MedDispute $dispute, string $outcome, array $infraction, array $raw): MedDispute
    {
        if (! $dispute->isOpen() && $dispute->status !== MedDispute::STATUS_DEFENSE_SUBMITTED) {
            $meta = is_array($dispute->metadata) ? $dispute->metadata : [];
            $meta['webhook_resolved'] = $raw !== [] ? $raw : $infraction;
            $dispute->update(['metadata' => $meta]);

            return $dispute->fresh();
        }

        $mappedStatus = match ($outcome) {
            'lost' => MedDispute::STATUS_RESOLVED_LOST,
            'cancelled' => MedDispute::STATUS_CANCELLED,
            default => MedDispute::STATUS_RESOLVED_WON,
        };

        $meta = is_array($dispute->metadata) ? $dispute->metadata : [];
        $meta['assetpay_status'] = strtoupper((string) ($infraction['status'] ?? $outcome));
        $meta['webhook_resolved'] = $raw !== [] ? $raw : $infraction;

        $dispute->update([
            'status' => $mappedStatus,
            'outcome' => $outcome,
            'resolved_at' => now(),
            'metadata' => $meta,
        ]);

        $this->resolution->applyWalletOutcome($dispute->fresh(), $outcome);
        $this->notifications->medResolved($dispute->fresh());

        return $dispute->fresh();
    }

    /**
     * @param  array<string, mixed>  $infraction
     */
    private function findDispute(Order $order, array $infraction): ?MedDispute
    {
        $infractionId = trim((string) ($infraction['id'] ?? ''));
        if ($infractionId !== '') {
            $existing = MedDispute::query()
                ->where('cajupay_dispute_id', self::REMOTE_ID_PREFIX.$infractionId)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        return $this->openDisputeForOrder($order);
    }

    private function openDisputeForOrder(Order $order): ?MedDispute
    {
        $open = MedDispute::query()
            ->where('order_id', $order->id)
            ->open()
            ->latest('id')
            ->first();

        return $open !== null && self::isAssetPayDispute($open) ? $open : null;
    }

    /**
     * @param  array<string, mixed>  $infraction  amount em REAIS (float) no objeto infraction
     */
    private function amountCents(array $infraction, Order $order): int
    {
        if (isset($infraction['amount']) && is_numeric($infraction['amount'])) {
            return (int) round(((float) $infraction['amount']) * 100);
        }

        return (int) round(((float) $order->amount) * 100);
    }
}

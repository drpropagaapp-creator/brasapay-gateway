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
 * Objeto de infração da API nova (GET /v1/infractions/{id} e webhook `infracoes`):
 *  - status OPEN / UNDER_ANALYSIS → disputa aberta, valor bloqueado;
 *  - status CLOSED + analysisResult AGREED (outcome FUNDS_RETURNED) → MED perdido;
 *  - status CLOSED + analysisResult DISAGREED (outcome FUNDS_RELEASED) → MED ganho;
 *  - status CANCELED → cancelada, valor liberado.
 * Valores em centavos.
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
     * @param  array<string, mixed>  $infraction  objeto de infração conferido na API
     */
    public function syncInfraction(Order $order, array $infraction): ?MedDispute
    {
        $status = strtoupper(trim((string) ($infraction['status'] ?? '')));

        if (in_array($status, ['OPEN', 'UNDER_ANALYSIS'], true)) {
            return $this->syncOpened($order, $infraction);
        }

        $dispute = $this->findDispute($order, $infraction) ?? $this->syncOpened($order, $infraction);
        if ($dispute === null) {
            return null;
        }

        if ($status === 'CANCELED' || $status === 'CANCELLED') {
            return $this->resolve($dispute, 'cancelled', $infraction);
        }

        if ($status === 'CLOSED') {
            $analysis = strtoupper(trim((string) ($infraction['analysisResult'] ?? '')));
            $outcome = strtoupper(trim((string) ($infraction['outcome'] ?? '')));
            if ($analysis === 'AGREED' || $outcome === 'FUNDS_RETURNED') {
                return $this->resolve($dispute, 'lost', $infraction);
            }
            if ($analysis === 'DISAGREED' || $outcome === 'FUNDS_RELEASED') {
                return $this->resolve($dispute, 'won', $infraction);
            }
        }

        Log::info('AssetPayMed: status de infração sem ação', [
            'order_id' => $order->id,
            'status' => $status,
            'infraction_id' => $infraction['id'] ?? null,
        ]);

        return $dispute;
    }

    /**
     * @param  array<string, mixed>  $infraction
     */
    public function syncOpened(Order $order, array $infraction): ?MedDispute
    {
        $infractionId = trim((string) ($infraction['id'] ?? ''));
        if ($infractionId === '') {
            return $this->openDisputeForOrder($order);
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
        $remoteStatus = strtoupper(trim((string) ($infraction['status'] ?? 'OPEN')));
        $status = $existing?->status === MedDispute::STATUS_DEFENSE_SUBMITTED || $remoteStatus === 'UNDER_ANALYSIS'
            ? MedDispute::STATUS_DEFENSE_SUBMITTED
            : MedDispute::STATUS_OPEN;

        $type = strtoupper(trim((string) ($infraction['infractionType'] ?? 'MED')));
        $reason = trim((string) ($infraction['reportDetails'] ?? $infraction['reportedBy'] ?? ''));
        $e2e = trim((string) ($infraction['endToEndId'] ?? $infraction['transaction']['endToEndId'] ?? ''));

        $dispute = MedDispute::query()->updateOrCreate(
            ['cajupay_dispute_id' => $remoteKey],
            [
                'order_id' => $order->id,
                'tenant_id' => (int) $order->tenant_id,
                'responsible_party' => $this->policy->responsiblePartyForOrder($order),
                'cajupay_payment_id' => trim((string) ($order->gateway_id ?? '')),
                'status' => $status,
                'outcome' => null,
                'amount_cents' => $this->amountCents($infraction, $order),
                'currency' => strtoupper(trim((string) ($infraction['currency'] ?? 'BRL'))) ?: 'BRL',
                'txid' => $e2e !== '' ? $e2e : null,
                'reason' => $reason !== '' ? $reason : null,
                'reason_code' => $type !== '' ? $type : 'MED',
                'opened_at' => $existing?->opened_at ?? now(),
                'metadata' => [
                    'provider' => 'assetpay',
                    'assetpay_infraction_id' => $infractionId,
                    'assetpay_status' => $remoteStatus,
                    'defense_note' => 'Defesa desta infração é feita no painel da AssetPay.',
                    'infraction' => $infraction,
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
     * @param  array<string, mixed>  $infraction
     */
    private function resolve(MedDispute $dispute, string $outcome, array $infraction): MedDispute
    {
        if (! $dispute->isOpen() && $dispute->status !== MedDispute::STATUS_DEFENSE_SUBMITTED) {
            $meta = is_array($dispute->metadata) ? $dispute->metadata : [];
            $meta['last_sync'] = $infraction;
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
        $meta['assetpay_outcome'] = (string) ($infraction['outcome'] ?? '');
        $meta['infraction_resolved'] = $infraction;

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
     * @param  array<string, mixed>  $infraction  amount em CENTAVOS
     */
    private function amountCents(array $infraction, Order $order): int
    {
        if (isset($infraction['amount']) && is_numeric($infraction['amount'])) {
            return (int) $infraction['amount'];
        }

        return (int) round(((float) $order->amount) * 100);
    }
}

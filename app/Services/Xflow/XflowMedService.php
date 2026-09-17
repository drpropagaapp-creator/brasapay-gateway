<?php

namespace App\Services\Xflow;

use App\Models\MedDispute;
use App\Models\Order;
use App\Services\Med\MedPolicyService;
use App\Services\Med\MedResolutionService;
use App\Services\MedEmailNotifications;
use App\Services\PlatformOrderAdminService;
use Illuminate\Support\Facades\Log;

/**
 * MED Pix Xflow (webhooks dispute.*) → MedDispute + pedido/carteira.
 *
 * A defesa é enviada no painel da Xflow (Disputas); aqui só sincronizamos o status.
 */
class XflowMedService
{
    public const REMOTE_ID_PREFIX = 'xflow:';

    public function __construct(
        protected MedPolicyService $policy,
        protected MedResolutionService $resolution,
        protected MedEmailNotifications $notifications,
    ) {}

    public static function isXflowDispute(MedDispute $dispute): bool
    {
        $meta = is_array($dispute->metadata) ? $dispute->metadata : [];
        if (($meta['provider'] ?? null) === 'xflow') {
            return true;
        }

        $id = trim((string) ($dispute->cajupay_dispute_id ?? ''));

        return str_starts_with($id, self::REMOTE_ID_PREFIX);
    }

    public static function remoteIdFromDispute(MedDispute $dispute): string
    {
        $id = trim((string) ($dispute->cajupay_dispute_id ?? ''));
        if (str_starts_with($id, self::REMOTE_ID_PREFIX)) {
            return substr($id, strlen(self::REMOTE_ID_PREFIX));
        }

        $meta = is_array($dispute->metadata) ? $dispute->metadata : [];

        return trim((string) ($meta['xflow_dispute_id'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhookEvent(string $event, array $payload, Order $order): ?MedDispute
    {
        $data = $this->unwrapData($payload);
        $disputeId = $this->disputeId($data, $payload);
        if ($disputeId === '') {
            Log::info('XflowMed: webhook sem id da disputa', [
                'event' => $event,
                'order_id' => $order->id,
            ]);

            return null;
        }

        return match ($event) {
            'dispute.opened' => $this->syncOpened($order, $data, $payload),
            'dispute.accepted' => $this->syncResolved($order, $data, $payload, 'won'),
            'dispute.rejected' => $this->syncResolved($order, $data, $payload, 'lost'),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $raw
     */
    public function syncOpened(Order $order, array $data, array $raw = []): MedDispute
    {
        $disputeId = $this->disputeId($data, $raw);
        if ($disputeId === '') {
            throw new \InvalidArgumentException('id da disputa ausente no webhook Xflow.');
        }

        $remoteKey = self::REMOTE_ID_PREFIX.$disputeId;
        $existing = MedDispute::query()->where('cajupay_dispute_id', $remoteKey)->first();
        if ($existing !== null && ! $existing->isOpen()) {
            $meta = is_array($existing->metadata) ? $existing->metadata : [];
            $meta['last_sync'] = $data;
            $existing->update(['metadata' => $meta]);

            return $existing->fresh();
        }

        $responsibleParty = $this->policy->responsiblePartyForOrder($order);
        $status = MedDispute::STATUS_OPEN;
        if ($existing !== null && $existing->status === MedDispute::STATUS_DEFENSE_SUBMITTED) {
            $status = MedDispute::STATUS_DEFENSE_SUBMITTED;
        }

        $created = $existing === null;
        $type = strtoupper(trim((string) ($data['reason_code'] ?? $data['type'] ?? 'MED')));
        $reason = trim((string) ($data['reason'] ?? $data['message'] ?? ''));
        $e2e = trim((string) ($data['txid'] ?? $data['e2e_id'] ?? $data['pix_e2e_id'] ?? $data['end_to_end_id'] ?? ''));
        $amountCents = $this->amountCents($data, $order);

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
                'currency' => strtoupper(trim((string) ($data['currency'] ?? 'BRL'))) ?: 'BRL',
                'txid' => $e2e !== '' ? $e2e : null,
                'reason' => $reason !== '' ? $reason : null,
                'reason_code' => $type !== '' ? $type : 'MED',
                'opened_at' => $existing?->opened_at ?? now(),
                'metadata' => [
                    'provider' => 'xflow',
                    'xflow_dispute_id' => $disputeId,
                    'xflow_status' => (string) ($data['status'] ?? 'opened'),
                    'xflow_deadline_at' => $data['deadline_at'] ?? $data['deadlineAt'] ?? null,
                    'webhook' => $raw !== [] ? $raw : $data,
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
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $raw
     */
    public function syncResolved(Order $order, array $data, array $raw, string $outcome): MedDispute
    {
        $outcome = match (strtolower($outcome)) {
            'lost' => 'lost',
            'cancelled', 'canceled' => 'cancelled',
            default => 'won',
        };

        $dispute = $this->findOrOpenFromPayload($order, $data, $raw);
        if (! $dispute->isOpen() && $dispute->status !== MedDispute::STATUS_DEFENSE_SUBMITTED) {
            $meta = is_array($dispute->metadata) ? $dispute->metadata : [];
            $meta['webhook_resolved'] = $raw !== [] ? $raw : $data;
            $dispute->update(['metadata' => $meta]);

            return $dispute->fresh();
        }

        $mappedStatus = match ($outcome) {
            'lost' => MedDispute::STATUS_RESOLVED_LOST,
            'cancelled' => MedDispute::STATUS_CANCELLED,
            default => MedDispute::STATUS_RESOLVED_WON,
        };

        $meta = is_array($dispute->metadata) ? $dispute->metadata : [];
        $meta['xflow_status'] = $outcome;
        $meta['webhook_resolved'] = $raw !== [] ? $raw : $data;

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
     * Registra a defesa localmente. A Xflow não expõe API de contestação — envie no painel Disputas.
     */
    public function submitDefense(MedDispute $dispute, string $text): MedDispute
    {
        if (! $dispute->isOpen()) {
            throw new \InvalidArgumentException('Esta disputa não está aberta para defesa.');
        }

        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('Informe o texto da defesa.');
        }
        if (mb_strlen($text) > 10000) {
            throw new \InvalidArgumentException('A defesa pode ter no máximo 10000 caracteres.');
        }

        $meta = is_array($dispute->metadata) ? $dispute->metadata : [];
        $meta['xflow_status'] = 'defense_local';
        $meta['defense_panel_required'] = true;

        $dispute->update([
            'defense_text' => $text,
            'defended_at' => now(),
            'status' => MedDispute::STATUS_DEFENSE_SUBMITTED,
            'metadata' => $meta,
        ]);

        return $dispute->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $raw
     */
    private function findOrOpenFromPayload(Order $order, array $data, array $raw): MedDispute
    {
        $disputeId = $this->disputeId($data, $raw);
        if ($disputeId !== '') {
            $existing = MedDispute::query()
                ->where('cajupay_dispute_id', self::REMOTE_ID_PREFIX.$disputeId)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $open = MedDispute::query()
            ->where('order_id', $order->id)
            ->open()
            ->latest('id')
            ->first();
        if ($open !== null && self::isXflowDispute($open)) {
            return $open;
        }

        return $this->syncOpened($order, $data, $raw);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function unwrapData(array $payload): array
    {
        $data = $payload['data'] ?? null;

        return is_array($data) ? $data : $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $raw
     */
    public function disputeId(array $data, array $raw = []): string
    {
        foreach (['dispute_id', 'disputeId'] as $key) {
            $v = trim((string) ($data[$key] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        $nested = $data['dispute'] ?? null;
        if (is_array($nested)) {
            $v = trim((string) ($nested['id'] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        $id = trim((string) ($data['id'] ?? ''));
        $chargeId = self::chargeId($data);
        if ($id !== '' && ($chargeId !== '' || ! str_starts_with($id, 'clx'))) {
            return $id;
        }

        return trim((string) ($raw['id'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function chargeId(array $data): string
    {
        foreach (['charge_id', 'chargeId', 'transaction_id', 'payment_id', 'charge_id_id'] as $key) {
            $v = trim((string) ($data[$key] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        $charge = $data['charge'] ?? null;
        if (is_array($charge)) {
            return trim((string) ($charge['id'] ?? ''));
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function amountCents(array $data, Order $order): int
    {
        if (isset($data['amountCents']) && is_numeric($data['amountCents'])) {
            return (int) $data['amountCents'];
        }
        if (isset($data['amount_cents']) && is_numeric($data['amount_cents'])) {
            return (int) $data['amount_cents'];
        }
        if (isset($data['amount']) && is_numeric($data['amount'])) {
            $amount = (float) $data['amount'];
            if (fmod($amount, 1.0) === 0.0) {
                return (int) $amount;
            }

            return (int) round($amount * 100);
        }

        return (int) round(((float) $order->amount) * 100);
    }
}

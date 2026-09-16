<?php

namespace App\Services\AssetPay;

use App\Gateways\AssetPay\AssetPayDriver;
use App\Models\GatewayCredential;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\EffectiveMerchantFees;
use App\Services\Payout\GatewayPayoutEconomics;
use App\Services\Payout\PayoutUserSettings;
use App\Services\Payout\WithdrawalPayoutDestination;
use App\Services\Withdrawal\WithdrawalMinimumService;
use App\Support\GatewayWebhookUrl;

/**
 * Payout (saque PIX) via AssetPay. Valores da API em REAIS.
 */
class AssetPayPayoutService
{
    protected AssetPayDriver $driver;

    public function __construct(?AssetPayDriver $driver = null)
    {
        $this->driver = $driver ?? new AssetPayDriver;
    }

    /**
     * @return array{ok: bool, pending?: bool, transaction_id?: string|null, error?: string}
     */
    public function sendWithdrawalToPix(Withdrawal $withdrawal, User $owner): array
    {
        $credential = GatewayCredential::resolveForPayment(null, 'assetpay');
        if ($credential === null || ! $credential->is_connected) {
            return ['ok' => false, 'error' => 'Saque automático não configurado pela plataforma (AssetPay).'];
        }
        $credentials = $credential->getDecryptedCredentials();
        if (trim((string) ($credentials['secret_key'] ?? '')) === '' || trim((string) ($credentials['public_key'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'Configure Secret Key e Public Key da AssetPay nas adquirentes da plataforma.'];
        }

        $net = (float) $withdrawal->net_amount;
        if ($net <= 0) {
            return ['ok' => false, 'error' => 'Valor líquido do saque inválido.'];
        }

        $economics = GatewayPayoutEconomics::fromCredentialsArray('assetpay', $credentials);
        $requiredNet = WithdrawalMinimumService::effectiveRequiredMinNet($economics);
        $minCents = (int) max(1, (int) round($requiredNet * 100));
        $apiAmount = GatewayPayoutEconomics::transferAmountBrlForApi($net, $economics['admin_fee_payout_brl']);
        $amountCents = (int) round($net * 100);
        if ($amountCents < $minCents) {
            $tenantId = (int) $withdrawal->tenant_id;
            $minGross = EffectiveMerchantFees::minimumWithdrawalGrossForTargetNet($tenantId, $requiredNet);
            $msg = $minGross !== null
                ? 'O valor mínimo do saque é R$ '.number_format($minGross, 2, ',', '.').' (valor total a solicitar).'
                : 'O valor solicitado é inferior ao mínimo permitido.';

            return ['ok' => false, 'error' => $msg];
        }

        $settings = is_array($owner->payout_settings) ? $owner->payout_settings : [];
        $fromWithdrawal = WithdrawalPayoutDestination::fromWithdrawal($withdrawal);
        $pixKey = $fromWithdrawal['pix_key'] ?? PayoutUserSettings::pixKey($settings);
        $pixKeyType = $fromWithdrawal['pix_key_type'] ?? PayoutUserSettings::pixKeyType($settings);
        if ($pixKey === '') {
            return ['ok' => false, 'error' => 'Cadastre a chave PIX de destino no Financeiro antes de solicitar o saque.'];
        }

        $label = PayoutUserSettings::pixLabel($settings);
        $result = $this->driver->createPixCashout(
            $credentials,
            $apiAmount,
            $pixKey,
            $pixKeyType,
            (string) $withdrawal->id,
            GatewayWebhookUrl::forGateway('assetpay'),
            $label
        );
        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => $result['error'] ?? 'Falha ao processar o saque na AssetPay.'];
        }

        $tid = $result['transaction_id'] ?? null;

        return [
            'ok' => true,
            'pending' => true,
            'transaction_id' => is_string($tid) && $tid !== '' ? $tid : null,
        ];
    }
}

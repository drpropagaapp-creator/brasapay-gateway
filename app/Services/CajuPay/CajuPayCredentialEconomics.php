<?php

namespace App\Services\CajuPay;

use App\Models\CajuPayAccount;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Withdrawal;
use App\Services\Payout\GatewayPayoutEconomics;

class CajuPayCredentialEconomics
{
    public const DEFAULT_MIN_PAYOUT_BRL = GatewayPayoutEconomics::DEFAULT_MIN_PAYOUT_BRL;

    /**
     * Líquido mínimo exigido pelo gateway: mínimo CajuPay configurado (sem somar taxas admin).
     *
     * @return array{
     *     required_min_net: float,
     *     cajupay_payout_min_brl: float,
     *     cajupay_admin_fee_pix_brl: float,
     *     cajupay_admin_fee_payout_brl: float,
     *     cajupay_admin_fee_pix_percent: float,
     *     cajupay_admin_fee_payout_percent: float
     * }
     */
    public static function fromGateway(): array
    {
        $account = app(CajuPayAccountResolver::class)->defaultOrFirstConnected();
        if ($account !== null && $account->is_connected) {
            return self::fromCredentialsArray($account->getDecryptedCredentials());
        }

        $cred = GatewayCredential::resolveForPayment(null, 'cajupay');
        if ($cred === null || ! $cred->is_connected) {
            return self::defaults();
        }

        return self::fromCredentialsArray($cred->getDecryptedCredentials());
    }

    /**
     * Economia da conta usada na cobrança do pedido (cajupay_account_id → tenant → default).
     *
     * @return array{
     *     required_min_net: float,
     *     cajupay_payout_min_brl: float,
     *     cajupay_admin_fee_pix_brl: float,
     *     cajupay_admin_fee_payout_brl: float,
     *     cajupay_admin_fee_pix_percent: float,
     *     cajupay_admin_fee_payout_percent: float
     * }
     */
    public static function forOrder(Order $order): array
    {
        return self::fromAccount(
            app(CajuPayAccountResolver::class)->resolveForOrder($order)
        );
    }

    /**
     * Economia da conta usada no payout (meta do saque → tenant → default).
     * Alinha ao CajuPayPayoutService::sendWithdrawalToPixKey.
     *
     * @return array{
     *     required_min_net: float,
     *     cajupay_payout_min_brl: float,
     *     cajupay_admin_fee_pix_brl: float,
     *     cajupay_admin_fee_payout_brl: float,
     *     cajupay_admin_fee_pix_percent: float,
     *     cajupay_admin_fee_payout_percent: float
     * }
     */
    public static function forWithdrawal(Withdrawal $withdrawal): array
    {
        $resolver = app(CajuPayAccountResolver::class);
        $meta = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        $metaAccountId = (int) ($meta['cajupay_account_id'] ?? 0);
        if ($metaAccountId > 0) {
            $fromMeta = $resolver->resolveById($metaAccountId);
            if ($fromMeta !== null) {
                return self::fromAccount($fromMeta);
            }
        }

        $account = $resolver->resolveForTenant((int) $withdrawal->tenant_id)
            ?? $resolver->defaultOrFirstConnected();

        return self::fromAccount($account);
    }

    /**
     * @return array{
     *     required_min_net: float,
     *     cajupay_payout_min_brl: float,
     *     cajupay_admin_fee_pix_brl: float,
     *     cajupay_admin_fee_payout_brl: float,
     *     cajupay_admin_fee_pix_percent: float,
     *     cajupay_admin_fee_payout_percent: float
     * }
     */
    public static function fromAccount(?CajuPayAccount $account): array
    {
        if ($account !== null && $account->is_connected) {
            return self::fromCredentialsArray($account->getDecryptedCredentials());
        }

        return self::fromGateway();
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{
     *     required_min_net: float,
     *     cajupay_payout_min_brl: float,
     *     cajupay_admin_fee_pix_brl: float,
     *     cajupay_admin_fee_payout_brl: float,
     *     cajupay_admin_fee_pix_percent: float,
     *     cajupay_admin_fee_payout_percent: float
     * }
     */
    public static function fromCredentialsArray(array $credentials): array
    {
        $e = GatewayPayoutEconomics::fromCredentialsArray('cajupay', $credentials);

        return [
            'required_min_net' => $e['required_min_net'],
            'cajupay_payout_min_brl' => $e['payout_min_brl'],
            'cajupay_admin_fee_pix_brl' => $e['admin_fee_pix_brl'],
            'cajupay_admin_fee_payout_brl' => $e['admin_fee_payout_brl'],
            'cajupay_admin_fee_pix_percent' => $e['admin_fee_pix_percent'],
            'cajupay_admin_fee_payout_percent' => $e['admin_fee_payout_percent'],
        ];
    }

    /**
     * @return array{
     *     required_min_net: float,
     *     cajupay_payout_min_brl: float,
     *     cajupay_admin_fee_pix_brl: float,
     *     cajupay_admin_fee_payout_brl: float,
     *     cajupay_admin_fee_pix_percent: float,
     *     cajupay_admin_fee_payout_percent: float
     * }
     */
    private static function defaults(): array
    {
        $e = GatewayPayoutEconomics::fromCredentialsArray('cajupay', []);

        return [
            'required_min_net' => $e['required_min_net'],
            'cajupay_payout_min_brl' => $e['payout_min_brl'],
            'cajupay_admin_fee_pix_brl' => $e['admin_fee_pix_brl'],
            'cajupay_admin_fee_payout_brl' => $e['admin_fee_payout_brl'],
            'cajupay_admin_fee_pix_percent' => $e['admin_fee_pix_percent'],
            'cajupay_admin_fee_payout_percent' => $e['admin_fee_payout_percent'],
        ];
    }
}

<?php

namespace App\Services\CajuPay;

use App\Models\CajuPayAccount;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;

class CajuPayAccountResolver
{
    public const METHOD_ACCOUNT_SETTING = 'cajupay_method_accounts';

    /** @var list<string> */
    public const METHOD_KEYS = ['pix', 'card', 'boleto', 'pix_auto'];

    public function resolveForTenant(?int $tenantId): ?CajuPayAccount
    {
        $assigned = $this->assignedAccountForTenant($tenantId);
        if ($assigned !== null) {
            return $assigned;
        }

        $default = CajuPayAccount::query()->default()->first();
        if ($default !== null && $default->is_enabled && $this->isUsableAccount($default)) {
            return $default;
        }

        $anyUsable = CajuPayAccount::query()
            ->enabled()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->first(fn (CajuPayAccount $account) => $this->isUsableAccount($account));

        if ($anyUsable !== null) {
            return $anyUsable;
        }

        return $this->legacyCredentialAsVirtualAccount();
    }

    /**
     * Conta usada na cobrança de um método.
     * Vendedor com conta fixa continua nela. Sem conta fixa, vale a conta escolhida
     * em Financeiro → Prioridade por método. Se essa conta não estiver utilizável,
     * a CajuPay fica de fora desse método (não cai na outra conta).
     */
    public function resolveForPayment(?int $tenantId, ?string $method): ?CajuPayAccount
    {
        $assigned = $this->assignedAccountForTenant($tenantId);
        if ($assigned !== null) {
            return $assigned;
        }

        $key = $this->normalizeMethodKey($method);
        $map = $this->storedMethodAccountIds();
        if ($key !== null && ! empty($map[$key])) {
            $account = CajuPayAccount::query()
                ->whereKey((int) $map[$key])
                ->enabled()
                ->connected()
                ->first();
            if ($account !== null && $this->isUsableAccount($account)) {
                return $account;
            }

            return null;
        }

        return $this->resolveForTenant($tenantId);
    }

    public function accountIdForPayment(?int $tenantId, ?string $method): ?int
    {
        $id = (int) ($this->resolveForPayment($tenantId, $method)?->id ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * @return array{pix: ?int, card: ?int, boleto: ?int, pix_auto: ?int}
     */
    public function methodAccountMap(?int $tenantId = null): array
    {
        return $this->sanitizeMethodAccounts($this->storedMethodAccountIds($tenantId));
    }

    /**
     * IDs gravados, mesmo se a conta tiver sido desligada depois.
     *
     * @return array{pix: ?int, card: ?int, boleto: ?int, pix_auto: ?int}
     */
    private function storedMethodAccountIds(?int $tenantId = null): array
    {
        $raw = Setting::get(self::METHOD_ACCOUNT_SETTING, null, $tenantId);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($raw)) {
            $raw = [];
        }

        $out = [];
        foreach (self::METHOD_KEYS as $key) {
            $id = (int) ($raw[$key] ?? 0);
            $out[$key] = $id > 0 ? $id : null;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{pix: ?int, card: ?int, boleto: ?int, pix_auto: ?int}
     */
    public function saveMethodAccounts(array $input, ?int $tenantId = null): array
    {
        $clean = $this->sanitizeMethodAccounts($input);
        Setting::set(self::METHOD_ACCOUNT_SETTING, $clean, $tenantId);

        return $clean;
    }

    public function resolveForOrder(Order $order): ?CajuPayAccount
    {
        if ($order->cajupay_account_id) {
            $account = CajuPayAccount::query()->whereKey($order->cajupay_account_id)->first();
            if ($account !== null) {
                return $account;
            }
        }

        return $this->resolveForTenant($order->tenant_id);
    }

    public function isAvailableForTenant(?int $tenantId): bool
    {
        $account = $this->resolveForTenant($tenantId);
        if ($account === null) {
            return false;
        }

        return \App\Support\GatewayApiCredentials::isReadyForGateway(
            'cajupay',
            $account->getDecryptedCredentials()
        );
    }

    public function resolveById(int $accountId): ?CajuPayAccount
    {
        return CajuPayAccount::query()->whereKey($accountId)->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function credentialsForTenant(?int $tenantId): array
    {
        $account = $this->resolveForTenant($tenantId);

        return $account !== null ? $account->getDecryptedCredentials() : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function credentialsForOrder(Order $order): ?array
    {
        $account = $this->resolveForOrder($order);
        if ($account === null) {
            return null;
        }

        $credentials = $account->getDecryptedCredentials();

        return $credentials === [] ? null : $credentials;
    }

    public function accountIdForTenant(?int $tenantId): ?int
    {
        return $this->resolveForTenant($tenantId)?->id;
    }

    /**
     * @return Collection<int, CajuPayAccount>
     */
    public function allConnectedForWebhookValidation(): Collection
    {
        $accounts = CajuPayAccount::query()
            ->where('is_connected', true)
            ->where('is_enabled', true)
            ->get()
            ->filter(fn (CajuPayAccount $account) => $this->isUsableAccount($account));

        if ($accounts->isNotEmpty()) {
            return $accounts;
        }

        $legacy = $this->legacyCredentialAsVirtualAccount();

        return $legacy !== null ? collect([$legacy]) : collect();
    }

    public function anyConnectedForPayout(): bool
    {
        if (CajuPayAccount::query()->connected()->enabled()->exists()) {
            return true;
        }

        $legacy = GatewayCredential::forTenant(null)
            ->where('gateway_slug', 'cajupay')
            ->where('is_connected', true)
            ->enabledForPayments()
            ->exists();

        return $legacy;
    }

    public function defaultOrFirstConnected(): ?CajuPayAccount
    {
        $default = CajuPayAccount::query()->default()->enabled()->first();
        if ($default !== null && $this->isUsableAccount($default)) {
            return $default;
        }

        $any = CajuPayAccount::query()
            ->enabled()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->first(fn (CajuPayAccount $account) => $this->isUsableAccount($account));

        return $any ?? $this->legacyCredentialAsVirtualAccount();
    }

    private function assignedAccountForTenant(?int $tenantId): ?CajuPayAccount
    {
        if ($tenantId === null || $tenantId <= 0) {
            return null;
        }

        $owner = User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhere('id', $tenantId);
            })
            ->whereNotNull('cajupay_account_id')
            ->first();

        if ($owner === null || ! $owner->cajupay_account_id) {
            return null;
        }

        return CajuPayAccount::query()
            ->whereKey($owner->cajupay_account_id)
            ->enabled()
            ->connected()
            ->first();
    }

    private function normalizeMethodKey(?string $method): ?string
    {
        $method = strtolower(trim((string) $method));
        if (in_array($method, ['apple_pay', 'google_pay'], true)) {
            $method = 'card';
        }

        return in_array($method, self::METHOD_KEYS, true) ? $method : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{pix: ?int, card: ?int, boleto: ?int, pix_auto: ?int}
     */
    private function sanitizeMethodAccounts(array $input): array
    {
        $allowed = array_flip(
            CajuPayAccount::query()
                ->enabled()
                ->connected()
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );

        $out = [];
        foreach (self::METHOD_KEYS as $key) {
            $id = (int) ($input[$key] ?? 0);
            $out[$key] = $id > 0 && isset($allowed[$id]) ? $id : null;
        }

        return $out;
    }

    private function isUsableAccount(CajuPayAccount $account): bool
    {
        if (! $account->is_connected) {
            return false;
        }

        return \App\Support\GatewayApiCredentials::isReadyForGateway(
            'cajupay',
            $account->getDecryptedCredentials()
        );
    }

    /**
     * Fallback durante transição: credencial global legada em gateway_credentials.
     */
    private function legacyCredentialAsVirtualAccount(): ?CajuPayAccount
    {
        $legacy = GatewayCredential::forTenant(null)
            ->where('gateway_slug', 'cajupay')
            ->where('is_connected', true)
            ->enabledForPayments()
            ->first();

        if ($legacy === null) {
            return null;
        }

        $virtual = new CajuPayAccount([
            'name' => 'Conta legada',
            'is_default' => true,
            'is_connected' => true,
            'is_enabled' => true,
        ]);
        $virtual->id = 0;
        $virtual->setEncryptedCredentials($legacy->getDecryptedCredentials());

        return $virtual;
    }
}

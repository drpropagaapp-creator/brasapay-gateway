<?php

namespace App\Services\Med;

use App\Models\MedDispute;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;

class MedPolicyService
{
    public const SETTING_MED_ZERO = 'med_zero_enabled';

    public function isApiPixRestOrder(Order $order): bool
    {
        if ($order->api_application_id === null) {
            return false;
        }

        if ($order->payment_method !== 'pix') {
            return false;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];

        return ($meta['source'] ?? null) === 'api';
    }

    public function responsiblePartyForOrder(Order $order): string
    {
        // MED de venda PIX (checkout ou API) é responsabilidade do infoprodutor:
        // trava o valor na carteira e aparece em Vendas → Disputas MED para defesa.
        // Exceção: tenant com "MED Zero" ativo — a plataforma absorve a disputa.
        if ($order->payment_method === 'pix' && ! $this->medZeroForTenant((int) $order->tenant_id)) {
            return MedDispute::PARTY_TENANT;
        }

        return MedDispute::PARTY_PLATFORM;
    }

    public function medZeroForTenant(int $tenantId): bool
    {
        if ($tenantId <= 0) {
            return false;
        }

        $raw = Setting::get(self::SETTING_MED_ZERO, null, $tenantId);
        if ($raw === null || $raw === '') {
            return false;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    public function setMedZeroForTenant(int $tenantId, bool $enabled): void
    {
        if ($tenantId <= 0) {
            return;
        }

        if (! $enabled) {
            Setting::query()
                ->where('key', self::SETTING_MED_ZERO)
                ->where('tenant_id', $tenantId)
                ->delete();

            return;
        }

        Setting::set(self::SETTING_MED_ZERO, true, $tenantId);
    }

    public function shouldHoldTenantBalance(MedDispute $dispute): bool
    {
        if ($dispute->responsible_party === MedDispute::PARTY_TENANT) {
            return true;
        }

        if ($dispute->responsible_party !== MedDispute::PARTY_PLATFORM) {
            return false;
        }

        $order = $dispute->relationLoaded('order') ? $dispute->order : null;
        if (! $order instanceof Order && $dispute->order_id) {
            $order = $dispute->order()->first();
        }

        if (! $order instanceof Order || $this->isApiPixRestOrder($order)) {
            return false;
        }

        // BrasaPay: com "MED Zero" a plataforma absorve o MED de PIX — não retém o saldo do infoprodutor.
        if ($order->payment_method === 'pix' && $this->medZeroForTenant((int) $order->tenant_id)) {
            return false;
        }

        return true;
    }

    /**
     * O infoprodutor acompanha a disputa no painel: API PIX sob a responsabilidade dele
     * e MED de infoproduto, cujo saldo fica retido até a solução.
     */
    public function sellerFollowsDispute(MedDispute $dispute): bool
    {
        return $this->shouldHoldTenantBalance($dispute);
    }

    /**
     * @param  Builder<MedDispute>  $query
     * @return Builder<MedDispute>
     */
    public function scopeDisputesVisibleToSeller(Builder $query): Builder
    {
        return $query->where(function (Builder $visible) {
            $visible->where('responsible_party', MedDispute::PARTY_TENANT)
                ->orWhere(function (Builder $product) {
                    $product->where('responsible_party', MedDispute::PARTY_PLATFORM)
                        ->whereHas('order', function (Builder $order) {
                            $order->where(function (Builder $notApiPix) {
                                $notApiPix->whereNull('api_application_id')
                                    ->orWhere('payment_method', '!=', 'pix')
                                    ->orWhereNull('metadata->source')
                                    ->orWhere('metadata->source', '!=', 'api');
                            });
                        });
                });
        });
    }

    public function orderOriginLabel(Order $order): string
    {
        return $this->isApiPixRestOrder($order) ? 'API PIX REST' : 'Checkout';
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeApiPixRestOrders(Builder $query): Builder
    {
        return $query
            ->whereNotNull('api_application_id')
            ->where('payment_method', 'pix')
            ->where('metadata->source', 'api');
    }
}

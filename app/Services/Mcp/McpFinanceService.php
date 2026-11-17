<?php

namespace App\Services\Mcp;

use App\Models\TenantWallet;
use App\Models\WalletTransaction;
use App\Support\PendingReceiveSchedule;
use Illuminate\Support\Facades\Schema;

class McpFinanceService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function toolDefinitions(): array
    {
        return [
            $this->tool('finance.get_balance', 'Saldo disponível e a receber deste tenant. Somente leitura. Não saca, não mostra chave PIX, documento, taxas de adquirente nem bloqueio administrativo.', [
                'type' => 'object',
                'properties' => new \stdClass,
                'additionalProperties' => false,
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function call(string $name, array $arguments, int $tenantId): array
    {
        return match ($name) {
            'finance.get_balance' => $this->getBalance($tenantId),
            default => throw new McpCatalogException('Ferramenta indisponível neste servidor.', 'unknown_tool'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function getBalance(int $tenantId): array
    {
        $empty = [
            'read_only' => true,
            'currency' => 'BRL',
            'available_pix' => 0.0,
            'available_card' => 0.0,
            'available_boleto' => 0.0,
            'available_total' => 0.0,
            'pending_pix' => 0.0,
            'pending_card' => 0.0,
            'pending_boleto' => 0.0,
            'pending_total' => 0.0,
            'reserve_pending_total' => 0.0,
            'pending_receive_by_date' => [],
        ];

        if (! Schema::hasTable('tenant_wallets')) {
            return $empty;
        }

        $wallet = TenantWallet::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            [
                'available_balance' => 0,
                'pending_balance' => 0,
                'currency' => 'BRL',
                'available_pix' => 0,
                'available_card' => 0,
                'available_boleto' => 0,
                'pending_pix' => 0,
                'pending_card' => 0,
                'pending_boleto' => 0,
            ]
        );

        $pp = (float) ($wallet->pending_pix ?? 0);
        $pc = (float) ($wallet->pending_card ?? 0);
        $pb = (float) ($wallet->pending_boleto ?? 0);
        $ap = (float) ($wallet->available_pix ?? 0);
        $ac = (float) ($wallet->available_card ?? 0);
        $ab = (float) ($wallet->available_boleto ?? 0);

        return [
            'read_only' => true,
            'currency' => (string) ($wallet->currency ?? 'BRL'),
            'available_pix' => $ap,
            'available_card' => $ac,
            'available_boleto' => $ab,
            'available_total' => round($ap + $ac + $ab, 2),
            'pending_pix' => $pp,
            'pending_card' => $pc,
            'pending_boleto' => $pb,
            'pending_total' => round($pp + $pc + $pb, 2),
            'reserve_pending_total' => $this->reservePendingTotal($tenantId),
            'pending_receive_by_date' => PendingReceiveSchedule::forTenant($tenantId),
        ];
    }

    private function reservePendingTotal(int $tenantId): float
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return 0.0;
        }

        return round((float) WalletTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('type', WalletTransaction::TYPE_CREDIT_SALE_PENDING)
            ->get()
            ->filter(function (WalletTransaction $t) {
                $m = is_array($t->meta) ? $t->meta : [];

                return ($m['portion'] ?? '') === 'reserve' && empty($m['released_at'] ?? null);
            })
            ->sum(fn (WalletTransaction $t) => (float) $t->amount_net), 2);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function tool(string $name, string $description, array $schema): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $schema,
        ];
    }
}

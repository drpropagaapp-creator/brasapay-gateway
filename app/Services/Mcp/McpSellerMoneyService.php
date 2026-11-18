<?php

namespace App\Services\Mcp;

use App\Http\Controllers\SellerFinancialController;
use App\Http\Controllers\VendasController;
use App\Models\MedDispute;
use App\Models\Order;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\EffectiveMerchantFees;
use App\Services\Med\MedPolicyService;
use App\Services\MerchantWalletAdminBlockService;
use App\Services\Platform\PlatformTotpService;
use App\Support\MoneyDecimal;
use App\Support\OrderManualRefund;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * MCP do infoprodutor: saques, reembolsos e MED.
 * Saque e reembolso são em duas etapas (prévia + confirmação com o 2FA do dono da conta)
 * e rodam pelo mesmo código do painel do vendedor.
 */
class McpSellerMoneyService
{
    private const TOOLS = [
        'finance.list_withdrawals',
        'finance.preview_withdrawal',
        'finance.confirm_withdrawal',
        'sales.preview_refund',
        'sales.confirm_refund',
        'med.list_disputes',
    ];

    public function __construct(
        private readonly McpPreviewStore $previews,
        private readonly McpPanelActionRunner $runner,
        private readonly MedPolicyService $medPolicy,
    ) {}

    public function handles(string $name): bool
    {
        return in_array($name, self::TOOLS, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toolDefinitions(): array
    {
        $confirm = [
            'type' => 'object',
            'properties' => [
                'preview_id' => ['type' => 'string'],
                'totp_code' => ['type' => 'string', 'description' => 'Código de 6 dígitos do app autenticador do dono da conta.'],
            ],
            'required' => ['preview_id', 'totp_code'],
            'additionalProperties' => false,
        ];

        return [
            $this->tool('finance.list_withdrawals', 'Últimos saques desta conta com valor, taxa, líquido e status. Somente leitura.', [
                'type' => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                ],
                'additionalProperties' => false,
            ]),
            $this->tool('finance.preview_withdrawal', 'Etapa 1 do saque: mostra saldo sacável, taxa e valor líquido do PIX e devolve um preview_id (15 min). Não saca.', [
                'type' => 'object',
                'properties' => [
                    'amount' => ['type' => 'number', 'description' => 'Valor bruto em reais.'],
                    'bucket' => ['type' => 'string', 'enum' => ['pix', 'card', 'boleto'], 'description' => 'De qual saldo sacar. Padrão: pix.'],
                    'notes' => ['type' => 'string'],
                ],
                'required' => ['amount'],
                'additionalProperties' => false,
            ]),
            $this->tool('finance.confirm_withdrawal', 'Etapa 2 do saque: executa o preview_id. Só chame depois que o dono da conta confirmou o resumo e informou o código 2FA. Segue as mesmas regras do painel (KYC, horário, mínimo, intervalo, dados PIX).', $confirm),
            $this->tool('sales.preview_refund', 'Etapa 1 do reembolso de um pedido pago desta conta: mostra o que será estornado e devolve um preview_id. Não reembolsa.', [
                'type' => 'object',
                'properties' => [
                    'order_id' => ['type' => 'integer'],
                    'reason' => ['type' => 'string', 'description' => 'Motivo (3 a 500 caracteres).'],
                ],
                'required' => ['order_id', 'reason'],
                'additionalProperties' => false,
            ]),
            $this->tool('sales.confirm_refund', 'Etapa 2 do reembolso: executa o preview_id. Só chame depois que o dono da conta confirmou e informou o código 2FA. O valor sai do saldo da conta.', $confirm),
            $this->tool('med.list_disputes', 'Disputas MED desta conta (abertas por padrão): pedido, valor, prazo e status. Somente leitura; não consulta o gateway.', [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['open', 'resolved', 'all']],
                ],
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
            'finance.list_withdrawals' => $this->listWithdrawals($arguments, $tenantId),
            'finance.preview_withdrawal' => $this->previewWithdrawal($arguments, $tenantId),
            'finance.confirm_withdrawal' => $this->confirm($arguments, $tenantId, 'withdrawal'),
            'sales.preview_refund' => $this->previewRefund($arguments, $tenantId),
            'sales.confirm_refund' => $this->confirm($arguments, $tenantId, 'refund'),
            'med.list_disputes' => $this->listDisputes($arguments, $tenantId),
            default => throw new McpCatalogException('Ferramenta indisponível neste servidor.', 'unknown_tool'),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function listWithdrawals(array $arguments, int $tenantId): array
    {
        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 20)));

        return [
            'withdrawals' => Withdrawal::query()->where('tenant_id', $tenantId)->orderByDesc('id')->limit($limit)
                ->get(['id', 'amount', 'fee_amount', 'net_amount', 'bucket', 'status', 'created_at'])
                ->map(fn (Withdrawal $w) => [
                    'id' => $w->id,
                    'valor_solicitado' => (float) $w->amount,
                    'taxa' => (float) $w->fee_amount,
                    'valor_liquido' => (float) $w->net_amount,
                    'bucket' => $w->bucket,
                    'status' => $w->status,
                    'criado_em' => $w->created_at?->toIso8601String(),
                ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function previewWithdrawal(array $arguments, int $tenantId): array
    {
        $amount = round((float) ($arguments['amount'] ?? 0), 2);
        $bucket = is_string($arguments['bucket'] ?? null) ? $arguments['bucket'] : 'pix';
        if (! in_array($bucket, ['pix', 'card', 'boleto'], true)) {
            throw new McpCatalogException('bucket deve ser pix, card ou boleto.', 'invalid_params');
        }
        if ($amount <= 0 || $amount > 99999999) {
            throw new McpCatalogException('Informe um valor de saque válido.', 'invalid_params');
        }

        $owner = $this->owner($tenantId);
        $wallet = TenantWallet::query()->where('tenant_id', $tenantId)->first();
        $available = $wallet ? MerchantWalletAdminBlockService::effectiveAvailableForWithdrawal($wallet, $bucket) : 0.0;
        if ($amount > $available) {
            throw new McpCatalogException('Saldo sacável insuficiente: R$ '.number_format($available, 2, ',', '.').' em '.$bucket.'.', 'insufficient_balance');
        }

        $fee = EffectiveMerchantFees::calculateWithdrawalFee($tenantId, $amount);
        $notes = is_string($arguments['notes'] ?? null) ? mb_substr(trim($arguments['notes']), 0, 500) : null;

        $stored = $this->previews->put($tenantId, [[
            'kind' => 'withdrawal',
            'amount' => $amount,
            'bucket' => $bucket,
            'notes' => $notes,
        ]]);

        return [
            'preview_id' => $stored['preview_id'],
            'expires_at' => $stored['expires_at'],
            'resumo' => [
                'o_que_acontece' => 'Solicita saque de R$ '.number_format($amount, 2, ',', '.').' do saldo '.$bucket.'. Se o saque automático estiver ligado, o PIX é enviado na hora para a chave cadastrada.',
                'saldo_sacavel' => $available,
                'taxa' => (float) $fee['fee'],
                'valor_liquido_pix' => (float) $fee['net'],
            ],
            'confirmacao' => $this->stepUpHint($owner),
            'proximo_passo' => 'Mostre o resumo ao dono da conta. Só chame finance.confirm_withdrawal depois que ele confirmar e informar o código 2FA.',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function previewRefund(array $arguments, int $tenantId): array
    {
        $order = Order::query()->with('product')->where('tenant_id', $tenantId)->find((int) ($arguments['order_id'] ?? 0));
        if ($order === null) {
            throw new McpCatalogException('Pedido não encontrado nesta conta.', 'not_found');
        }
        if (! OrderManualRefund::canManualRefund($order)) {
            throw new McpCatalogException('Este pedido não pode ser reembolsado (status: '.$order->status.').', 'invalid_state');
        }
        $reason = is_string($arguments['reason'] ?? null) ? trim($arguments['reason']) : '';
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new McpCatalogException('Informe o motivo (3 a 500 caracteres).', 'invalid_params');
        }

        $owner = $this->owner($tenantId);
        $stored = $this->previews->put($tenantId, [[
            'kind' => 'refund',
            'order_id' => $order->id,
            'reason' => $reason,
        ]]);

        return [
            'preview_id' => $stored['preview_id'],
            'expires_at' => $stored['expires_at'],
            'resumo' => [
                'o_que_acontece' => 'Reembolsa R$ '.number_format(MoneyDecimal::toFloat($order->amount), 2, ',', '.').' ao comprador (pelo gateway quando suportado), debita o valor do saldo desta conta e revoga o acesso do comprador.',
                'pedido' => [
                    'id' => $order->id,
                    'public_reference' => $order->public_reference,
                    'valor' => MoneyDecimal::toFloat($order->amount),
                    'status' => $order->status,
                    'produto' => $order->product?->name,
                    'criado_em' => $order->created_at?->toIso8601String(),
                ],
                'motivo' => $reason,
            ],
            'confirmacao' => $this->stepUpHint($owner),
            'proximo_passo' => 'Mostre o resumo ao dono da conta. Só chame sales.confirm_refund depois que ele confirmar e informar o código 2FA.',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function confirm(array $arguments, int $tenantId, string $kind): array
    {
        $previewId = is_string($arguments['preview_id'] ?? null) ? trim($arguments['preview_id']) : '';
        $body = $this->previews->get($tenantId, $previewId);
        $op = is_array($body['operations'][0] ?? null) ? $body['operations'][0] : null;
        if ($body === null || $op === null || ($op['kind'] ?? '') !== $kind) {
            throw new McpCatalogException('Prévia inexistente, expirada ou de outro tipo. Gere outra.', 'preview_not_found');
        }
        if (! empty($body['consumed_at'])) {
            return ['ja_executada' => true] + (is_array($body['result'] ?? null) ? $body['result'] : []);
        }

        $owner = $this->owner($tenantId);
        $this->assertStepUp($owner, $arguments);

        $lock = Cache::lock('mcp:seller:confirm:'.$previewId, 120);
        if (! $lock->get()) {
            throw new McpCatalogException('Esta ação já está sendo executada.', 'in_progress');
        }

        try {
            if ($kind === 'withdrawal') {
                $input = array_filter([
                    // pt-BR sem milhar ("1234,50"): o parser do painel lê "." como milhar.
                    'amount' => number_format((float) $op['amount'], 2, ',', ''),
                    'bucket' => (string) $op['bucket'],
                    'notes' => $op['notes'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');
                $result = $this->runner->run($owner, '/financeiro/saque', $input,
                    fn (Request $r) => app()->call([app(SellerFinancialController::class), 'storeWithdrawal'], ['request' => $r]));
            } else {
                $order = Order::query()->where('tenant_id', $tenantId)->findOrFail((int) $op['order_id']);
                $result = $this->runner->run($owner, '/vendas/'.$order->id.'/reembolsar', ['reason' => (string) $op['reason']],
                    fn (Request $r) => app()->call([app(VendasController::class), 'refundManually'], ['request' => $r, 'order' => $order]));
            }

            $payload = [
                'ok' => $result['ok'],
                'mensagem' => $result['message'],
            ];
            if (isset($result['errors'])) {
                $payload['erros'] = $result['errors'];
            }

            // Uso único mesmo em falha: o saque pode ter sido registrado e só o envio automático falhado.
            $this->previews->markConsumed($tenantId, $previewId, $payload);

            return $payload;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function listDisputes(array $arguments, int $tenantId): array
    {
        $status = is_string($arguments['status'] ?? null) ? $arguments['status'] : 'open';
        $query = MedDispute::query()->with('order')->where('tenant_id', $tenantId)->orderByDesc('opened_at');
        $this->medPolicy->scopeDisputesVisibleToSeller($query);
        if ($status === 'open') {
            $query->open();
        } elseif ($status === 'resolved') {
            $query->whereIn('status', [MedDispute::STATUS_RESOLVED_WON, MedDispute::STATUS_RESOLVED_LOST, MedDispute::STATUS_CANCELLED]);
        }

        return [
            'status' => $status,
            'disputes' => $query->limit(100)->get()->map(fn (MedDispute $d) => [
                'id' => $d->id,
                'status' => $d->status,
                'outcome' => $d->outcome,
                'valor' => round(((int) $d->amount_cents) / 100, 2),
                'motivo' => $d->reason,
                'aberta_em' => $d->opened_at?->toIso8601String(),
                'defendida_em' => $d->defended_at?->toIso8601String(),
                'resolvida_em' => $d->resolved_at?->toIso8601String(),
                'pedido' => $d->order ? [
                    'id' => $d->order->id,
                    'public_reference' => $d->order->public_reference,
                    'valor' => MoneyDecimal::toFloat($d->order->amount),
                ] : null,
            ])->values()->all(),
        ];
    }

    /**
     * Dinheiro pelo MCP exige o 2FA do dono da conta: o token pode estar com um assistente
     * ou com um membro da equipe; o código prova que o dono confirmou agora.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function assertStepUp(User $owner, array $arguments): void
    {
        if (! PlatformTotpService::isEnabledFor($owner)) {
            throw new McpCatalogException('Para sacar ou reembolsar pelo MCP, ative o 2FA em Configurações > Segurança do painel.', 'step_up_unavailable');
        }
        $code = preg_replace('/\D/', '', (string) ($arguments['totp_code'] ?? '')) ?? '';
        if ($code === '') {
            throw new McpCatalogException('Informe o código 2FA atual do dono da conta (totp_code).', 'step_up_required');
        }
        if (! PlatformTotpService::verifyCodeForUser($owner, $code)) {
            throw new McpCatalogException('Código 2FA inválido ou expirado.', 'step_up_invalid');
        }
        if (! Cache::add('mcp:seller:totp-used:'.$owner->id.':'.$code, 1, 120)) {
            throw new McpCatalogException('Este código 2FA já foi usado. Espere o próximo.', 'step_up_reused');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function stepUpHint(User $owner): array
    {
        return PlatformTotpService::isEnabledFor($owner)
            ? ['exige' => 'totp_code', 'texto' => 'Peça o código de 6 dígitos do app autenticador do dono da conta.']
            : ['exige' => null, 'texto' => 'A conta não tem 2FA ativo: a confirmação será recusada até ativar em Configurações > Segurança.'];
    }

    private function owner(int $tenantId): User
    {
        $owner = User::query()->where('id', $tenantId)->where('role', User::ROLE_INFOPRODUTOR)->first()
            ?? User::query()->where('tenant_id', $tenantId)->where('role', User::ROLE_INFOPRODUTOR)->first();
        if ($owner === null) {
            throw new McpCatalogException('Titular da conta não encontrado.', 'not_found');
        }

        return $owner;
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

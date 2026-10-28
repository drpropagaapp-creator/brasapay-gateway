<?php

namespace App\Services\Risk;

use App\Models\Order;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * BrasaShield — score de risco explicável, em MODO OBSERVAÇÃO.
 *
 * Roda fora do caminho de pagamento (comando agendado), só com dados que a plataforma já tem
 * (pedidos, reembolsos, MED, saques, datas de cadastro). Não bloqueia nem retém nada: grava o
 * score, os motivos e uma ação SUGERIDA para o admin revisar. Cada sinal é um fato verificável;
 * nenhum usa característica pessoal protegida.
 */
class BrasaShieldService
{
    /** código => [peso, descrição] */
    public const SIGNALS = [
        'velocity_email' => [25, 'Várias compras com o mesmo e-mail em 10 minutos'],
        'velocity_ip' => [20, 'Várias compras do mesmo IP em 10 minutos'],
        'velocity_document' => [25, 'Várias compras com o mesmo CPF/CNPJ em 1 hora'],
        'recent_card_failures' => [20, 'Tentativas de cartão recusadas/falhas com o mesmo e-mail na última hora'],
        'buyer_refund_history' => [30, 'Comprador com reembolsos ou MED anteriores (90 dias)'],
        'ticket_spike' => [20, 'Valor muito acima do ticket médio do seller (30 dias)'],
        'new_seller_high_value' => [20, 'Seller com menos de 7 dias e venda acima de R$ 1.000'],
        'withdrawal_spike' => [30, 'Saque muito acima da média de saques do seller'],
        'withdrawal_new_account' => [25, 'Saque de seller com menos de 7 dias'],
        'withdrawal_frequency' => [20, 'Três ou mais saques no mesmo dia'],
    ];

    public function scan(int $minutes = 30): array
    {
        if (! Schema::hasTable('risk_assessments')) {
            return ['orders' => 0, 'withdrawals' => 0];
        }

        $orders = 0;
        Order::query()
            ->whereIn('status', ['completed', 'pending'])
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->orderBy('id')
            ->limit(2000)
            ->get()
            ->each(function (Order $o) use (&$orders) {
                $this->safely(fn () => $this->assessOrder($o));
                $orders++;
            });

        $withdrawals = 0;
        if (Schema::hasTable('withdrawals')) {
            Withdrawal::query()->where('created_at', '>=', now()->subMinutes($minutes))->orderBy('id')->limit(1000)->get()
                ->each(function (Withdrawal $w) use (&$withdrawals) {
                    $this->safely(fn () => $this->assessWithdrawal($w));
                    $withdrawals++;
                });
        }

        return ['orders' => $orders, 'withdrawals' => $withdrawals];
    }

    public function assessOrder(Order $order): RiskAssessment
    {
        $signals = [];
        $since10 = $order->created_at->copy()->subMinutes(10);
        $email = strtolower(trim((string) $order->email));
        $ip = trim((string) ($order->customer_ip ?? ''));
        $doc = preg_replace('/\D/', '', (string) ($order->cpf ?? '')) ?? '';

        if ($email !== '') {
            $n = Order::query()->where('email', $order->email)->whereBetween('created_at', [$since10, $order->created_at])->count();
            if ($n >= 3) {
                $signals[] = $this->signal('velocity_email', "{$n} pedidos em 10 min");
            }
            $failures = Order::query()->where('email', $order->email)->where('payment_method', 'card')
                ->whereIn('status', ['failed', 'cancelled'])
                ->where('created_at', '>=', $order->created_at->copy()->subHour())->count();
            if ($failures >= 3) {
                $signals[] = $this->signal('recent_card_failures', "{$failures} falhas de cartão em 1 h");
            }
            $history = Order::query()->where('email', $order->email)->where('id', '!=', $order->id)
                ->whereIn('status', ['refunded', 'disputed', 'refund_pending'])
                ->where('created_at', '>=', now()->subDays(90))->count();
            if ($history >= 2) {
                $signals[] = $this->signal('buyer_refund_history', "{$history} pedidos reembolsados/em MED");
            }
        }
        if ($ip !== '') {
            $n = Order::query()->where('customer_ip', $ip)->whereBetween('created_at', [$since10, $order->created_at])->count();
            if ($n >= 5) {
                $signals[] = $this->signal('velocity_ip', "{$n} pedidos em 10 min");
            }
        }
        if (strlen($doc) >= 11) {
            $n = Order::query()->where('cpf', $order->cpf)->where('created_at', '>=', $order->created_at->copy()->subHour())
                ->where('created_at', '<=', $order->created_at)->count();
            if ($n >= 3) {
                $signals[] = $this->signal('velocity_document', "{$n} pedidos em 1 h");
            }
        }

        $amount = (float) $order->amount;
        if ($order->tenant_id) {
            $base = Order::query()->where('tenant_id', $order->tenant_id)->where('status', 'completed')
                ->where('id', '!=', $order->id)->where('created_at', '>=', now()->subDays(30));
            $count = (clone $base)->count();
            $avg = $count >= 10 ? (float) (clone $base)->avg('amount') : 0.0;
            if ($avg > 0 && $amount > $avg * 5 && $amount >= 300) {
                $signals[] = $this->signal('ticket_spike', sprintf('R$ %.2f vs média R$ %.2f', $amount, $avg));
            }
            $seller = User::query()->find($order->tenant_id);
            if ($seller?->created_at && $seller->created_at->gt(now()->subDays(7)) && $amount > 1000) {
                $signals[] = $this->signal('new_seller_high_value', 'Conta criada em '.$seller->created_at->format('d/m/Y'));
            }
        }

        return $this->store('order', (string) $order->id, $order->tenant_id, $amount, $signals);
    }

    public function assessWithdrawal(Withdrawal $w): RiskAssessment
    {
        $signals = [];
        $amount = (float) $w->amount;
        $prev = Withdrawal::query()->where('tenant_id', $w->tenant_id)->where('id', '!=', $w->id)
            ->where('status', 'paid')->where('created_at', '>=', now()->subDays(90));
        $count = (clone $prev)->count();
        $avg = $count >= 3 ? (float) (clone $prev)->avg('amount') : 0.0;
        if ($avg > 0 && $amount > $avg * 3 && $amount >= 500) {
            $signals[] = $this->signal('withdrawal_spike', sprintf('R$ %.2f vs média R$ %.2f', $amount, $avg));
        }
        $seller = User::query()->find($w->tenant_id);
        if ($seller?->created_at && $seller->created_at->gt(now()->subDays(7))) {
            $signals[] = $this->signal('withdrawal_new_account', 'Conta criada em '.$seller->created_at->format('d/m/Y'));
        }
        $today = Withdrawal::query()->where('tenant_id', $w->tenant_id)->whereDate('created_at', $w->created_at->toDateString())->count();
        if ($today >= 3) {
            $signals[] = $this->signal('withdrawal_frequency', "{$today} saques no dia");
        }

        return $this->store('withdrawal', (string) $w->id, $w->tenant_id, $amount, $signals);
    }

    /** @return array{code:string,weight:int,label:string,detail:string} */
    private function signal(string $code, string $detail): array
    {
        [$weight, $label] = self::SIGNALS[$code];

        return ['code' => $code, 'weight' => $weight, 'label' => $label, 'detail' => $detail];
    }

    private function store(string $type, string $id, ?int $tenantId, float $amount, array $signals): RiskAssessment
    {
        $score = min(100, array_sum(array_column($signals, 'weight')));
        $level = $score >= 60 ? 'high' : ($score >= 30 ? 'medium' : 'low');
        $action = ['high' => 'review', 'medium' => 'monitor', 'low' => 'allow'][$level];

        $existing = RiskAssessment::query()->where('subject_type', $type)->where('subject_id', $id)->first();
        $attrs = [
            'tenant_id' => $tenantId,
            'score' => $score,
            'level' => $level,
            'suggested_action' => $action,
            'signals' => $signals,
            'amount_cents' => (int) bcmul(sprintf('%.2F', $amount), '100', 0),
        ];
        if ($existing) {
            // Revisão do admin é preservada; só reabre se o risco subiu de nível.
            $rank = ['low' => 0, 'medium' => 1, 'high' => 2];
            if ($existing->status !== 'open' && $rank[$level] > $rank[$existing->level]) {
                $attrs['status'] = 'open';
            }
            $existing->update($attrs);

            return $existing;
        }

        return RiskAssessment::query()->create($attrs + ['subject_type' => $type, 'subject_id' => $id, 'status' => 'open']);
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('BrasaShield: avaliação falhou', ['message' => $e->getMessage()]);
        }
    }
}

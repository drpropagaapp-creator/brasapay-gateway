<?php

namespace App\Services\Mcp;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\HtmlSanitizer;
use App\Support\MoneyDecimal;
use App\Support\SqlDialect;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class McpSalesService
{
    private const PERIODS = ['hoje', 'ontem', '7dias', 'mes', 'ano', 'total', 'personalizado'];

    private const STATUSES = [
        'completed',
        'pending',
        'refunded',
        'refund_pending',
        'cancelled',
        'disputed',
        'refused',
        'expired',
    ];

    private const STATUS_LABELS = [
        'completed' => 'Pago',
        'pending' => 'Pendente',
        'refunded' => 'Reembolsado',
        'refund_pending' => 'Aguardando reembolso',
        'cancelled' => 'Cancelado',
        'disputed' => 'MED',
        'refused' => 'Recusado',
        'expired' => 'Expirado',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function toolDefinitions(): array
    {
        $period = [
            'type' => 'string',
            'enum' => self::PERIODS,
            'description' => 'Período do relatório. Padrão: 7dias. Use personalizado com date_from e date_to (YYYY-MM-DD).',
        ];

        return [
            $this->tool('sales.summary', 'Resumo de vendas deste tenant para relatórios: receita, ticket, reembolsos, quebra por produto e forma de pagamento. Somente leitura. Não devolve CPF, telefone, e-mail, IP nem dados de gateway.', [
                'type' => 'object',
                'properties' => [
                    'period' => $period,
                    'date_from' => ['type' => 'string', 'description' => 'Início (YYYY-MM-DD), com period=personalizado.'],
                    'date_to' => ['type' => 'string', 'description' => 'Fim (YYYY-MM-DD), com period=personalizado.'],
                    'product_id' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ]),
            $this->tool('sales.list_orders', 'Lista pedidos deste tenant (campos de relatório). Sem PII sensível e sem ações de reembolso. Paginação no máximo 50.', [
                'type' => 'object',
                'properties' => [
                    'period' => $period,
                    'date_from' => ['type' => 'string'],
                    'date_to' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'description' => 'completed, pending, refunded, cancelled, disputed, all. Padrão: completed.'],
                    'product_id' => ['type' => 'string'],
                    'payment_method' => ['type' => 'string', 'enum' => ['pix', 'card', 'boleto', 'all']],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                    'offset' => ['type' => 'integer', 'minimum' => 0],
                ],
                'additionalProperties' => false,
            ]),
            $this->tool('sales.get_order', 'Detalhe de um pedido deste tenant para relatório: itens, valor, status e produto. Não devolve CPF, telefone, e-mail, endereço, IP, metadata nem gateway_id. Não reembolsa.', [
                'type' => 'object',
                'properties' => [
                    'order_id' => ['type' => 'integer', 'description' => 'ID interno do pedido.'],
                    'public_reference' => ['type' => 'string', 'description' => 'Código público do pedido.'],
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
            'sales.summary' => $this->summary($tenantId, $arguments),
            'sales.list_orders' => $this->listOrders($tenantId, $arguments),
            'sales.get_order' => $this->getOrder($tenantId, $arguments),
            default => throw new McpCatalogException('Ferramenta indisponível neste servidor de catálogo.', 'unknown_tool'),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function summary(int $tenantId, array $arguments): array
    {
        [$start, $end, $period] = $this->resolveRange($arguments);
        $query = $this->baseQuery($tenantId, $start, $end, $arguments);

        $completed = (clone $query)->where('status', 'completed');
        $refunded = (clone $query)->where('status', 'refunded');
        $pending = (clone $query)->where('status', 'pending');

        $receita = MoneyDecimal::toFloat($completed->sum('amount'));
        $vendas = (int) $completed->count();
        $reembolsosCount = (int) $refunded->count();
        $reembolsosTotal = MoneyDecimal::toFloat($refunded->sum('amount'));
        $pendingCount = (int) $pending->count();
        $pendingTotal = MoneyDecimal::toFloat($pending->sum('amount'));

        $byStatus = (clone $query)
            ->selectRaw('status, COUNT(*) as quantidade, SUM(amount) as total')
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->map(fn ($row) => [
                'status' => (string) $row->status,
                'status_label' => $this->statusLabel((string) $row->status),
                'quantidade' => (int) $row->quantidade,
                'total' => MoneyDecimal::toFloat($row->total),
            ])
            ->values()
            ->all();

        $byMethod = (clone $query)
            ->where('status', 'completed')
            ->get(['payment_method', 'metadata', 'gateway', 'amount'])
            ->groupBy(fn (Order $order) => $order->paymentMethodReportKey())
            ->map(fn ($rows, $method) => [
                'metodo' => (string) $method,
                'label' => Order::paymentMethodReportLabel((string) $method),
                'quantidade' => $rows->count(),
                'total' => MoneyDecimal::toFloat($rows->sum(fn (Order $order) => (float) $order->amount)),
            ])
            ->sortBy(fn (array $row) => Order::paymentMethodReportSort($row['metodo']))
            ->values()
            ->all();

        $byProductQuery = Order::query()
            ->where('orders.tenant_id', $tenantId)
            ->where('orders.status', 'completed');
        $this->applyRange($byProductQuery, $start, $end, 'orders.created_at');
        $productId = $this->optionalProductId($tenantId, $arguments);
        if ($productId !== null) {
            $byProductQuery->where('orders.product_id', $productId);
        }
        $byProduct = $byProductQuery
            ->join('products', 'orders.product_id', '=', 'products.id')
            ->selectRaw('products.id as product_id, products.name as product_name, SUM(orders.amount) as total, COUNT(*) as quantidade')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'product_id' => (string) $row->product_id,
                'product_name' => $row->product_name,
                'quantidade' => (int) $row->quantidade,
                'total' => MoneyDecimal::toFloat($row->total),
            ])
            ->values()
            ->all();

        $seriesQuery = Order::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'completed');
        $this->applyRange($seriesQuery, $start, $end);
        if ($productId !== null) {
            $seriesQuery->where('product_id', $productId);
        }
        $dateExpr = SqlDialect::dateExpression('created_at');
        $series = $seriesQuery
            ->selectRaw($dateExpr.' as data, SUM(amount) as total, COUNT(*) as quantidade')
            ->groupBy('data')
            ->orderBy('data')
            ->get()
            ->map(fn ($row) => [
                'data' => (string) $row->data,
                'quantidade' => (int) $row->quantidade,
                'total' => MoneyDecimal::toFloat($row->total),
            ])
            ->values()
            ->all();

        return [
            'period' => $period,
            'date_from' => $start,
            'date_to' => $end,
            'receita_total' => $receita,
            'quantidade_vendas' => $vendas,
            'ticket_medio' => $vendas > 0 ? round($receita / $vendas, 2) : 0.0,
            'reembolsos_count' => $reembolsosCount,
            'reembolsos_total' => $reembolsosTotal,
            'pendentes_count' => $pendingCount,
            'pendentes_total' => $pendingTotal,
            'por_status' => $byStatus,
            'por_forma_pagamento' => $byMethod,
            'por_produto' => $byProduct,
            'serie_diaria' => $series,
            'read_only' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function listOrders(int $tenantId, array $arguments): array
    {
        [$start, $end, $period] = $this->resolveRange($arguments);
        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 20)));
        $offset = max(0, (int) ($arguments['offset'] ?? 0));
        $status = $this->normalizeStatus($arguments['status'] ?? 'completed');

        $query = $this->baseQuery($tenantId, $start, $end, $arguments);
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        $method = strtolower(trim((string) ($arguments['payment_method'] ?? 'all')));
        if (in_array($method, ['pix', 'card', 'boleto'], true)) {
            $query->where(function (Builder $builder) use ($method): void {
                $builder->where('payment_method', $method);
            });
        }

        $total = (clone $query)->count();
        $orders = $query
            ->with(['product:id,name', 'productOffer:id,name', 'orderItems:id,order_id,product_id,amount,position'])
            ->orderByDesc('created_at')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(fn (Order $order) => $this->orderSummary($order))
            ->values()
            ->all();

        return [
            'period' => $period,
            'date_from' => $start,
            'date_to' => $end,
            'status' => $status,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'orders' => $orders,
            'read_only' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function getOrder(int $tenantId, array $arguments): array
    {
        $order = $this->orderOfTenant($tenantId, $arguments);
        $order->load([
            'product:id,name',
            'productOffer:id,name',
            'subscriptionPlan:id,name',
            'orderItems.product:id,name',
        ]);

        $summary = $this->orderSummary($order);
        $summary['items'] = $order->orderItems->map(function (OrderItem $item): array {
            return [
                'product_id' => $item->product_id !== null ? (string) $item->product_id : null,
                'product_name' => $item->product?->name,
                'amount' => MoneyDecimal::toFloat($item->amount),
                'position' => (int) ($item->position ?? 0),
                'kind' => ((int) ($item->position ?? 0)) > 0 ? 'order_bump' : 'main',
            ];
        })->values()->all();
        $summary['shipping_amount'] = $order->shipping_amount !== null
            ? MoneyDecimal::toFloat($order->shipping_amount)
            : 0.0;
        $summary['offer_id'] = $order->product_offer_id;
        $summary['is_renewal'] = (bool) $order->is_renewal;
        $summary['read_only'] = true;

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function orderOfTenant(int $tenantId, array $arguments): Order
    {
        $orderId = isset($arguments['order_id']) ? (int) $arguments['order_id'] : 0;
        $reference = strtoupper(trim((string) ($arguments['public_reference'] ?? '')));
        if ($orderId < 1 && $reference === '') {
            throw new McpCatalogException('Informe order_id ou public_reference.', 'validation');
        }

        $query = Order::query()->forTenant($tenantId);
        if ($orderId > 0) {
            $query->whereKey($orderId);
        } else {
            $query->where('public_reference', $reference);
        }
        $order = $query->first();
        if ($order === null) {
            throw new McpCatalogException('Pedido não encontrado neste catálogo.', 'not_found');
        }

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function orderSummary(Order $order): array
    {
        $method = $order->paymentMethodReportKey();

        return [
            'id' => $order->id,
            'public_reference' => $order->public_reference,
            'status' => $order->status,
            'status_label' => $this->statusLabel((string) $order->status),
            'amount' => MoneyDecimal::toFloat($order->amount),
            'payment_method' => $method,
            'payment_method_label' => Order::paymentMethodReportLabel($method),
            'product_id' => $order->product_id !== null ? (string) $order->product_id : null,
            'product_name' => $order->product?->name,
            'offer_name' => $order->productOffer?->name,
            'coupon_code' => $order->coupon_code ? HtmlSanitizer::plainText((string) $order->coupon_code, 64) : null,
            'sale_origin' => $order->sale_origin,
            'created_at' => $order->created_at?->toIso8601String(),
            'has_order_bump' => $order->relationLoaded('orderItems')
                ? $order->orderItems->contains(fn (OrderItem $item) => (int) ($item->position ?? 0) > 0)
                : false,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function baseQuery(int $tenantId, ?string $start, ?string $end, array $arguments): Builder
    {
        $query = Order::query()->forTenant($tenantId);
        $this->applyRange($query, $start, $end);
        $productId = $this->optionalProductId($tenantId, $arguments);
        if ($productId !== null) {
            $query->where('product_id', $productId);
        }

        return $query;
    }

    private function applyRange(Builder $query, ?string $start, ?string $end, string $column = 'created_at'): void
    {
        if ($start && $end) {
            $query->whereBetween($column, [$start, $end]);
        } elseif ($start) {
            $query->where($column, '>=', $start);
        } elseif ($end) {
            $query->where($column, '<=', $end);
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function optionalProductId(int $tenantId, array $arguments): ?string
    {
        $productId = trim((string) ($arguments['product_id'] ?? ''));
        if ($productId === '') {
            return null;
        }
        $exists = Product::query()->forTenant($tenantId)->whereKey($productId)->exists();
        if (! $exists) {
            throw new McpCatalogException('Produto não encontrado neste catálogo.', 'not_found');
        }

        return $productId;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: ?string, 1: ?string, 2: string}
     */
    private function resolveRange(array $arguments): array
    {
        $period = strtolower(trim((string) ($arguments['period'] ?? '7dias')));
        if (! in_array($period, self::PERIODS, true)) {
            throw new McpCatalogException('Período inválido.', 'validation');
        }

        if ($period === 'personalizado') {
            $from = $this->parseDate($arguments['date_from'] ?? null);
            $to = $this->parseDate($arguments['date_to'] ?? null);
            if ($from === null || $to === null) {
                throw new McpCatalogException('Período personalizado exige date_from e date_to (YYYY-MM-DD).', 'validation');
            }
            if ($from->gt($to)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            } else {
                $from = $from->startOfDay();
                $to = $to->endOfDay();
            }
            if ($from->diffInDays($to) > 366) {
                throw new McpCatalogException('O intervalo máximo é de 366 dias.', 'validation');
            }

            return [$from->toDateTimeString(), $to->toDateTimeString(), $period];
        }

        $now = Carbon::now();
        [$start, $end] = match ($period) {
            'hoje' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'ontem' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            '7dias' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            'mes' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'ano' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            default => [null, null],
        };

        return [$start?->toDateTimeString(), $end?->toDateTimeString(), $period];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeStatus(mixed $status): string
    {
        $status = strtolower(trim((string) $status));
        if ($status === '' || $status === 'all') {
            return 'all';
        }
        if (! in_array($status, self::STATUSES, true)) {
            throw new McpCatalogException('Status inválido.', 'validation');
        }

        return $status;
    }

    private function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
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

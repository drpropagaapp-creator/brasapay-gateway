<?php

namespace App\Services\Crm;

use App\Models\CheckoutSession;
use App\Models\CrmContactMeta;
use App\Models\Order;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Brasa CRM — contatos do seller agregados a partir dos PRÓPRIOS pedidos (e-mail normalizado).
 * Escopo sempre pelo tenant (e pelos produtos permitidos, para membro de equipe): o seller nunca
 * vê compras feitas com outro seller.
 */
class CrmService
{
    public const SEGMENTS = [
        'all' => 'Todos os compradores',
        'recent' => 'Compraram nos últimos 30 dias',
        'repeat' => 'Recorrentes (2+ compras)',
        'high_value' => 'Alto valor (R$ 1.000+ pagos)',
        'inactive' => 'Inativos (sem compra há 90+ dias)',
        'unpaid' => 'Geraram pagamento e nunca pagaram',
        'abandoned' => 'Abandonaram o checkout',
        'past_due' => 'Assinatura atrasada',
    ];

    private const PAID = ['completed'];

    /** @param  list<string>|null  $allowedProducts  null = sem restrição (titular) */
    public function contacts(int $tenantId, ?array $allowedProducts, string $segment = 'all', ?string $search = null, ?string $productId = null, int $perPage = 30): LengthAwarePaginator
    {
        if ($segment === 'abandoned') {
            return $this->abandoned($tenantId, $allowedProducts, $search, $perPage);
        }

        $q = $this->ordersScope($tenantId, $allowedProducts)
            ->whereNotNull('email')->where('email', '!=', '')
            ->when($productId, fn ($w) => $w->where('product_id', $productId))
            ->when($search, fn ($w) => $w->where(fn ($s) => $s->whereRaw('LOWER(email) LIKE ?', ['%'.mb_strtolower($search).'%'])))
            ->groupByRaw('LOWER(email)')
            ->selectRaw("LOWER(email) as email,
                MAX(phone) as phone,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as paid_orders,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_paid,
                SUM(CASE WHEN status IN ('refunded','disputed','refund_pending') THEN 1 ELSE 0 END) as refunds,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
                MAX(CASE WHEN status = 'completed' THEN created_at END) as last_paid_at,
                MIN(created_at) as first_seen_at");

        match ($segment) {
            'recent' => $q->havingRaw("MAX(CASE WHEN status = 'completed' THEN created_at END) >= ?", [now()->subDays(30)]),
            'repeat' => $q->havingRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) >= 2"),
            'high_value' => $q->havingRaw("SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) >= 1000"),
            'inactive' => $q->havingRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) >= 1")
                ->havingRaw("MAX(CASE WHEN status = 'completed' THEN created_at END) < ?", [now()->subDays(90)]),
            'unpaid' => $q->havingRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) = 0")
                ->havingRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) >= 1"),
            'past_due' => $q->whereIn(DB::raw('LOWER(email)'), $this->pastDueEmails($tenantId)),
            default => null,
        };

        $page = $q->orderByRaw("MAX(CASE WHEN status = 'completed' THEN created_at END) DESC NULLS LAST")
            ->paginate($perPage)->withQueryString();

        return $this->attachMeta($tenantId, $page);
    }

    /** @return array<string, mixed>|null */
    public function contact(int $tenantId, ?array $allowedProducts, string $email): ?array
    {
        $email = mb_strtolower(trim($email));
        $orders = $this->ordersScope($tenantId, $allowedProducts)->whereRaw('LOWER(email) = ?', [$email])
            ->with('product:id,name')->latest('id')->limit(100)->get();
        if ($orders->isEmpty()) {
            return null;
        }
        $paid = $orders->where('status', 'completed');
        $meta = CrmContactMeta::query()->where('tenant_id', $tenantId)->where('email', $email)->first();

        return [
            'email' => $email,
            'name' => (string) ($orders->first()->user?->name ?? ''),
            'phone' => (string) ($orders->first(fn ($o) => ! empty($o->phone))?->phone ?? ''),
            'paid_orders' => $paid->count(),
            'total_paid' => round((float) $paid->sum('amount'), 2),
            'avg_ticket' => $paid->count() ? round((float) $paid->avg('amount'), 2) : 0,
            'first_seen_at' => $orders->min('created_at')?->toIso8601String(),
            'last_paid_at' => $paid->max('created_at')?->toIso8601String(),
            'products' => $paid->map(fn ($o) => $o->product?->name)->filter()->unique()->values(),
            'orders' => $orders->map(fn (Order $o) => [
                'id' => $o->id, 'status' => $o->status, 'amount' => (float) $o->amount, 'payment_method' => $o->payment_method,
                'product' => $o->product?->name, 'created_at' => $o->created_at?->toIso8601String(),
                'utm_source' => is_array($o->metadata) ? ($o->metadata['utm_source'] ?? null) : null,
            ])->values(),
            'subscriptions' => Subscription::query()->where('tenant_id', $tenantId)
                ->whereHas('user', fn ($u) => $u->whereRaw('LOWER(email) = ?', [$email]))
                ->with('product:id,name')->get()
                ->map(fn ($s) => ['product' => $s->product?->name, 'status' => $s->status, 'current_period_end' => $s->current_period_end?->toDateString()])->values(),
            'tags' => $meta?->tags ?? [],
            'notes' => $meta?->notes ?? '',
            'recovery_opted_out' => \App\Models\RecoveryOptOut::isOptedOut($tenantId, $email),
        ];
    }

    public function saveMeta(int $tenantId, string $email, array $tags, ?string $notes, int $userId): CrmContactMeta
    {
        $tags = array_values(array_unique(array_filter(array_map(fn ($t) => mb_substr(trim(strip_tags((string) $t)), 0, 30), $tags))));

        return CrmContactMeta::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'email' => mb_strtolower(trim($email))],
            ['tags' => array_slice($tags, 0, 10), 'notes' => mb_substr((string) $notes, 0, 5000), 'updated_by' => $userId],
        );
    }

    private function ordersScope(int $tenantId, ?array $allowedProducts): Builder
    {
        return Order::query()->where('tenant_id', $tenantId)
            ->when($allowedProducts !== null, fn ($q) => $q->whereIn('product_id', $allowedProducts ?: ['__none__']));
    }

    /** @return list<string> */
    private function pastDueEmails(int $tenantId): array
    {
        return Subscription::query()->where('tenant_id', $tenantId)->where('status', Subscription::STATUS_PAST_DUE)
            ->join('users', 'users.id', '=', 'subscriptions.user_id')
            ->pluck(DB::raw('LOWER(users.email)'))->filter()->unique()->values()->all();
    }

    private function abandoned(int $tenantId, ?array $allowedProducts, ?string $search, int $perPage): LengthAwarePaginator
    {
        $buyers = $this->ordersScope($tenantId, $allowedProducts)->where('status', 'completed')->whereNotNull('email')
            ->selectRaw('LOWER(email)');
        $page = CheckoutSession::query()->where('tenant_id', $tenantId)
            ->whereIn('step', [CheckoutSession::STEP_FORM_STARTED, CheckoutSession::STEP_FORM_FILLED])
            ->whereNull('order_id')->whereNotNull('email')->where('email', '!=', '')
            ->when($allowedProducts !== null, fn ($q) => $q->whereIn('product_id', $allowedProducts ?: ['__none__']))
            ->when($search, fn ($w) => $w->whereRaw('LOWER(email) LIKE ?', ['%'.mb_strtolower($search).'%']))
            ->whereRaw('LOWER(email) NOT IN ('.$buyers->toSql().')', $buyers->getBindings())
            ->groupByRaw('LOWER(email)')
            ->selectRaw('LOWER(email) as email, MAX(phone) as phone, 0 as paid_orders, 0 as total_paid, 0 as refunds, COUNT(*) as pending_orders, NULL as last_paid_at, MIN(created_at) as first_seen_at')
            ->orderByRaw('MAX(updated_at) DESC')
            ->paginate($perPage)->withQueryString();

        return $this->attachMeta($tenantId, $page);
    }

    private function attachMeta(int $tenantId, LengthAwarePaginator $page): LengthAwarePaginator
    {
        $emails = collect($page->items())->pluck('email')->all();
        $meta = CrmContactMeta::query()->where('tenant_id', $tenantId)->whereIn('email', $emails)->get()->keyBy('email');
        $page->getCollection()->transform(function ($row) use ($meta) {
            return [
                'email' => $row->email,
                'phone' => $row->phone,
                'paid_orders' => (int) $row->paid_orders,
                'total_paid' => round((float) $row->total_paid, 2),
                'refunds' => (int) $row->refunds,
                'pending_orders' => (int) $row->pending_orders,
                'last_paid_at' => $row->last_paid_at ? \Illuminate\Support\Carbon::parse($row->last_paid_at)->toIso8601String() : null,
                'first_seen_at' => $row->first_seen_at ? \Illuminate\Support\Carbon::parse($row->first_seen_at)->toIso8601String() : null,
                'tags' => $meta->get($row->email)?->tags ?? [],
            ];
        });

        return $page;
    }
}

<?php

namespace App\Models;

use App\Services\Coproduction\RevenueSplitCalculator;
use App\Support\MoneyDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ProductCoproducer extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    public const DURATION_ETERNAL = 'eternal';

    /** @var list<string> */
    public const DURATION_DAYS = ['30', '60', '90', '120'];

    protected $fillable = [
        'product_id',
        'inviter_user_id',
        'co_producer_user_id',
        'email',
        'status',
        'token',
        'commission_percent',
        'commission_on_direct_sales',
        'commission_on_affiliate_sales',
        'duration_preset',
        'starts_at',
        'ends_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'commission_percent' => 'decimal:2',
            'commission_on_direct_sales' => 'boolean',
            'commission_on_affiliate_sales' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_user_id');
    }

    public function coProducer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'co_producer_user_id');
    }

    public function isActiveNow(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE || $this->co_producer_user_id === null) {
            return false;
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }
        if ($this->ends_at && $this->ends_at->lte(now())) {
            return false;
        }

        return true;
    }

    /**
     * Marca como expiradas as co-produções ativas cujo prazo (ends_at) já passou.
     * Não apaga o registro: o histórico de comissões depende do vínculo.
     */
    public static function expireOverdue(): int
    {
        if (! Schema::hasTable('product_coproducers')) {
            return 0;
        }

        return (int) static::query()
            ->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->update(['status' => self::STATUS_EXPIRED]);
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function isActiveCoproducerOf(int $userId, string $productId): bool
    {
        if ($userId < 1 || $productId === '') {
            return false;
        }

        $row = static::query()
            ->where('product_id', $productId)
            ->where('co_producer_user_id', $userId)
            ->where('status', self::STATUS_ACTIVE)
            ->first();

        return $row instanceof self && $row->isActiveNow();
    }

    /**
     * @return list<string>
     */
    public static function activeProductIdsForUser(int $userId): array
    {
        if ($userId < 1) {
            return [];
        }

        return static::query()
            ->where('co_producer_user_id', $userId)
            ->where('status', self::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->pluck('product_id')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    public static function userHasParticipations(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        return static::query()
            ->where('co_producer_user_id', $userId)
            ->whereIn('status', [
                self::STATUS_ACTIVE,
                self::STATUS_REVOKED,
                self::STATUS_EXPIRED,
            ])
            ->exists();
    }

    public function applyAcceptance(User $user): void
    {
        $starts = now();
        $ends = null;
        if ($this->duration_preset !== self::DURATION_ETERNAL && in_array($this->duration_preset, self::DURATION_DAYS, true)) {
            $ends = $starts->copy()->addDays((int) $this->duration_preset);
        }

        $this->update([
            'status' => self::STATUS_ACTIVE,
            'co_producer_user_id' => $user->id,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'accepted_at' => $starts,
        ]);
    }

    /**
     * Após cadastro via convite: ativa se token e e-mail batem.
     */
    public static function tryActivateAfterRegistration(User $user, ?string $token): bool
    {
        if ($token === null || trim($token) === '' || ! $user->isInfoprodutor()) {
            return false;
        }

        self::expireOverdue();

        $invitation = self::query()
            ->where('token', $token)
            ->where('status', self::STATUS_PENDING)
            ->with('product')
            ->first();

        if ($invitation === null) {
            return false;
        }

        if (self::normalizeEmail((string) $user->email) !== $invitation->email) {
            return false;
        }

        $productTenantId = (int) ($invitation->product?->tenant_id ?? 0);
        if ($productTenantId === (int) $user->tenant_id) {
            return false;
        }

        if (self::query()
            ->where('product_id', $invitation->product_id)
            ->where('co_producer_user_id', $user->id)
            ->where('status', self::STATUS_ACTIVE)
            ->where('id', '!=', $invitation->id)
            ->exists()) {
            return false;
        }

        $invitation->applyAcceptance($user);

        return true;
    }

    /**
     * Tenant da carteira do co-produtor — o mesmo critério de CoproductionCommissionQuery::tenantIdForUser.
     */
    public static function walletTenantIdFor(self $row): int
    {
        $user = $row->relationLoaded('coProducer')
            ? $row->coProducer
            : ($row->co_producer_user_id ? User::query()->find((int) $row->co_producer_user_id) : null);

        if ($user instanceof User) {
            return (int) ($user->tenant_id ?? $user->id);
        }

        return (int) $row->co_producer_user_id;
    }

    /**
     * Fatias do bruto no formato consumido pela carteira (valores em reais).
     *
     * @return array<int, array{tenant_id: int, gross: float, gross_cents: int, product_coproducer_id: int|null, role: string}>
     */
    public static function buildGrossSlicesForOrder(Order $order, float $grossTotal, bool $isAffiliateSale): array
    {
        return self::slicesToLegacyShape(self::computeSplitForOrder($order, $grossTotal, $isAffiliateSale)['slices']);
    }

    /**
     * Divisão completa (centavos, versão da regra e problemas encontrados) —
     * é o que vai para o snapshot imutável do pedido.
     *
     * @return array{version: int, total_cents: int, slices: list<array<string, mixed>>, issues: list<array<string, mixed>>}
     */
    public static function computeSplitForOrder(Order $order, float $grossTotal, bool $isAffiliateSale): array
    {
        $sellerTenantId = (int) $order->tenant_id;
        $totalCents = MoneyDecimal::toCents($grossTotal);

        if ($sellerTenantId < 1 || $totalCents <= 0 || $order->product_id === null || $order->product_id === '') {
            return RevenueSplitCalculator::split($sellerTenantId, [
                ['product_id' => null, 'cents' => max(0, $totalCents), 'coproducers' => []],
            ]);
        }

        static::expireOverdue();

        $bases = [];
        foreach (self::revenueBasesForOrder($order, $totalCents) as $productId => $cents) {
            $bases[] = [
                'product_id' => $productId,
                'cents' => $cents,
                'coproducers' => self::eligibleCoproducersForBase($order, $productId, $isAffiliateSale),
            ];
        }

        $affiliate = null;
        if ($isAffiliateSale) {
            $affSlice = self::affiliateGrossSliceFromOrder($order, $grossTotal);
            if ($affSlice !== null) {
                $affiliate = [
                    'tenant_id' => (int) $affSlice['tenant_id'],
                    'cents' => (int) $affSlice['gross_cents'],
                    'product_affiliate_enrollment_id' => (int) $affSlice['product_affiliate_enrollment_id'],
                    'basis_points' => (int) $affSlice['basis_points'],
                ];
            }
        }

        $result = RevenueSplitCalculator::split($sellerTenantId, $bases, $affiliate);

        if ($result['issues'] !== []) {
            Log::warning('ProductCoproducer: split com ressalvas', [
                'order_id' => $order->id,
                'issues' => $result['issues'],
            ]);
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $slices
     * @return array<int, array<string, mixed>>
     */
    public static function slicesToLegacyShape(array $slices): array
    {
        return array_map(function (array $s) {
            $bp = (int) ($s['basis_points'] ?? 0);

            return [
                'tenant_id' => (int) $s['tenant_id'],
                'gross' => MoneyDecimal::fromCents((int) $s['gross_cents']),
                'gross_cents' => (int) $s['gross_cents'],
                'product_coproducer_id' => $s['product_coproducer_id'] ?? null,
                'product_coproducer_ids' => $s['product_coproducer_ids'] ?? [],
                'role' => (string) $s['role'],
                'product_affiliate_enrollment_id' => $s['product_affiliate_enrollment_id'] ?? null,
                'commission_percent' => $bp > 0 ? $bp / 100 : null,
            ];
        }, $slices);
    }

    /**
     * Receita do pedido por produto, em centavos. A base do principal recebe o
     * que não for identificável por item; pedidos antigos sem itens (ou cujos
     * itens não fecham com o total) ficam inteiros no produto principal.
     *
     * @return array<string, int>
     */
    private static function revenueBasesForOrder(Order $order, int $totalCents): array
    {
        $mainId = (string) $order->product_id;
        $order->loadMissing('orderItems');

        $bases = [];
        foreach ($order->orderItems as $item) {
            $pid = $item->product_id !== null && $item->product_id !== '' ? (string) $item->product_id : $mainId;
            $bases[$pid] = ($bases[$pid] ?? 0) + MoneyDecimal::toCents($item->amount ?? 0);
        }

        if ($bases === [] || array_sum($bases) !== $totalCents) {
            return [$mainId => $totalCents];
        }

        // Itens de produto de outro tenant não carregam co-produtores deste pedido.
        $foreign = [];
        foreach (array_keys($bases) as $pid) {
            if ($pid === $mainId) {
                continue;
            }
            $productTenant = Product::query()->whereKey($pid)->value('tenant_id');
            if ($productTenant === null || (int) $productTenant !== (int) $order->tenant_id) {
                $foreign[] = $pid;
            }
        }
        foreach ($foreign as $pid) {
            $bases[$mainId] = ($bases[$mainId] ?? 0) + $bases[$pid];
            unset($bases[$pid]);
        }

        return $bases;
    }

    /**
     * @return list<array{tenant_id: int, basis_points: int, product_coproducer_id: int}>
     */
    private static function eligibleCoproducersForBase(Order $order, string $productId, bool $isAffiliateSale): array
    {
        $rows = static::query()
            ->with(['coProducer:id,tenant_id'])
            ->where('product_id', $productId)
            ->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('co_producer_user_id')
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (self $row) => (string) $row->product_id === $productId)
            ->filter(fn (self $row) => $isAffiliateSale ? (bool) $row->commission_on_affiliate_sales : (bool) $row->commission_on_direct_sales);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'tenant_id' => self::walletTenantIdFor($row),
                'basis_points' => MoneyDecimal::percentToBasisPoints($row->commission_percent),
                'product_coproducer_id' => (int) $row->id,
            ];
        }

        return $out;
    }

    /**
     * @return array{tenant_id: int, gross: float, product_coproducer_id: null, role: string, product_affiliate_enrollment_id: int}|null
     */
    public static function affiliateGrossSliceFromOrder(Order $order, float $grossTotal): ?array
    {
        $meta = $order->metadata ?? [];
        if (! is_array($meta) || empty($meta['affiliate_enrollment_id'])) {
            return null;
        }

        $enrollment = ProductAffiliateEnrollment::query()->find((int) $meta['affiliate_enrollment_id']);
        if ($enrollment === null || $enrollment->status !== ProductAffiliateEnrollment::STATUS_APPROVED) {
            return null;
        }

        $productId = $order->product_id;
        if ($productId === null || $productId === '') {
            return null;
        }
        // Comparar como string: orders.product_id e enrollments.product_id podem vir como int (SQLite) ou char(36).
        if ((string) $enrollment->product_id !== (string) $productId) {
            return null;
        }

        if (! empty($meta['affiliate_user_id']) && (int) $meta['affiliate_user_id'] !== (int) $enrollment->affiliate_user_id) {
            return null;
        }

        $product = Product::query()->find($productId);
        if ($product === null || ! $product->affiliate_enabled) {
            return null;
        }

        $pct = (float) $product->affiliate_commission_percent;
        if ($pct <= 0) {
            return null;
        }

        $sellerTenantId = (int) $order->tenant_id;
        $affiliateUser = $enrollment->relationLoaded('affiliate')
            ? $enrollment->affiliate
            : $enrollment->affiliate()->first();
        $affiliateTenantId = (int) ($affiliateUser?->tenant_id ?? $enrollment->affiliate_user_id);
        // Mesmo tenant = auto-afiliação / conta própria: não paga comissão (evita “transferir” saldo para si).
        if ($affiliateTenantId === $sellerTenantId) {
            return null;
        }

        // Inteiros com arredondamento half-up nos centavos (mesma regra do round(…, 2) antigo, sem float).
        $bp = MoneyDecimal::percentToBasisPoints($pct);
        $cents = intdiv(MoneyDecimal::toCents($grossTotal) * $bp + 5000, 10000);
        if ($cents <= 0) {
            return null;
        }

        return [
            'tenant_id' => $affiliateTenantId,
            'gross' => MoneyDecimal::fromCents($cents),
            'gross_cents' => $cents,
            'basis_points' => $bp,
            'product_coproducer_id' => null,
            'role' => 'affiliate',
            'product_affiliate_enrollment_id' => (int) $enrollment->id,
        ];
    }
}

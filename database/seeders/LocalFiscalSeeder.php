<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Support\WalletCreditReference;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Volume fictício para o relatório Fiscal (competência do ledger).
 *
 * Uso: php artisan db:seed --class=LocalFiscalSeeder --force
 *
 * Senha dos infoprodutores/clientes: password
 * Admin: admin@admin.com / 12345678
 */
class LocalFiscalSeeder extends Seeder
{
    private const PASSWORD = 'password';

    private const EMAIL_DOMAIN = 'fiscal.stacker.local';

    private const SEED = 'local_fiscal';

    /** @var list<array{name: string, company: string, trade: string, pj: bool}> */
    private const SELLERS = [
        ['name' => 'João Silva', 'company' => 'João Silva Educação LTDA', 'trade' => 'JS Academy', 'pj' => true],
        ['name' => 'Maria Oliveira', 'company' => 'Oliveira Infoprodutos LTDA', 'trade' => 'MO Digital', 'pj' => true],
        ['name' => 'Pedro Santos', 'company' => '', 'trade' => 'Pedro Mentoria', 'pj' => false],
        ['name' => 'Ana Costa', 'company' => 'Costa & Filhos Cursos LTDA', 'trade' => 'Costa Cursos', 'pj' => true],
        ['name' => 'Lucas Pereira', 'company' => '', 'trade' => '', 'pj' => false],
        ['name' => 'Fernanda Lima', 'company' => 'Lima School LTDA', 'trade' => 'Lima School', 'pj' => true],
        ['name' => 'Ricardo Alves', 'company' => 'Alves Digital LTDA', 'trade' => 'Alves Pay', 'pj' => true],
        ['name' => 'Camila Rocha', 'company' => '', 'trade' => 'Camila Copy', 'pj' => false],
        ['name' => 'Bruno Carvalho', 'company' => 'Carvalho Hub LTDA', 'trade' => 'Hub do Bruno', 'pj' => true],
        ['name' => 'Juliana Martins', 'company' => 'JM Infoprodutos LTDA', 'trade' => 'JM Pro', 'pj' => true],
        ['name' => 'Thiago Mendes', 'company' => '', 'trade' => '', 'pj' => false],
        ['name' => 'Patrícia Nunes', 'company' => 'Nunes Educação LTDA', 'trade' => 'Nunes Edu', 'pj' => true],
        ['name' => 'Felipe Barbosa', 'company' => 'Barbosa Tráfego LTDA', 'trade' => 'Tráfego FB', 'pj' => true],
        ['name' => 'Aline Souza', 'company' => '', 'trade' => 'Aline Finanças', 'pj' => false],
        ['name' => 'Gustavo Ribeiro', 'company' => 'Ribeiro Labs LTDA', 'trade' => 'G Labs', 'pj' => true],
        ['name' => 'Beatriz Freitas', 'company' => 'Freitas Academy LTDA', 'trade' => 'BF Academy', 'pj' => true],
        ['name' => 'André Teixeira', 'company' => '', 'trade' => '', 'pj' => false],
        ['name' => 'Larissa Campos', 'company' => 'Campos Cursos LTDA', 'trade' => 'Campos Pro', 'pj' => true],
        ['name' => 'Diego Araujo', 'company' => 'Araujo Media LTDA', 'trade' => 'DA Media', 'pj' => true],
        ['name' => 'Renata Pinto', 'company' => '', 'trade' => 'Renata PIX', 'pj' => false],
        ['name' => 'Marcelo Vieira', 'company' => 'Vieira Scale LTDA', 'trade' => 'Scale MV', 'pj' => true],
        ['name' => 'Sofia Castro', 'company' => 'Castro Design LTDA', 'trade' => 'Castro UI', 'pj' => true],
        ['name' => 'Rafael Moreira', 'company' => '', 'trade' => '', 'pj' => false],
        ['name' => 'Helena Dias', 'company' => 'Dias Mentoria LTDA', 'trade' => 'Dias 7D', 'pj' => true],
        ['name' => 'Vinicius Lopes', 'company' => 'Lopes Ads LTDA', 'trade' => 'Lopes Ads', 'pj' => true],
        ['name' => 'Carolina Duarte', 'company' => '', 'trade' => 'Carol Nutri', 'pj' => false],
        ['name' => 'Eduardo Cunha', 'company' => 'Cunha Code LTDA', 'trade' => 'Cunha Dev', 'pj' => true],
        ['name' => 'Isabela Monteiro', 'company' => 'Monteiro English LTDA', 'trade' => 'IM English', 'pj' => true],
        ['name' => 'Paulo Henrique', 'company' => '', 'trade' => '', 'pj' => false],
        ['name' => 'Vanessa Gomes', 'company' => 'Gomes Yoga LTDA', 'trade' => 'Yoga VG', 'pj' => true],
        ['name' => 'Leandro Batista', 'company' => 'Batista Funil LTDA', 'trade' => 'Funil LB', 'pj' => true],
        ['name' => 'Natália Prado', 'company' => 'Prado Beauty LTDA', 'trade' => 'Prado Beauty', 'pj' => true],
        ['name' => 'Igor Fernandes', 'company' => '', 'trade' => 'Igor Imob', 'pj' => false],
        ['name' => 'Priscila Ramos', 'company' => 'Ramos Kids LTDA', 'trade' => 'Ramos Kids', 'pj' => true],
    ];

    /** @var list<float> */
    private const PRICES = [47.90, 67.00, 97.00, 147.00, 197.00, 297.00, 497.00, 997.00, 1497.00, 2970.00];

    public function run(): void
    {
        mt_srand(20260910);

        $this->purgePrevious();
        $this->upsertAdmin();

        $now = Carbon::now();
        $sellers = [];
        foreach (self::SELLERS as $i => $spec) {
            $sellers[] = $this->upsertSeller($i, $spec);
        }

        $customers = [];
        for ($i = 0; $i < 24; $i++) {
            $customers[] = $this->upsertCustomer($i);
        }

        $products = [];
        foreach ($sellers as $i => $seller) {
            $products[$seller->id] = $this->upsertProduct($seller, $i);
        }

        $coproducer = $sellers[1];
        $stats = [
            'orders' => 0,
            'refunds' => 0,
            'pending_settlement' => 0,
            'withdrawals' => 0,
        ];

        foreach ($sellers as $i => $seller) {
            $product = $products[$seller->id];
            $feeRate = [0.0399, 0.0449, 0.0499, 0.0549, 0.0599][$i % 5];
            $fixed = in_array($i % 5, [2, 4], true) ? 0.39 : 0.0;
            $seller->forceFill([
                'merchant_fees' => [
                    'pix' => ['percent' => round($feeRate * 100, 2), 'fixed' => $fixed],
                    'card' => ['percent' => round($feeRate * 100 + 1, 2), 'fixed' => 0.39],
                    'withdrawal' => ['percent' => 0, 'fixed' => 3.50],
                ],
            ])->save();

            $currentCount = 8 + ($i % 7) * 3 + ($i < 6 ? 18 : 0);
            $augustCount = 4 + ($i % 5) * 2;
            $julyCount = 2 + ($i % 3);

            $stats['orders'] += $this->seedMonthSales(
                $seller,
                $product,
                $customers,
                $currentCount,
                $now->copy()->startOfMonth()->addDays(1),
                $now->copy()->min(Carbon::now()),
                $feeRate,
                $fixed,
                $i,
                $coproducer
            );
            $stats['orders'] += $this->seedMonthSales(
                $seller,
                $product,
                $customers,
                $augustCount,
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
                $feeRate,
                $fixed,
                $i + 40,
                null
            );
            $stats['orders'] += $this->seedMonthSales(
                $seller,
                $product,
                $customers,
                $julyCount,
                $now->copy()->subMonthsNoOverflow(2)->startOfMonth(),
                $now->copy()->subMonthsNoOverflow(2)->endOfMonth(),
                $feeRate + 0.01,
                $fixed,
                $i + 80,
                null
            );

            $stats['refunds'] += $this->seedRefunds($seller, $product, $customers, $feeRate, $fixed, $now);
            $stats['pending_settlement'] += $this->seedPendingAndRelease($seller, $product, $customers[$i % count($customers)], $feeRate, $fixed, $now);
            $stats['orders'] += $this->seedBoletoCrossMonth($seller, $product, $customers[($i + 3) % count($customers)], $feeRate, $fixed, $now);
            $this->seedWithdrawals($seller, $i, $now);
            $stats['withdrawals'] += $i % 7 === 0 ? 5 : 3;
        }

        $this->command?->info('Fiscal local OK. Infoprodutores, vendas, reembolsos e saques inseridos.');
        $this->command?->table(
            ['Métrica', 'Qtd'],
            [
                ['Infoprodutores', (string) count($sellers)],
                ['Pedidos gerados (aprox.)', (string) $stats['orders']],
                ['Reembolsos com taxa retida', (string) $stats['refunds']],
                ['Casos D+N (pending + liquidação)', (string) $stats['pending_settlement']],
            ]
        );
        $this->command?->info('Admin: admin@admin.com / 12345678');
        $this->command?->info('Sellers: *@'.self::EMAIL_DOMAIN.' / password');
        $this->command?->info('Abrir: /plataforma/fiscal  (mês atual por padrão)');
    }

    private function purgePrevious(): void
    {
        $sellerIds = User::query()
            ->where('email', 'like', '%@'.self::EMAIL_DOMAIN)
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->pluck('id');
        $customerIds = User::query()
            ->where('email', 'like', '%@'.self::EMAIL_DOMAIN)
            ->whereIn('role', User::buyerRoleValues())
            ->pluck('id');

        $orderIds = Order::query()
            ->where(function ($q) use ($sellerIds, $customerIds) {
                if ($sellerIds->isNotEmpty()) {
                    $q->orWhereIn('tenant_id', $sellerIds);
                }
                if ($customerIds->isNotEmpty()) {
                    $q->orWhereIn('user_id', $customerIds);
                }
                $q->orWhere('metadata->seed', self::SEED);
            })
            ->pluck('id');

        if ($orderIds->isNotEmpty()) {
            if (Schema::hasTable('wallet_transactions')) {
                WalletTransaction::query()->whereIn('order_id', $orderIds)->delete();
            }
            if (Schema::hasTable('order_items')) {
                OrderItem::query()->whereIn('order_id', $orderIds)->delete();
            }
            Order::query()->whereIn('id', $orderIds)->delete();
        }

        if ($sellerIds->isNotEmpty() && Schema::hasTable('withdrawals')) {
            $wIds = Withdrawal::query()->whereIn('tenant_id', $sellerIds)->pluck('id');
            if ($wIds->isNotEmpty() && Schema::hasTable('wallet_transactions')) {
                WalletTransaction::query()->whereIn('withdrawal_id', $wIds)->delete();
            }
            Withdrawal::query()->whereIn('tenant_id', $sellerIds)->delete();
        }

        if ($sellerIds->isNotEmpty() && Schema::hasTable('tenant_wallets')) {
            TenantWallet::query()->whereIn('tenant_id', $sellerIds)->delete();
        }
        if ($sellerIds->isNotEmpty()) {
            Product::withTrashed()->whereIn('tenant_id', $sellerIds)->forceDelete();
        }

        User::query()->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->delete();
    }

    private function upsertAdmin(): User
    {
        return User::query()->updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin Local',
                'password' => Hash::make('12345678'),
                'role' => User::ROLE_PLATFORM_ADMIN,
                'tenant_id' => null,
                'account_status' => 'approved',
                'email_verified_at' => now(),
            ]
        )->fresh();
    }

    /**
     * @param  array{name: string, company: string, trade: string, pj: bool}  $spec
     */
    private function upsertSeller(int $i, array $spec): User
    {
        $slug = Str::slug($spec['name']).'.'.$i;
        $attrs = [
            'name' => $spec['name'],
            'password' => Hash::make(self::PASSWORD),
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'person_type' => $spec['pj'] ? 'pj' : 'pf',
            'phone' => '1199'.str_pad((string) (2000000 + $i), 7, '0', STR_PAD_LEFT),
            'document' => $spec['pj']
                ? $this->fakeCnpj($i)
                : $this->fakeCpf(100 + $i),
            'company_name' => $spec['company'] !== '' ? $spec['company'] : null,
            'email_verified_at' => now()->subDays(20),
        ];
        if (Schema::hasColumn('users', 'trade_name')) {
            $attrs['trade_name'] = $spec['trade'] !== '' ? $spec['trade'] : null;
        }
        if (Schema::hasColumn('users', 'kyc_status')) {
            $attrs['kyc_status'] = User::KYC_APPROVED;
        }
        if (Schema::hasColumn('users', 'seller_onboarded_at')) {
            $attrs['seller_onboarded_at'] = now()->subDays(18);
        }

        $user = User::query()->updateOrCreate(
            ['email' => $slug.'@'.self::EMAIL_DOMAIN],
            $attrs
        );
        $user->forceFill(['tenant_id' => $user->id])->save();

        return $user->fresh();
    }

    private function upsertCustomer(int $i): User
    {
        return User::query()->updateOrCreate(
            ['email' => 'cliente.'.$i.'@'.self::EMAIL_DOMAIN],
            [
                'name' => 'Cliente Fiscal '.$i,
                'password' => Hash::make(self::PASSWORD),
                'role' => User::ROLE_CLIENTE,
                'tenant_id' => null,
                'account_status' => 'approved',
                'person_type' => 'pf',
                'phone' => '2198'.str_pad((string) (3000000 + $i), 7, '0', STR_PAD_LEFT),
                'document' => $this->fakeCpf(200 + $i),
                'email_verified_at' => now(),
            ]
        )->fresh();
    }

    private function upsertProduct(User $seller, int $i): Product
    {
        $payload = [
            'tenant_id' => $seller->id,
            'name' => 'Produto Fiscal '.($i + 1),
            'slug' => 'fiscal-prod-'.$i,
            'type' => Product::TYPE_LINK,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => self::PRICES[$i % count(self::PRICES)],
            'currency' => 'BRL',
            'is_active' => true,
            'description' => 'Produto fictício do seeder Fiscal.',
        ];
        if (Schema::hasColumn('products', 'approval_status')) {
            $payload['approval_status'] = Product::APPROVAL_APPROVED;
            $payload['approval_source'] = Product::APPROVAL_SOURCE_MANUAL;
        }

        $existing = Product::withTrashed()
            ->where('tenant_id', $seller->id)
            ->where('slug', $payload['slug'])
            ->first();
        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->forceFill($payload)->save();

            return $existing->fresh();
        }

        $product = new Product;
        $product->forceFill($payload);
        $product->save();

        return $product->fresh();
    }

    /**
     * @param  list<User>  $customers
     */
    private function seedMonthSales(
        User $seller,
        Product $product,
        array $customers,
        int $count,
        Carbon $from,
        Carbon $to,
        float $feeRate,
        float $fixed,
        int $salt,
        ?User $coproducer,
    ): int {
        $created = 0;
        $span = max(1, $to->getTimestamp() - $from->getTimestamp());
        for ($n = 0; $n < $count; $n++) {
            $at = Carbon::createFromTimestamp($from->getTimestamp() + (int) (($span / max(1, $count)) * $n) + ($n * 37));
            if ($at->gt($to)) {
                $at = $to->copy();
            }
            $price = self::PRICES[($salt + $n) % count(self::PRICES)];
            $method = ['pix', 'pix', 'card', 'pix', 'boleto'][$n % 5];
            $customer = $customers[($salt + $n) % count($customers)];
            $doSplit = $coproducer !== null && $n % 11 === 0 && $seller->id !== $coproducer->id;

            $order = $this->createOrder($customer, $seller, $product, $price, $method, $at, 'completed');
            if ($doSplit) {
                $ownerGross = round($price * 0.7, 2);
                $coGross = round($price - $ownerGross, 2);
                $this->creditOriginal($seller, $order, $ownerGross, $this->fee($ownerGross, $feeRate, $fixed), $method, $at, 'sale');
                $this->creditOriginal($coproducer, $order, $coGross, $this->fee($coGross, 0.0499, 0.39), $method, $at, 'sale');
            } else {
                $this->creditOriginal($seller, $order, $price, $this->fee($price, $feeRate, $fixed), $method, $at, 'sale');
            }
            $created++;
        }

        return $created;
    }

    private function seedRefunds(User $seller, Product $product, array $customers, float $feeRate, float $fixed, Carbon $now): int
    {
        $count = 0;
        foreach ([3, 7] as $offset) {
            $paidAt = $now->copy()->startOfMonth()->addDays(min(8, $now->day))->addHours($offset);
            $price = 297.00;
            $order = $this->createOrder($customers[$offset % count($customers)], $seller, $product, $price, 'pix', $paidAt, 'completed');
            $fee = $this->fee($price, $feeRate, $fixed);
            $this->creditOriginal($seller, $order, $price, $fee, 'pix', $paidAt, 'sale');
            $refundAt = $paidAt->copy()->addDays(2);
            $order->update(['status' => 'refunded']);
            $this->ledger($seller, $order, null, WalletTransaction::TYPE_DEBIT_REFUND, $price, $fee, round($price - $fee, 2), 'pix', $refundAt, [
                'seed' => self::SEED,
                'reason' => 'platform_manual_refund',
            ]);
            $count++;
        }

        return $count;
    }

    private function seedPendingAndRelease(User $seller, Product $product, User $customer, float $feeRate, float $fixed, Carbon $now): int
    {
        $price = 997.00;
        $fee = $this->fee($price, $feeRate, $fixed);
        $paidAt = $now->copy()->subMonthNoOverflow()->endOfMonth()->subDays(2)->setTime(14, 20);
        $releasedAt = $now->copy()->startOfMonth()->addDays(1)->setTime(9, 10);
        $order = $this->createOrder($customer, $seller, $product, $price, 'card', $paidAt, 'completed');
        $pending = $this->creditOriginal($seller, $order, $price, $fee, 'card', $paidAt, 'pending');
        $this->ledger($seller, $order, null, WalletTransaction::TYPE_CREDIT_SALE, $price, $fee, round($price - $fee, 2), 'card', $releasedAt, [
            'seed' => self::SEED,
            'from_pending_wallet_transaction_id' => $pending->id,
        ], WalletCreditReference::forDirectSale((int) $order->id, (int) $seller->id).':released');
        $pending->forceFill([
            'meta' => array_merge($pending->meta ?? [], [
                'released_at' => $releasedAt->toIso8601String(),
                'clears_at' => $releasedAt->toIso8601String(),
            ]),
        ])->save();

        return 1;
    }

    private function seedBoletoCrossMonth(User $seller, Product $product, User $customer, float $feeRate, float $fixed, Carbon $now): int
    {
        $price = 197.00;
        $opened = $now->copy()->subMonthNoOverflow()->endOfMonth()->subDay()->setTime(18, 40);
        $paid = $now->copy()->startOfMonth()->addDays(1)->setTime(11, 5);
        $order = $this->createOrder($customer, $seller, $product, $price, 'boleto', $opened, 'completed');
        $this->creditOriginal($seller, $order, $price, $this->fee($price, $feeRate, $fixed), 'boleto', $paid, 'sale');

        return 1;
    }

    private function seedWithdrawals(User $seller, int $i, Carbon $now): void
    {
        if (! Schema::hasTable('withdrawals')) {
            return;
        }

        $base = $now->copy()->startOfMonth()->addDays(2)->setTime(10, 0);
        $rows = [
            ['amount' => 800 + ($i * 37), 'status' => 'paid', 'fee' => 3.50, 'days' => 1],
            ['amount' => 250 + ($i * 11), 'status' => 'paid', 'fee' => 3.50, 'days' => 4],
            ['amount' => 120, 'status' => $i % 3 === 0 ? 'pending' : 'processing', 'fee' => 2.00, 'days' => 0],
            ['amount' => 90, 'status' => 'failed', 'fee' => 8.00, 'days' => 5],
            ['amount' => 60, 'status' => 'rejected', 'fee' => 8.00, 'days' => 6],
        ];
        if ($i % 7 !== 0) {
            $rows = array_slice($rows, 0, 3);
        }

        foreach ($rows as $row) {
            $at = $base->copy()->addDays($row['days'])->addMinutes($i);
            if ($at->gt($now)) {
                $at = $now->copy()->subHours(2);
            }
            $w = Withdrawal::query()->create([
                'tenant_id' => $seller->id,
                'user_id' => $seller->id,
                'amount' => $row['amount'],
                'fee_amount' => $row['fee'],
                'net_amount' => round($row['amount'] - $row['fee'], 2),
                'bucket' => 'pix',
                'status' => $row['status'],
                'failed_reason' => $row['status'] === 'failed' ? 'Falha fictícia do seeder Fiscal.' : null,
                'notes' => 'Saque fictício Fiscal',
                'currency' => 'BRL',
                'payout_provider' => 'cajupay',
                'payout_external_id' => 'fiscal-wd-'.Str::lower(Str::random(10)),
                'payout_manual' => false,
            ]);
            $w->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }
    }

    private function createOrder(
        User $customer,
        User $seller,
        Product $product,
        float $amount,
        string $method,
        Carbon $at,
        string $status,
    ): Order {
        $gateway = match ($method) {
            'card' => 'pagarme',
            'boleto' => 'efi',
            default => 'cajupay',
        };

        $order = Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => $status,
            'amount' => $amount,
            'email' => $customer->email,
            'cpf' => $customer->document,
            'phone' => $customer->phone,
            'gateway' => $gateway,
            'gateway_id' => 'fiscal_'.Str::lower(Str::random(12)),
            'payment_method' => $method,
            'approved_manually' => false,
            'metadata' => [
                'seed' => self::SEED,
                'checkout_payment_method' => $method,
            ],
        ]);
        $order->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        if (Schema::hasTable('order_items')) {
            OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'amount' => $amount,
                'position' => 0,
            ]);
        }

        return $order->fresh();
    }

    private function creditOriginal(
        User $tenant,
        Order $order,
        float $gross,
        float $fee,
        string $method,
        Carbon $at,
        string $mode,
    ): WalletTransaction {
        $bucket = match ($method) {
            'card', 'apple_pay', 'google_pay' => 'card',
            'boleto' => 'boleto',
            default => 'pix',
        };
        $type = $mode === 'pending'
            ? WalletTransaction::TYPE_CREDIT_SALE_PENDING
            : WalletTransaction::TYPE_CREDIT_SALE;
        $ref = $mode === 'pending'
            ? WalletCreditReference::forPendingSale((int) $order->id, (int) $tenant->id, 'main')
            : WalletCreditReference::forDirectSale((int) $order->id, (int) $tenant->id);

        $tx = $this->ledger($tenant, $order, $ref, $type, $gross, $fee, round($gross - $fee, 2), $bucket, $at, [
            'seed' => self::SEED,
            'percent_applied' => null,
            'portion' => $mode === 'pending' ? 'main' : 'direct',
        ]);

        $this->addWalletNet($tenant, $bucket, round($gross - $fee, 2));

        return $tx;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function ledger(
        User $tenant,
        Order $order,
        ?string $creditReference,
        string $type,
        float $gross,
        float $fee,
        float $net,
        string $bucket,
        Carbon $at,
        array $meta,
        ?string $creditReferenceOverride = null,
    ): WalletTransaction {
        $payload = [
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'bucket' => $bucket,
            'type' => $type,
            'amount_gross' => $gross,
            'amount_fee' => $fee,
            'amount_net' => $net,
            'meta' => $meta,
        ];
        if (Schema::hasColumn('wallet_transactions', 'credit_reference')) {
            $payload['credit_reference'] = $creditReferenceOverride ?? $creditReference;
        }

        $tx = WalletTransaction::query()->create($payload);
        $tx->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $tx->fresh();
    }

    private function addWalletNet(User $seller, string $bucket, float $net): void
    {
        if (! Schema::hasTable('tenant_wallets') || $net <= 0) {
            return;
        }
        $col = 'available_'.$bucket;
        if (! in_array($col, ['available_pix', 'available_card', 'available_boleto'], true)) {
            $col = 'available_pix';
        }
        $wallet = TenantWallet::query()->firstOrCreate(
            ['tenant_id' => $seller->id],
            [
                'currency' => 'BRL',
                'available_balance' => 0,
                'pending_balance' => 0,
                'available_pix' => 0,
                'available_card' => 0,
                'available_boleto' => 0,
                'pending_pix' => 0,
                'pending_card' => 0,
                'pending_boleto' => 0,
            ]
        );
        $wallet->{$col} = round((float) $wallet->{$col} + $net, 2);
        $wallet->available_balance = round(
            (float) $wallet->available_pix + (float) $wallet->available_card + (float) $wallet->available_boleto,
            2
        );
        $wallet->save();
    }

    private function fee(float $gross, float $rate, float $fixed): float
    {
        return round(($gross * $rate) + $fixed, 2);
    }

    private function fakeCpf(int $i): string
    {
        return str_pad((string) (10000000000 + ($i * 137) % 899999999), 11, '0', STR_PAD_LEFT);
    }

    private function fakeCnpj(int $i): string
    {
        return str_pad((string) (11222333000100 + ($i * 17)), 14, '0', STR_PAD_LEFT);
    }
}

<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\DeliverableAccessLinkService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Produto tipo Link com encaminhamento /a/{ref} (não apaga o restante do banco).
 *
 * Uso: php artisan db:seed --class=LocalLinkDeliverableSeeder --force
 */
class LocalLinkDeliverableSeeder extends Seeder
{
    private const STUDENT_EMAIL = 'carlos.link.demo@example.com';

    private const STUDENT_PASSWORD = 'password';

    private const CHECKOUT_SLUG = 'linkdemo01';

    private const GATEWAY_ID = 'link_demo_seed_v1';

    private const DESTINATION = 'https://example.com/conteudo-demo-stacker';

    public function run(): void
    {
        if (! Schema::hasColumn('product_user', 'access_ref')) {
            $this->command?->error('Rode as migrations antes (coluna product_user.access_ref).');

            return;
        }

        $seller = User::query()->where('email', LocalDevAccessSeeder::EMAIL)->first();
        if (! $seller) {
            $this->command?->error('Crie primeiro a conta local (LocalDevAccessSeeder).');

            return;
        }

        if ($seller->tenant_id === null) {
            $seller->forceFill(['tenant_id' => $seller->id])->save();
            $seller = $seller->fresh();
        }

        $product = $this->upsertProduct($seller);
        $student = $this->upsertStudent($product);
        $this->upsertOrder($seller, $student, $product);

        $links = app(DeliverableAccessLinkService::class);
        $tracked = $links->trackedUrl($student, $product);

        $this->command?->info('Demo de link externo criada sem apagar dados existentes.');
        $this->command?->table(
            ['Item', 'Valor'],
            [
                ['Painel', 'Alunos → Carlos Lima Demo → Pack Demo — Link externo'],
                ['Vendedor', LocalDevAccessSeeder::EMAIL.' / '.LocalDevAccessSeeder::PASSWORD],
                ['Aluno', self::STUDENT_EMAIL.' / '.self::STUDENT_PASSWORD],
                ['Checkout', url('/c/'.self::CHECKOUT_SLUG)],
                ['Link rastreado', $tracked ?? '(falhou gerar ref)'],
                ['Destino real (não aparece no botão)', self::DESTINATION],
            ]
        );
    }

    private function upsertProduct(User $seller): Product
    {
        $tenantId = $seller->tenant_id ?: $seller->id;
        $existing = Product::query()->where('checkout_slug', self::CHECKOUT_SLUG)->first();

        $payload = [
            'tenant_id' => $tenantId,
            'name' => 'Pack Demo — Link externo',
            'slug' => 'pack-demo-link-externo',
            'checkout_slug' => self::CHECKOUT_SLUG,
            'type' => Product::TYPE_LINK,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 47.00,
            'currency' => 'BRL',
            'is_active' => true,
            'description' => 'Produto fictício para testar o encaminhamento /a/{ref}.',
            'checkout_config' => array_merge(
                is_array($existing?->checkout_config) ? $existing->checkout_config : [],
                ['deliverable_link' => self::DESTINATION]
            ),
        ];
        if (Schema::hasColumn('products', 'admin_blocked')) {
            $payload['admin_blocked'] = false;
        }
        if (Schema::hasColumn('products', 'approval_status')) {
            $payload['approval_status'] = Product::APPROVAL_APPROVED;
        }

        if ($existing) {
            $existing->forceFill($payload)->save();

            return $existing->fresh();
        }

        $product = new Product;
        $product->forceFill($payload);
        $product->save();

        return $product->fresh();
    }

    private function upsertStudent(Product $product): User
    {
        $student = User::query()->updateOrCreate(
            ['email' => self::STUDENT_EMAIL],
            [
                'name' => 'Carlos Lima Demo',
                'password' => Hash::make(self::STUDENT_PASSWORD),
                'role' => User::ROLE_CLIENTE,
                'account_status' => 'approved',
                'tenant_id' => null,
                'phone' => '11987654321',
                'document' => '39053344705',
                'email_verified_at' => now(),
            ]
        );

        if (! $product->users()->where('users.id', $student->id)->exists()) {
            $product->users()->attach($student->id);
        }

        return $student->fresh();
    }

    private function upsertOrder(User $seller, User $student, Product $product): void
    {
        $paidAt = Carbon::now()->subHours(3);
        $payload = [
            'tenant_id' => $seller->tenant_id ?: $seller->id,
            'user_id' => $student->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 47.00,
            'email' => $student->email,
            'cpf' => $student->document,
            'phone' => $student->phone,
            'gateway' => 'demo_seed',
            'gateway_id' => self::GATEWAY_ID,
            'payment_method' => 'pix',
            'approved_manually' => false,
            'metadata' => ['seed' => 'local_link_deliverable'],
        ];

        $order = Order::query()->where('gateway_id', self::GATEWAY_ID)->first();
        if ($order) {
            $order->forceFill($payload)->save();
        } else {
            $order = Order::query()->create($payload);
        }

        $timestamps = ['created_at' => $paidAt, 'updated_at' => $paidAt];
        if (Schema::hasColumn('orders', 'paid_at')) {
            $timestamps['paid_at'] = $paidAt;
        }
        $order->forceFill($timestamps)->save();

        if (Schema::hasTable('order_items') && ! $order->orderItems()->exists()) {
            OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'amount' => 47.00,
                'position' => 0,
            ]);
        }
    }
}

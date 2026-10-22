<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Tests\TestCase;

class VendasOrderBumpFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
        ]);
    }

    public function test_filtering_by_bump_product_includes_parent_sale(): void
    {
        [$seller, $main, $bump, $withBump, $plain] = $this->seedSales();

        $this->actingAs($seller)
            ->get(route('vendas.index', ['product_ids' => [$bump->id]]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Vendas/Index')
                ->has('vendas.data', 1)
                ->where('vendas.data.0.id', $withBump->id)
                ->where('vendas.data.0.has_order_bump', true)
            );

        $this->actingAs($seller)
            ->get(route('vendas.index', ['product_ids' => [$main->id]]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('vendas.data', 2)
            );

        $this->actingAs($seller)
            ->get(route('vendas.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('vendas.data', 2)
                ->where('filters.order_bump', 'all')
            );

        $this->assertNotNull($plain->id);
    }

    public function test_order_bump_filter_with_and_without(): void
    {
        [$seller, , , $withBump, $plain] = $this->seedSales();

        $this->actingAs($seller)
            ->get(route('vendas.index', ['order_bump' => 'with']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('vendas.data', 1)
                ->where('vendas.data.0.id', $withBump->id)
                ->where('filters.order_bump', 'with')
            );

        $this->actingAs($seller)
            ->get(route('vendas.index', ['order_bump' => 'without']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('vendas.data', 1)
                ->where('vendas.data.0.id', $plain->id)
                ->where('filters.order_bump', 'without')
            );
    }

    public function test_export_includes_bump_items_and_full_columns(): void
    {
        [$seller, $main, $bump] = $this->seedSales();

        $response = $this->actingAs($seller)->get(route('vendas.export', [
            'format' => 'csv',
            'order_bump' => 'with',
        ]));

        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('ID;Referência;Data;Status;Origem;Canal', $csv);
        $this->assertStringContainsString('Order bump', $csv);
        $this->assertStringContainsString('Valor bruto', $csv);
        $this->assertStringContainsString('Valor líquido', $csv);
        $this->assertStringContainsString('Produto bump (Bump)', $csv);
        $this->assertStringContainsString($main->name, $csv);
        $this->assertStringContainsString($bump->name, $csv);
    }

    public function test_json_export_is_structured_for_analysis(): void
    {
        [$seller, $main, $bump, $withBump] = $this->seedSales();

        $response = $this->actingAs($seller)->get(route('vendas.export', [
            'format' => 'json',
            'order_bump' => 'with',
        ]));

        $response->assertOk();
        $payload = json_decode($response->streamedContent(), true);
        $this->assertIsArray($payload);
        $this->assertSame('stacker-vendas-json-v1', $payload['formato']);
        $this->assertSame(1, $payload['total']);
        $this->assertCount(1, $payload['vendas']);
        $sale = $payload['vendas'][0];
        $this->assertSame($withBump->id, $sale['id']);
        $this->assertTrue($sale['tem_order_bump']);
        $this->assertSame($main->name, $sale['produto']);
        $this->assertEquals(150, $sale['valores']['bruto']);
        $this->assertCount(2, $sale['itens']);
        $this->assertFalse($sale['itens'][0]['order_bump']);
        $this->assertTrue($sale['itens'][1]['order_bump']);
        $this->assertSame($bump->name, $sale['itens'][1]['produto']);
        $this->assertEquals(50, $sale['itens'][1]['valor']);
    }

    public function test_bump_product_plus_order_bump_filter_still_finds_sale(): void
    {
        [$seller, , $bump, $withBump] = $this->seedSales();

        $this->actingAs($seller)
            ->get(route('vendas.index', [
                'product_ids' => [$bump->id],
                'order_bump' => 'with',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('vendas.data', 1)
                ->where('vendas.data.0.id', $withBump->id)
            );
    }

    /**
     * @return array{0: User, 1: \App\Models\Product, 2: \App\Models\Product, 3: Order, 4: Order}
     */
    private function seedSales(): array
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
            'email_verified_at' => now(),
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $tenantId = (int) $seller->id;

        $main = $this->createTestProduct(['tenant_id' => $tenantId, 'name' => 'Produto principal']);
        $bump = $this->createTestProduct(['tenant_id' => $tenantId, 'name' => 'Produto bump']);

        $withBump = Order::create([
            'tenant_id' => $tenantId,
            'user_id' => $seller->id,
            'product_id' => $main->id,
            'status' => 'completed',
            'amount' => 150,
            'email' => 'bump@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
        ]);
        OrderItem::create([
            'order_id' => $withBump->id,
            'product_id' => $main->id,
            'amount' => 100,
            'position' => 0,
        ]);
        OrderItem::create([
            'order_id' => $withBump->id,
            'product_id' => $bump->id,
            'amount' => 50,
            'position' => 1,
        ]);

        $plain = Order::create([
            'tenant_id' => $tenantId,
            'user_id' => $seller->id,
            'product_id' => $main->id,
            'status' => 'completed',
            'amount' => 100,
            'email' => 'plain@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
        ]);
        OrderItem::create([
            'order_id' => $plain->id,
            'product_id' => $main->id,
            'amount' => 100,
            'position' => 0,
        ]);

        return [$seller, $main, $bump, $withBump, $plain];
    }
}

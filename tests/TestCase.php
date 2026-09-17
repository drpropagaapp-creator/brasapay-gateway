<?php

namespace Tests;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\InteractsWithGatewayWebhooks;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithGatewayWebhooks;
    use RefreshDatabase {
        refreshTestDatabase as refreshTestDatabaseUnsafe;
    }

    /**
     * Nunca rodar migrate:fresh no Postgres local (ex.: getfy no Docker).
     */
    protected function refreshTestDatabase()
    {
        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");
        $database = (string) config("database.connections.{$connection}.database");
        $isSqliteMemory = $driver === 'sqlite' && ($database === ':memory:' || $database === '');

        if (! $isSqliteMemory) {
            throw new \RuntimeException(
                "Testes recusados: RefreshDatabase limparia {$driver}/{$database}. Use sqlite :memory: (phpunit.xml) para preservar o banco local."
            );
        }

        $this->refreshTestDatabaseUnsafe();
    }

    /**
     * Em SQLite (phpunit) a coluna products.id continua inteira; o boot do model usaria UUID (migração só no MySQL).
     */
    protected function createTestProduct(array $overrides = []): Product
    {
        $nextId = (int) (Product::query()->max('id') ?? 0) + 1;

        $product = new Product;
        $product->forceFill(array_merge([
            'id' => (string) $nextId,
            'tenant_id' => 1,
            'name' => 'Test product',
            'slug' => 't-'.uniqid('', true),
            'type' => Product::TYPE_LINK,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 10,
            'currency' => 'BRL',
            'is_active' => true,
        ], $overrides));
        $product->save();

        return $product->fresh();
    }
}

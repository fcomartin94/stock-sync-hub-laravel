<?php

namespace Tests\Unit;

use App\Jobs\SyncStoreJob;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncStoreJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_syncs_products_and_orders_from_prestashop_into_the_database(): void
    {
        Http::fake([
            '*/api/products*' => Http::response([
                'products' => [
                    [
                        'id' => 1,
                        'reference' => 'COFFEE-001',
                        'name' => [['id' => 1, 'value' => 'Ethiopia Yirgacheffe']],
                        'price' => '12.50',
                    ],
                ],
            ], 200),
            '*/api/stock_availables*' => Http::response([
                'stock_availables' => [
                    ['id' => 10, 'id_product' => 1, 'quantity' => 30],
                ],
            ], 200),
            '*/api/orders*' => Http::response([
                'orders' => [
                    [
                        'id' => 100,
                        'current_state' => '2',
                        'total_paid' => '12.50',
                        'date_add' => '2026-07-10 10:00:00',
                    ],
                ],
            ], 200),
        ]);

        $store = Store::create([
            'name' => 'Brew & Co.',
            'platform' => 'prestashop',
            'base_url' => 'http://prestashop.test',
            'api_key' => 'fake_key',
        ]);

        (new SyncStoreJob($store))->handle();

        $this->assertDatabaseHas('products', [
            'store_id' => $store->id,
            'external_id' => '1',
            'sku' => 'COFFEE-001',
            'stock_quantity' => 30,
        ]);

        $this->assertDatabaseHas('orders', [
            'store_id' => $store->id,
            'external_id' => '100',
            'total' => 12.50,
        ]);

        $this->assertDatabaseHas('stock_snapshots', [
            'quantity' => 30,
        ]);

        $store->refresh();
        $this->assertNotNull($store->last_synced_at);
    }
}
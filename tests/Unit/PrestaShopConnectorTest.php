<?php

namespace Tests\Unit;

use App\Services\Connectors\PrestaShopConnector;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PrestaShopConnectorTest extends TestCase
{
    public function test_it_fetches_and_normalizes_products_from_the_prestashop_api(): void
    {
        Http::fake([
            '*/api/products*' => Http::response([
                'products' => [
                    [
                        'id' => 1,
                        'reference' => 'COFFEE-001',
                        'name' => [
                            ['id' => 1, 'value' => 'Ethiopia Yirgacheffe'],
                        ],
                        'price' => '12.500000',
                    ],
                ],
            ], 200),
            '*/api/stock_availables*' => Http::response([
                'stock_availables' => [
                    ['id' => 10, 'id_product' => 1, 'quantity' => 30],
                ],
            ], 200),
        ]);

        $connector = new PrestaShopConnector('http://prestashop.test', 'fake_key');

        $products = $connector->getProducts();

        $this->assertCount(1, $products);
        $this->assertSame('1', $products[0]['external_id']);
        $this->assertSame('COFFEE-001', $products[0]['sku']);
        $this->assertSame('Ethiopia Yirgacheffe', $products[0]['name']);
        $this->assertSame(12.5, $products[0]['price']);
        $this->assertSame(30, $products[0]['stock_quantity']);
    }

    public function test_it_fetches_and_normalizes_orders_from_the_prestashop_api(): void
    {
        Http::fake([
            '*/api/orders*' => Http::response([
                'orders' => [
                    [
                        'id' => 100,
                        'current_state' => '2',
                        'total_paid' => '37.50',
                        'date_add' => '2026-07-10 10:00:00',
                    ],
                ],
            ], 200),
        ]);

        $connector = new PrestaShopConnector('http://prestashop.test', 'fake_key');

        $orders = $connector->getOrders();

        $this->assertCount(1, $orders);
        $this->assertSame('100', $orders[0]['external_id']);
        $this->assertSame(37.5, $orders[0]['total']);
        $this->assertSame('EUR', $orders[0]['currency']);
    }
}
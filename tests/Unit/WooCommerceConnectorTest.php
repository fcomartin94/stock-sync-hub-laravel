<?php

namespace Tests\Unit;

use App\Services\Connectors\WooCommerceConnector;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WooCommerceConnectorTest extends TestCase
{
    public function test_it_fetches_and_normalizes_products_from_the_woocommerce_api(): void
    {
        Http::fake([
            '*/wp-json/wc/v3/products*' => Http::response([
                [
                    'id' => 55,
                    'sku' => 'BOOK-001',
                    'name' => 'The Midnight Library',
                    'price' => '18.99',
                    'stock_quantity' => 12,
                ],
            ], 200),
        ]);

        $connector = new WooCommerceConnector('http://woocommerce.test', 'key', 'secret');

        $products = $connector->getProducts();

        $this->assertCount(1, $products);
        $this->assertSame('55', $products[0]['external_id']);
        $this->assertSame('BOOK-001', $products[0]['sku']);
        $this->assertSame('The Midnight Library', $products[0]['name']);
        $this->assertSame(18.99, $products[0]['price']);
        $this->assertSame(12, $products[0]['stock_quantity']);
    }

    public function test_it_fetches_and_normalizes_orders_from_the_woocommerce_api(): void
    {
        Http::fake([
            '*/wp-json/wc/v3/orders*' => Http::response([
                [
                    'id' => 200,
                    'status' => 'processing',
                    'total' => '37.98',
                    'currency' => 'USD',
                    'date_created' => '2026-07-10T10:00:00',
                ],
            ], 200),
        ]);

        $connector = new WooCommerceConnector('http://woocommerce.test', 'key', 'secret');

        $orders = $connector->getOrders();

        $this->assertCount(1, $orders);
        $this->assertSame('200', $orders[0]['external_id']);
        $this->assertSame('processing', $orders[0]['status']);
        $this->assertSame(37.98, $orders[0]['total']);
        $this->assertSame('USD', $orders[0]['currency']);
    }
}
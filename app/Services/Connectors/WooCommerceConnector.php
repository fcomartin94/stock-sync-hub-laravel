<?php

namespace App\Services\Connectors;

use Illuminate\Support\Facades\Http;

class WooCommerceConnector implements StoreConnectorInterface
{
    public function __construct(
        protected string $baseUrl,
        protected string $consumerKey,
        protected string $consumerSecret,
    ) {
    }

    protected function client()
    {
        return Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
            ->baseUrl(rtrim($this->baseUrl, '/') . '/wp-json/wc/v3');
    }

    public function getProducts(): array
    {
        $response = $this->client()->get('products', [
            'per_page' => 100,
        ]);

        $response->throw();

        $products = $response->json();

        return array_map(function (array $product) {
            return [
                'external_id' => (string) $product['id'],
                'sku' => $product['sku'] ?: null,
                'name' => $product['name'],
                'price' => (float) ($product['price'] ?? 0),
                'stock_quantity' => (int) ($product['stock_quantity'] ?? 0),
            ];
        }, $products);
    }

    public function getStock(string $externalProductId): int
    {
        $response = $this->client()->get("products/{$externalProductId}");

        $response->throw();

        return (int) ($response->json('stock_quantity') ?? 0);
    }

    public function getOrders(): array
    {
        $response = $this->client()->get('orders', [
            'per_page' => 100,
        ]);

        $response->throw();

        $orders = $response->json();

        return array_map(function (array $order) {
            return [
                'external_id' => (string) $order['id'],
                'status' => (string) $order['status'],
                'total' => (float) ($order['total'] ?? 0),
                'currency' => (string) ($order['currency'] ?? 'EUR'),
                'ordered_at' => $order['date_created'] ?? now()->toIso8601String(),
            ];
        }, $orders);
    }
}

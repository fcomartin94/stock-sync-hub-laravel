<?php

namespace App\Services\Connectors;

use Illuminate\Support\Facades\Http;

class PrestaShopConnector implements StoreConnectorInterface
{
    public function __construct(
        protected string $baseUrl,
        protected string $apiKey,
    ) {
    }

    protected function client()
    {
        return Http::withBasicAuth($this->apiKey, '')
            ->baseUrl(rtrim($this->baseUrl, '/') . '/api');
    }

    public function getProducts(): array
    {
        $response = $this->client()->get('products', [
            'output_format' => 'JSON',
            'display' => 'full',
        ]);

        $response->throw();

        $products = $response->json('products', []);

        return array_map(function (array $product) {
            return [
                'external_id' => (string) $product['id'],
                'sku' => $product['reference'] ?? null,
                'name' => is_array($product['name'])
                    ? (reset($product['name'])['value'] ?? '')
                    : $product['name'],
                'price' => (float) ($product['price'] ?? 0),
                'stock_quantity' => $this->getStock((string) $product['id']),
            ];
        }, $products);
    }

    public function getStock(string $externalProductId): int
    {
        $response = $this->client()->get('stock_availables', [
            'output_format' => 'JSON',
            'filter[id_product]' => $externalProductId,
            'display' => '[quantity]',
        ]);

        $response->throw();

        $stockAvailables = $response->json('stock_availables', []);

        return (int) ($stockAvailables[0]['quantity'] ?? 0);
    }

    public function getOrders(): array
    {
        $response = $this->client()->get('orders', [
            'output_format' => 'JSON',
            'display' => 'full',
        ]);

        $response->throw();

        $orders = $response->json('orders', []);

        return array_map(function (array $order) {
            return [
                'external_id' => (string) $order['id'],
                'status' => (string) $order['current_state'],
                'total' => (float) ($order['total_paid'] ?? 0),
                'currency' => 'EUR',
                'ordered_at' => $order['date_add'] ?? now()->toIso8601String(),
            ];
        }, $orders);
    }
}

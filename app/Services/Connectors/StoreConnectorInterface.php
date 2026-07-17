<?php

namespace App\Services\Connectors;

interface StoreConnectorInterface
{
    /**
     * @return array<int, array{external_id: string, sku: ?string, name: string, price: float, stock_quantity: int}>
     */
    public function getProducts(): array;

    public function getStock(string $externalProductId): int;

    /**
     * @return array<int, array{external_id: string, status: string, total: float, currency: string, ordered_at: string}>
     */
    public function getOrders(): array;
}

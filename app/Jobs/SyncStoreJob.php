<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\StockSnapshot;
use App\Services\Connectors\PrestaShopConnector;
use App\Services\Connectors\StoreConnectorInterface;
use App\Services\Connectors\WooCommerceConnector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncStoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Store $store,
    ) {
    }

    public function handle(): void
    {
        $connector = $this->resolveConnector();

        $this->syncProducts($connector);
        $this->syncOrders($connector);

        $this->store->update(['last_synced_at' => now()]);
    }

    protected function resolveConnector(): StoreConnectorInterface
    {
        return match ($this->store->platform) {
            'prestashop' => new PrestaShopConnector(
                $this->store->base_url,
                $this->store->api_key,
            ),
            'woocommerce' => new WooCommerceConnector(
                $this->store->base_url,
                $this->store->api_key,
                $this->store->api_secret,
            ),
            default => throw new \InvalidArgumentException(
                "Unsupported platform [{$this->store->platform}] for store [{$this->store->id}]."
            ),
        };
    }

    protected function syncProducts(StoreConnectorInterface $connector): void
    {
        foreach ($connector->getProducts() as $data) {
            $product = Product::updateOrCreate(
                [
                    'store_id' => $this->store->id,
                    'external_id' => $data['external_id'],
                ],
                [
                    'sku' => $data['sku'],
                    'name' => $data['name'],
                    'price' => $data['price'],
                    'stock_quantity' => $data['stock_quantity'],
                ],
            );

            StockSnapshot::create([
                'product_id' => $product->id,
                'quantity' => $data['stock_quantity'],
                'recorded_at' => now(),
            ]);
        }
    }

    protected function syncOrders(StoreConnectorInterface $connector): void
    {
        foreach ($connector->getOrders() as $data) {
            Order::updateOrCreate(
                [
                    'store_id' => $this->store->id,
                    'external_id' => $data['external_id'],
                ],
                [
                    'status' => $data['status'],
                    'total' => $data['total'],
                    'currency' => $data['currency'],
                    'ordered_at' => $data['ordered_at'],
                ],
            );
        }
    }
}
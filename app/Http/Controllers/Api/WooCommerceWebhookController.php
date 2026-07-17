<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\StockSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WooCommerceWebhookController extends Controller
{
    public function handle(Request $request)
    {
        if (! $this->hasValidSignature($request)) {
            Log::warning('WooCommerce webhook rejected: invalid signature.');

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $topic = $request->header('X-WC-Webhook-Topic');
        $payload = $request->json()->all();

        return match ($topic) {
            'order.created', 'order.updated' => $this->handleOrder($request, $payload),
            'product.created', 'product.updated' => $this->handleProduct($request, $payload),
            default => response()->json(['message' => "Ignored topic [{$topic}]."], 200),
        };
    }

    protected function hasValidSignature(Request $request): bool
    {
        $secret = config('services.woocommerce.webhook_secret');
        $signatureHeader = $request->header('X-WC-Webhook-Signature');

        if (! $secret || ! $signatureHeader) {
            return false;
        }

        $computedSignature = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        return hash_equals($computedSignature, $signatureHeader);
    }

    protected function resolveStore(Request $request): ?Store
    {
        $sourceUrl = $request->header('X-WC-Webhook-Source');

        return Store::where('platform', 'woocommerce')
            ->where('base_url', $sourceUrl)
            ->first();
    }

    protected function handleOrder(Request $request, array $payload)
    {
        $store = $this->resolveStore($request);

        if (! $store) {
            return response()->json(['message' => 'Unknown store for this webhook source.'], 404);
        }

        Order::updateOrCreate(
            [
                'store_id' => $store->id,
                'external_id' => (string) $payload['id'],
            ],
            [
                'status' => $payload['status'] ?? 'unknown',
                'total' => (float) ($payload['total'] ?? 0),
                'currency' => $payload['currency'] ?? 'EUR',
                'ordered_at' => $payload['date_created'] ?? now()->toIso8601String(),
            ],
        );

        return response()->json(['message' => 'Order synced.'], 200);
    }

    protected function handleProduct(Request $request, array $payload)
    {
        $store = $this->resolveStore($request);

        if (! $store) {
            return response()->json(['message' => 'Unknown store for this webhook source.'], 404);
        }

        $product = Product::updateOrCreate(
            [
                'store_id' => $store->id,
                'external_id' => (string) $payload['id'],
            ],
            [
                'sku' => $payload['sku'] ?: null,
                'name' => $payload['name'] ?? '',
                'price' => (float) ($payload['price'] ?? 0),
                'stock_quantity' => (int) ($payload['stock_quantity'] ?? 0),
            ],
        );

        StockSnapshot::create([
            'product_id' => $product->id,
            'quantity' => $product->stock_quantity,
            'recorded_at' => now(),
        ]);

        return response()->json(['message' => 'Product synced.'], 200);
    }
}
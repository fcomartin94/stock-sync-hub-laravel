<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::with('store:id,name,platform')
            ->when($request->query('store_id'), fn ($q, $storeId) => $q->where('store_id', $storeId))
            ->paginate(50);

        return response()->json($products);
    }

    public function lowStock(Request $request)
    {
        $threshold = (int) $request->query('threshold', 5);

        $products = Product::with('store:id,name,platform')
            ->where('stock_quantity', '<=', $threshold)
            ->orderBy('stock_quantity')
            ->get();

        return response()->json($products);
    }

    public function stockHistory(Product $product)
    {
        $history = $product->stockSnapshots()
            ->orderBy('recorded_at')
            ->get(['quantity', 'recorded_at']);

        return response()->json([
            'product' => $product->only(['id', 'name', 'sku']),
            'history' => $history,
        ]);
    }
}
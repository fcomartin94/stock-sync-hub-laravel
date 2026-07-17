<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function recent(Request $request)
    {
        $orders = Order::with('store:id,name,platform')
            ->when($request->query('store_id'), fn ($q, $storeId) => $q->where('store_id', $storeId))
            ->orderByDesc('ordered_at')
            ->limit(50)
            ->get();

        return response()->json($orders);
    }
}
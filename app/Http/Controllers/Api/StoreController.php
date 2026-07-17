<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncStoreJob;
use App\Models\Store;

class StoreController extends Controller
{
    public function index()
    {
        return response()->json(Store::all());
    }

    public function sync(Store $store)
    {
        SyncStoreJob::dispatch($store);

        return response()->json([
            'message' => "Sync dispatched for store [{$store->name}].",
        ], 202);
    }
}
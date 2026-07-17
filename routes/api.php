<?php

use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\WooCommerceWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/webhooks/woocommerce', [WooCommerceWebhookController::class, 'handle']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/stores', [StoreController::class, 'index']);
    Route::post('/stores/{store}/sync', [StoreController::class, 'sync']);

    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/low-stock', [ProductController::class, 'lowStock']);
    Route::get('/products/{product}/stock-history', [ProductController::class, 'stockHistory']);

    Route::get('/orders/recent', [OrderController::class, 'recent']);
});
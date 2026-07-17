<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('products', function (Blueprint $table) {
        $table->id();
        $table->foreignId('store_id')->constrained()->cascadeOnDelete();
        $table->string('external_id'); // ID del producto en PrestaShop/WooCommerce
        $table->string('sku')->nullable();
        $table->string('name');
        $table->decimal('price', 10, 2)->nullable();
        $table->integer('stock_quantity')->default(0);
        $table->timestamps();

        $table->unique(['store_id', 'external_id']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

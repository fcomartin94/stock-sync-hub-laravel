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
    Schema::create('stores', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('platform'); // 'prestashop' | 'woocommerce'
        $table->string('base_url');
        $table->string('api_key')->nullable();
        $table->string('api_secret')->nullable(); // WooCommerce usa key+secret
        $table->timestamp('last_synced_at')->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};

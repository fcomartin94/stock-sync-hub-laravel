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
    Schema::create('orders', function (Blueprint $table) {
        $table->id();
        $table->foreignId('store_id')->constrained()->cascadeOnDelete();
        $table->string('external_id');
        $table->string('status');
        $table->decimal('total', 10, 2);
        $table->string('currency', 3)->default('EUR');
        $table->timestamp('ordered_at');
        $table->timestamps();

        $table->unique(['store_id', 'external_id']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->string('amazon_order_id')->index();
            $table->string('order_item_id')->unique();
            $table->string('asin')->nullable();
            $table->string('seller_sku')->nullable();
            $table->string('title')->nullable();
            $table->integer('quantity_ordered')->nullable();
            $table->decimal('item_price_amount', 12, 2)->nullable();
            $table->string('item_price_currency', 3)->nullable();
            $table->json('raw_item')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};

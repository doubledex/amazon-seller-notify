<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('amazon_order_id')->unique();
            $table->dateTime('purchase_date')->nullable();
            $table->string('order_status')->nullable();
            $table->string('fulfillment_channel')->nullable();
            $table->string('sales_channel')->nullable();
            $table->string('marketplace_id')->nullable();
            $table->boolean('is_business_order')->default(false);
            $table->decimal('order_total_amount', 12, 2)->nullable();
            $table->string('order_total_currency', 3)->nullable();
            $table->string('shipping_city')->nullable();
            $table->string('shipping_country_code', 2)->nullable();
            $table->string('shipping_postal_code', 64)->nullable();
            $table->string('shipping_company')->nullable();
            $table->string('shipping_region')->nullable();
            $table->json('raw_order')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

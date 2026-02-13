<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('purchase_date');
            $table->index('marketplace_id');
            $table->index('order_status');
            $table->index('fulfillment_channel');
            $table->index('payment_method');
            $table->index('is_business_order');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['purchase_date']);
            $table->dropIndex(['marketplace_id']);
            $table->dropIndex(['order_status']);
            $table->dropIndex(['fulfillment_channel']);
            $table->dropIndex(['payment_method']);
            $table->dropIndex(['is_business_order']);
        });
    }
};

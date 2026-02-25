<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('amazon_fee_total', 12, 2)->nullable()->after('order_net_ex_tax_source');
            $table->string('amazon_fee_currency', 3)->nullable()->after('amazon_fee_total');
            $table->dateTime('amazon_fee_last_synced_at')->nullable()->after('amazon_fee_currency');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'amazon_fee_total',
                'amazon_fee_currency',
                'amazon_fee_last_synced_at',
            ]);
        });
    }
};


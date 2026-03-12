<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->longText('dev_order_api_2026_response')->nullable()->after('raw_order');
            $table->timestamp('dev_order_api_2026_fetched_at')->nullable()->after('dev_order_api_2026_response');
            $table->longText('dev_order_api_v0_response')->nullable()->after('dev_order_api_2026_fetched_at');
            $table->timestamp('dev_order_api_v0_fetched_at')->nullable()->after('dev_order_api_v0_response');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'dev_order_api_2026_response',
                'dev_order_api_2026_fetched_at',
                'dev_order_api_v0_response',
                'dev_order_api_v0_fetched_at',
            ]);
        });
    }
};

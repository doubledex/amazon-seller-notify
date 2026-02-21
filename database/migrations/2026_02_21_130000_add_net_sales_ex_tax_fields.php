<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('order_net_ex_tax', 12, 2)->nullable()->after('order_total_amount');
            $table->string('order_net_ex_tax_currency', 3)->nullable()->after('order_net_ex_tax');
            $table->string('order_net_ex_tax_source', 32)->nullable()->after('order_net_ex_tax_currency');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('line_net_ex_tax', 12, 2)->nullable()->after('item_price_amount');
            $table->string('line_net_currency', 3)->nullable()->after('line_net_ex_tax');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['line_net_ex_tax', 'line_net_currency']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['order_net_ex_tax', 'order_net_ex_tax_currency', 'order_net_ex_tax_source']);
        });
    }
};

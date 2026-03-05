<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('amazon_order_fee_lines_v2', function (Blueprint $table) {
            $table->dateTime('maturity_date')->nullable()->after('posted_date')->index();
            $table->dateTime('effective_payment_date')->nullable()->after('maturity_date')->index();
            $table->decimal('deferred_amount', 14, 4)->nullable()->after('net_ex_tax_amount');
            $table->decimal('released_amount', 14, 4)->nullable()->after('deferred_amount');

            $table->index(['effective_payment_date', 'marketplace_id'], 'aofl_v2_effective_marketplace_idx');
            $table->index(['maturity_date', 'transaction_status'], 'aofl_v2_maturity_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('amazon_order_fee_lines_v2', function (Blueprint $table) {
            $table->dropIndex('aofl_v2_effective_marketplace_idx');
            $table->dropIndex('aofl_v2_maturity_status_idx');
            $table->dropColumn([
                'maturity_date',
                'effective_payment_date',
                'deferred_amount',
                'released_amount',
            ]);
        });
    }
};

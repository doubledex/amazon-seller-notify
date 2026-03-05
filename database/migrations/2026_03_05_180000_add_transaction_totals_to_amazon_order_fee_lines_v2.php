<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('amazon_order_fee_lines_v2', function (Blueprint $table) {
            $table->decimal('transaction_total_amount', 14, 4)->nullable()->after('effective_payment_date');
            $table->string('transaction_total_currency', 8)->nullable()->after('transaction_total_amount')->index();
        });
    }

    public function down(): void
    {
        Schema::table('amazon_order_fee_lines_v2', function (Blueprint $table) {
            $table->dropColumn(['transaction_total_amount', 'transaction_total_currency']);
        });
    }
};

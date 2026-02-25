<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('amazon_order_fee_lines_v2', function (Blueprint $table) {
            $table->id();
            $table->string('line_hash', 64)->unique();
            $table->string('amazon_order_id')->index();
            $table->string('region', 8)->index();
            $table->string('marketplace_id')->nullable()->index();
            $table->string('transaction_id')->nullable()->index();
            $table->string('canonical_transaction_id')->nullable()->index();
            $table->string('transaction_status', 64)->nullable()->index();
            $table->string('transaction_type', 128)->nullable();
            $table->dateTime('posted_date')->nullable()->index();
            $table->string('fee_type')->nullable();
            $table->string('description')->nullable();
            $table->decimal('gross_amount', 14, 4)->nullable();
            $table->decimal('base_amount', 14, 4)->nullable();
            $table->decimal('tax_amount', 14, 4)->nullable();
            $table->decimal('net_ex_tax_amount', 14, 4)->nullable();
            $table->string('currency', 8)->nullable()->index();
            $table->json('raw_line')->nullable();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('amazon_fee_total_v2', 12, 2)->nullable()->after('amazon_fee_last_synced_at');
            $table->string('amazon_fee_currency_v2', 3)->nullable()->after('amazon_fee_total_v2');
            $table->dateTime('amazon_fee_last_synced_at_v2')->nullable()->after('amazon_fee_currency_v2');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'amazon_fee_total_v2',
                'amazon_fee_currency_v2',
                'amazon_fee_last_synced_at_v2',
            ]);
        });

        Schema::dropIfExists('amazon_order_fee_lines_v2');
    }
};


<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cashflow_outstanding_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('row_hash', 64)->unique();
            $table->string('region', 8)->nullable()->index();
            $table->string('marketplace_id', 24)->nullable()->index();
            $table->string('amazon_order_id')->nullable()->index();
            $table->string('transaction_id')->nullable()->index();
            $table->string('transaction_status', 64)->nullable()->index();
            $table->dateTime('posted_datetime_utc')->nullable()->index();
            $table->dateTime('maturity_datetime_utc')->nullable()->index();
            $table->integer('days_posted_to_maturity')->nullable();
            $table->string('currency', 8)->nullable()->index();
            $table->decimal('outstanding_value', 14, 4)->nullable();
            $table->boolean('missing_total_amount')->default(false)->index();
            $table->json('raw_transaction')->nullable();
            $table->timestamps();

            $table->index(['maturity_datetime_utc', 'marketplace_id'], 'cot_maturity_marketplace_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashflow_outstanding_transactions');
    }
};

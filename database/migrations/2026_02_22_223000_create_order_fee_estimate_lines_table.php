<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_fee_estimate_lines', function (Blueprint $table) {
            $table->id();
            $table->string('line_hash', 64)->unique();
            $table->string('amazon_order_id')->index();
            $table->string('asin', 32)->nullable()->index();
            $table->string('marketplace_id', 32)->nullable()->index();
            $table->string('fee_type')->nullable();
            $table->string('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('source', 64)->default('spapi_product_fees');
            $table->dateTime('estimated_at')->nullable()->index();
            $table->json('raw_line')->nullable();
            $table->timestamps();

            $table->index(['amazon_order_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_fee_estimate_lines');
    }
};


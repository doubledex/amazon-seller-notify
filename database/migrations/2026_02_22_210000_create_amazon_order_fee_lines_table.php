<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amazon_order_fee_lines', function (Blueprint $table) {
            $table->id();
            $table->string('fee_hash', 64)->unique();
            $table->string('amazon_order_id')->index();
            $table->string('region', 8)->nullable()->index();
            $table->string('event_type')->nullable();
            $table->string('fee_type')->nullable();
            $table->string('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->dateTime('posted_date')->nullable()->index();
            $table->json('raw_line')->nullable();
            $table->timestamps();

            $table->index(['amazon_order_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amazon_order_fee_lines');
    }
};


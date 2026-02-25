<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_identifier_sale_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_identifier_id')->constrained('product_identifiers')->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('currency', 3);
            $table->decimal('sale_price', 12, 4);
            $table->string('source', 32)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['product_identifier_id', 'effective_from'], 'pisp_identifier_effective_from_idx');
            $table->index(['product_identifier_id', 'effective_to'], 'pisp_identifier_effective_to_idx');
            $table->index(['product_identifier_id', 'currency'], 'pisp_identifier_currency_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_identifier_sale_prices');
    }
};

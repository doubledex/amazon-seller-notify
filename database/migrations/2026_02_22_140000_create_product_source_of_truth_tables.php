<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('status', 24)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status']);
        });

        Schema::create('product_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('identifier_type', 32); // seller_sku, asin, etc.
            $table->string('identifier_value', 191);
            $table->string('marketplace_id')->nullable();
            $table->string('region', 8)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['product_id']);
            $table->index(['identifier_type', 'identifier_value']);
            $table->index(['marketplace_id']);
            $table->unique(['identifier_type', 'identifier_value', 'marketplace_id'], 'product_identifiers_unique');
        });

        Schema::create('product_cost_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->decimal('unit_landed_cost', 12, 4);
            $table->string('currency', 3)->default('GBP');
            $table->string('source', 32)->nullable(); // supplier_invoice, shipping_invoice, manual, etc.
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'effective_from']);
            $table->index(['effective_to']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('amazon_order_id')->constrained('products')->nullOnDelete();
            $table->index(['product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });

        Schema::dropIfExists('product_cost_layers');
        Schema::dropIfExists('product_identifiers');
        Schema::dropIfExists('products');
    }
};

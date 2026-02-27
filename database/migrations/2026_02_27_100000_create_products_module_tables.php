<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('families', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('marketplace', 32);
            $table->string('parent_asin', 32)->nullable();
            $table->timestamps();

            $table->index('marketplace');
            $table->index(['marketplace', 'parent_asin']);
        });

        Schema::create('mcus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->nullable()->constrained('families')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('base_uom', 32)->default('unit');
            $table->decimal('net_weight', 12, 4)->nullable();
            $table->decimal('net_length', 12, 4)->nullable();
            $table->decimal('net_width', 12, 4)->nullable();
            $table->decimal('net_height', 12, 4)->nullable();
            $table->timestamps();

            $table->index('family_id');
        });

        Schema::create('sellable_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mcu_id')->constrained('mcus')->cascadeOnDelete();
            $table->decimal('packaged_weight', 12, 4)->nullable();
            $table->decimal('packaged_length', 12, 4)->nullable();
            $table->decimal('packaged_width', 12, 4)->nullable();
            $table->decimal('packaged_height', 12, 4)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->timestamps();

            $table->unique('mcu_id');
        });

        Schema::create('marketplace_projections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sellable_unit_id')->constrained('sellable_units')->cascadeOnDelete();
            $table->string('marketplace', 32);
            $table->string('parent_asin', 32)->nullable();
            $table->string('child_asin', 32);
            $table->string('seller_sku', 128);
            $table->string('fnsku', 64)->nullable();
            $table->string('fulfilment_type', 8)->default('MFN');
            $table->string('fulfilment_region', 16)->default('EU');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['marketplace', 'child_asin', 'seller_sku'], 'mp_unique_market_child_sku');
            $table->index(['sellable_unit_id']);
            $table->index(['marketplace', 'parent_asin']);
            $table->index(['marketplace', 'seller_sku']);
        });

        Schema::create('cost_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mcu_id')->constrained('mcus')->cascadeOnDelete();
            $table->string('region', 16);
            $table->string('currency', 3);
            $table->decimal('landed_cost_per_unit', 12, 4);
            $table->date('effective_from');
            $table->timestamps();

            $table->index(['mcu_id', 'region', 'effective_from'], 'cc_mcu_region_effective_idx');
            $table->unique(['mcu_id', 'region', 'effective_from'], 'cc_mcu_region_effective_unique');
        });

        Schema::create('inventory_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mcu_id')->constrained('mcus')->cascadeOnDelete();
            $table->string('location', 64);
            $table->integer('on_hand')->default(0);
            $table->integer('reserved')->default(0);
            $table->integer('safety_buffer')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['mcu_id', 'location'], 'inventory_states_mcu_location_unique');
            $table->index(['mcu_id']);
            $table->index(['location']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_states');
        Schema::dropIfExists('cost_contexts');
        Schema::dropIfExists('marketplace_projections');
        Schema::dropIfExists('sellable_units');
        Schema::dropIfExists('mcus');
        Schema::dropIfExists('families');
    }
};

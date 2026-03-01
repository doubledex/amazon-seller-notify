<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcu_cost_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mcu_id')->constrained('mcus')->cascadeOnDelete();
            $table->string('supplier', 191)->nullable();
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 12, 4);
            $table->string('currency', 3);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('marketplace', 32)->nullable();
            $table->string('region', 16)->nullable();
            $table->timestamps();

            $table->index(['mcu_id', 'effective_from'], 'mcu_cost_values_mcu_effective_from_idx');
            $table->index(['marketplace', 'region'], 'mcu_cost_values_marketplace_region_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcu_cost_values');
    }
};

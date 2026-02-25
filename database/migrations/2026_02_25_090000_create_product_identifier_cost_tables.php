<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_identifier_cost_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_identifier_id')->constrained('product_identifiers')->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('currency', 3)->default('GBP');
            $table->string('allocation_basis', 24)->default('per_unit'); // per_unit, per_shipment
            $table->string('shipment_reference', 191)->nullable();
            $table->decimal('unit_landed_cost', 12, 4)->default(0);
            $table->string('source', 32)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['product_identifier_id', 'effective_from'], 'picl_identifier_effective_from_idx');
            $table->index(['product_identifier_id', 'effective_to'], 'picl_identifier_effective_to_idx');
            $table->index(['effective_from', 'effective_to'], 'picl_effective_range_idx');
            $table->unique(
                ['product_identifier_id', 'effective_from', 'allocation_basis', 'currency', 'shipment_reference'],
                'picl_identifier_effective_basis_unique'
            );
        });

        Schema::create('product_identifier_cost_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cost_layer_id')->constrained('product_identifier_cost_layers')->cascadeOnDelete();
            $table->string('component_type', 32); // cog, packaging, shipping, duties, etc.
            $table->decimal('amount', 12, 4);
            $table->string('amount_basis', 24)->default('per_unit'); // per_unit, per_shipment
            $table->decimal('allocation_quantity', 12, 4)->nullable();
            $table->string('allocation_unit', 24)->nullable(); // units, cartons, kg, etc.
            $table->decimal('normalized_unit_amount', 12, 4)->nullable();
            $table->json('allocation_metadata')->nullable();
            $table->timestamps();

            $table->index(['cost_layer_id', 'component_type'], 'picc_layer_component_type_idx');
            $table->index(['component_type'], 'picc_component_type_idx');
            $table->index(['amount_basis'], 'picc_amount_basis_idx');
        });

        $this->backfillFromProductCostLayers();
    }

    public function down(): void
    {
        Schema::dropIfExists('product_identifier_cost_components');
        Schema::dropIfExists('product_identifier_cost_layers');
    }

    private function backfillFromProductCostLayers(): void
    {
        if (! Schema::hasTable('product_cost_layers') || ! Schema::hasTable('product_identifiers')) {
            return;
        }

        $rows = DB::table('product_cost_layers as pcl')
            ->join('product_identifiers as pi', 'pi.product_id', '=', 'pcl.product_id')
            ->leftJoin('product_identifiers as pi_primary', function ($join) {
                $join->on('pi_primary.product_id', '=', 'pcl.product_id')
                    ->where('pi_primary.is_primary', '=', 1);
            })
            ->where(function ($query) {
                $query->where('pi.is_primary', '=', 1)
                    ->orWhereNull('pi_primary.id');
            })
            ->select([
                'pi.id as product_identifier_id',
                'pcl.effective_from',
                'pcl.effective_to',
                'pcl.currency',
                'pcl.unit_landed_cost',
                'pcl.source',
                'pcl.notes',
                'pcl.created_at',
                'pcl.updated_at',
            ])
            ->orderBy('pcl.id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $now = now();

        foreach ($rows as $row) {
            $costLayerId = DB::table('product_identifier_cost_layers')->insertGetId([
                'product_identifier_id' => $row->product_identifier_id,
                'effective_from' => $row->effective_from,
                'effective_to' => $row->effective_to,
                'currency' => $row->currency,
                'allocation_basis' => 'per_unit',
                'shipment_reference' => null,
                'unit_landed_cost' => $row->unit_landed_cost,
                'source' => $row->source,
                'notes' => $row->notes,
                'created_at' => $row->created_at ?? $now,
                'updated_at' => $row->updated_at ?? $now,
            ]);

            DB::table('product_identifier_cost_components')->insert([
                'cost_layer_id' => $costLayerId,
                'component_type' => 'cog',
                'amount' => $row->unit_landed_cost,
                'amount_basis' => 'per_unit',
                'allocation_quantity' => 1,
                'allocation_unit' => 'unit',
                'normalized_unit_amount' => $row->unit_landed_cost,
                'allocation_metadata' => null,
                'created_at' => $row->created_at ?? $now,
                'updated_at' => $row->updated_at ?? $now,
            ]);
        }
    }
};

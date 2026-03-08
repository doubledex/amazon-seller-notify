<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inbound_shipment_cartons', function (Blueprint $table) {
            $table->id();
            $table->string('shipment_id', 64)->index();
            $table->string('carton_id', 128);
            $table->string('sku', 128)->default('');
            $table->string('fnsku', 128)->default('');
            $table->unsignedInteger('expected_units')->default(0);
            $table->unsignedInteger('units_per_carton')->default(0);
            $table->unsignedInteger('carton_count')->default(0);
            $table->timestamps();

            $table->unique(['shipment_id', 'carton_id', 'sku', 'fnsku'], 'inbound_cartons_identity_unique');
            $table->foreign('shipment_id')
                ->references('shipment_id')
                ->on('inbound_shipments')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_shipment_cartons');
    }
};

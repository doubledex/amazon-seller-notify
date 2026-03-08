<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inbound_discrepancies', function (Blueprint $table) {
            $table->id();
            $table->string('shipment_id', 64)->index();
            $table->string('sku', 128)->nullable();
            $table->string('fnsku', 128)->nullable();
            $table->unsignedInteger('expected_units')->default(0);
            $table->unsignedInteger('received_units')->default(0);
            $table->integer('delta')->default(0);
            $table->integer('carton_delta')->default(0);
            $table->string('status', 32)->default('open')->index();
            $table->dateTime('discrepancy_detected_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['shipment_id', 'sku', 'fnsku'], 'inbound_discrepancies_identity_unique');
            $table->foreign('shipment_id')
                ->references('shipment_id')
                ->on('inbound_shipments')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_discrepancies');
    }
};

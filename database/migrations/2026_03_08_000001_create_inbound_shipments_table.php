<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inbound_shipments', function (Blueprint $table) {
            $table->id();
            $table->string('shipment_id', 64)->unique();
            $table->string('region_code', 16)->index();
            $table->string('marketplace_id', 32)->index();
            $table->string('carrier_name', 128)->nullable();
            $table->string('pro_tracking_number', 128)->nullable();
            $table->dateTime('shipment_created_at')->nullable()->index();
            $table->dateTime('shipment_closed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['region_code', 'marketplace_id'], 'inbound_shipments_region_marketplace_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_shipments');
    }
};

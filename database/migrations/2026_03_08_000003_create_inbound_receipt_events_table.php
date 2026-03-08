<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inbound_receipt_events', function (Blueprint $table) {
            $table->id();
            $table->string('shipment_id', 64)->index();
            $table->dateTime('received_snapshot_at')->index();
            $table->unsignedInteger('received_units')->default(0);
            $table->string('source_event_id', 128)->index();
            $table->timestamps();

            $table->unique(['shipment_id', 'source_event_id'], 'inbound_receipt_events_source_unique');
            $table->foreign('shipment_id')
                ->references('shipment_id')
                ->on('inbound_shipments')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_receipt_events');
    }
};

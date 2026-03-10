<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('inbound_shipments', function (Blueprint $table) {
            $table->string('api_source_version', 32)->nullable()->after('shipment_closed_at');
            $table->longText('api_shipment_payload')->nullable()->after('api_source_version');
            $table->longText('api_items_payload')->nullable()->after('api_shipment_payload');
        });
    }

    public function down(): void
    {
        Schema::table('inbound_shipments', function (Blueprint $table) {
            $table->dropColumn(['api_source_version', 'api_shipment_payload', 'api_items_payload']);
        });
    }
};

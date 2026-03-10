<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('inbound_shipment_cartons', function (Blueprint $table) {
            $table->unsignedInteger('received_units')->default(0)->after('expected_units');
        });
    }

    public function down(): void
    {
        Schema::table('inbound_shipment_cartons', function (Blueprint $table) {
            $table->dropColumn('received_units');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('inbound_discrepancies', function (Blueprint $table) {
            $table->unsignedInteger('units_per_carton')->default(0)->after('received_units');
            $table->unsignedInteger('carton_count')->default(0)->after('units_per_carton');
            $table->decimal('carton_equivalent_delta', 12, 4)->default(0)->after('carton_delta');
            $table->boolean('split_carton')->default(false)->after('carton_equivalent_delta')->index();
            $table->decimal('value_impact', 12, 2)->default(0)->after('split_carton');
            $table->dateTime('challenge_deadline_at')->nullable()->after('value_impact')->index();
            $table->string('severity', 16)->default('low')->after('challenge_deadline_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('inbound_discrepancies', function (Blueprint $table) {
            $table->dropColumn([
                'units_per_carton',
                'carton_count',
                'carton_equivalent_delta',
                'split_carton',
                'value_impact',
                'challenge_deadline_at',
                'severity',
            ]);
        });
    }
};

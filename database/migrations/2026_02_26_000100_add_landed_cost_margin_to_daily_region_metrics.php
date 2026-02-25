<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_region_metrics', function (Blueprint $table) {
            $table->decimal('landed_cost_local', 14, 2)->default(0)->after('ad_spend_gbp');
            $table->decimal('landed_cost_gbp', 14, 2)->default(0)->after('landed_cost_local');
            $table->decimal('margin_local', 14, 2)->default(0)->after('landed_cost_gbp');
            $table->decimal('margin_gbp', 14, 2)->default(0)->after('margin_local');
        });
    }

    public function down(): void
    {
        Schema::table('daily_region_metrics', function (Blueprint $table) {
            $table->dropColumn([
                'landed_cost_local',
                'landed_cost_gbp',
                'margin_local',
                'margin_gbp',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sellable_units', function (Blueprint $table) {
            $table->dropUnique('sellable_units_mcu_id_unique');
            $table->index('mcu_id');
        });

        Schema::table('marketplace_projections', function (Blueprint $table) {
            $table->foreignId('mcu_id')->nullable()->after('sellable_unit_id')->constrained('mcus')->cascadeOnDelete();
            $table->index(['mcu_id', 'marketplace']);
        });

        DB::table('marketplace_projections as mp')
            ->join('sellable_units as su', 'su.id', '=', 'mp.sellable_unit_id')
            ->update(['mp.mcu_id' => DB::raw('su.mcu_id')]);
    }

    public function down(): void
    {
        Schema::table('marketplace_projections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mcu_id');
        });

        Schema::table('sellable_units', function (Blueprint $table) {
            $table->dropIndex(['mcu_id']);
            $table->unique('mcu_id');
        });
    }
};

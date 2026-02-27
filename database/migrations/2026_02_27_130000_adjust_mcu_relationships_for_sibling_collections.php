<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sellable_units', 'mcu_id')) {
            Schema::table('sellable_units', function (Blueprint $table) {
                // MySQL requires an index on FK columns; add a replacement index before dropping unique.
                $table->index('mcu_id');
                $table->dropUnique('sellable_units_mcu_id_unique');
            });
        }

        if (!Schema::hasColumn('marketplace_projections', 'mcu_id')) {
            Schema::table('marketplace_projections', function (Blueprint $table) {
                $table->foreignId('mcu_id')->nullable()->after('sellable_unit_id')->constrained('mcus')->cascadeOnDelete();
                $table->index(['mcu_id', 'marketplace']);
            });
        }

        if (Schema::hasColumn('marketplace_projections', 'mcu_id')) {
            DB::table('marketplace_projections')
                ->whereNull('mcu_id')
                ->update([
                    'mcu_id' => DB::raw('(select su.mcu_id from sellable_units as su where su.id = marketplace_projections.sellable_unit_id)'),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('marketplace_projections', 'mcu_id')) {
            Schema::table('marketplace_projections', function (Blueprint $table) {
                $table->dropConstrainedForeignId('mcu_id');
            });
        }

        if (Schema::hasColumn('sellable_units', 'mcu_id')) {
            Schema::table('sellable_units', function (Blueprint $table) {
                $table->unique('mcu_id');
                $table->dropIndex(['mcu_id']);
            });
        }
    }
};

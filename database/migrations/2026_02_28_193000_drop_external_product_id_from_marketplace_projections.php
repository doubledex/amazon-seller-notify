<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('marketplace_projections', 'external_product_id')) {
            Schema::table('marketplace_projections', function (Blueprint $table) {
                $table->dropColumn('external_product_id');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('marketplace_projections', 'external_product_id')) {
            Schema::table('marketplace_projections', function (Blueprint $table) {
                $table->string('external_product_id', 191)->nullable()->after('seller_sku');
            });
        }
    }
};

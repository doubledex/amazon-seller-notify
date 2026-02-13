<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_ship_addresses', function (Blueprint $table) {
            $table->json('raw_address')->nullable()->after('region');
        });
    }

    public function down(): void
    {
        Schema::table('order_ship_addresses', function (Blueprint $table) {
            $table->dropColumn('raw_address');
        });
    }
};

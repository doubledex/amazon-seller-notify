<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->integer('quantity_shipped')->nullable()->after('quantity_ordered');
            $table->integer('quantity_unshipped')->nullable()->after('quantity_shipped');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['quantity_shipped', 'quantity_unshipped']);
        });
    }
};

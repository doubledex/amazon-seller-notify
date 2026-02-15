<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_marketplace_facilitator')->nullable()->after('is_business_order');
            $table->index('is_marketplace_facilitator');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['is_marketplace_facilitator']);
            $table->dropColumn('is_marketplace_facilitator');
        });
    }
};


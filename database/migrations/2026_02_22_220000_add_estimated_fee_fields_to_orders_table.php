<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('amazon_fee_estimated_total', 12, 2)->nullable()->after('amazon_fee_total');
            $table->string('amazon_fee_estimated_currency', 3)->nullable()->after('amazon_fee_estimated_total');
            $table->string('amazon_fee_estimated_source', 64)->nullable()->after('amazon_fee_estimated_currency');
            $table->dateTime('amazon_fee_estimated_at')->nullable()->after('amazon_fee_estimated_source');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'amazon_fee_estimated_total',
                'amazon_fee_estimated_currency',
                'amazon_fee_estimated_source',
                'amazon_fee_estimated_at',
            ]);
        });
    }
};


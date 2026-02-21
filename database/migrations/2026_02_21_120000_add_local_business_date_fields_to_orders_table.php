<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('purchase_date_local')->nullable()->after('purchase_date');
            $table->date('purchase_date_local_date')->nullable()->after('purchase_date_local');
            $table->string('marketplace_timezone', 64)->nullable()->after('marketplace_id');

            $table->index('purchase_date_local_date');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['purchase_date_local_date']);
            $table->dropColumn(['purchase_date_local', 'purchase_date_local_date', 'marketplace_timezone']);
        });
    }
};

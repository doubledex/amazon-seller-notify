<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cashflow_outstanding_transactions', function (Blueprint $table) {
            $table->string('deferral_reason', 64)->nullable()->after('days_posted_to_maturity')->index();
        });
    }

    public function down(): void
    {
        Schema::table('cashflow_outstanding_transactions', function (Blueprint $table) {
            $table->dropColumn('deferral_reason');
        });
    }
};

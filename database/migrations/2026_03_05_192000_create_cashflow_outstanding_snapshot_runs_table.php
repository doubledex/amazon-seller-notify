<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cashflow_outstanding_snapshot_runs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('ran_at_utc')->index();
            $table->integer('lookback_days')->default(30);
            $table->integer('regions_processed')->default(0);
            $table->integer('marketplaces_processed')->default(0);
            $table->integer('transactions_seen')->default(0);
            $table->integer('rows_written')->default(0);
            $table->integer('excluded_by_status')->default(0);
            $table->integer('excluded_missing_maturity')->default(0);
            $table->integer('rows_missing_total_amount')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashflow_outstanding_snapshot_runs');
    }
};

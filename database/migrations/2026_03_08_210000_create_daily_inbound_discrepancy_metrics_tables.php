<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('daily_inbound_discrepancy_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('metric_date')->unique();
            $table->unsignedBigInteger('shipped_units')->default(0);
            $table->unsignedInteger('discrepancy_count')->default(0);
            $table->decimal('discrepancy_rate_per_1000', 12, 4)->default(0);
            $table->unsignedInteger('claims_submitted_count')->default(0);
            $table->unsignedInteger('claims_before_deadline_count')->default(0);
            $table->decimal('claims_submitted_before_deadline_percent', 7, 2)->nullable();
            $table->unsignedInteger('claims_closed_count')->default(0);
            $table->unsignedInteger('claims_won_count')->default(0);
            $table->decimal('claim_win_rate_percent', 7, 2)->nullable();
            $table->decimal('avg_reimbursement_cycle_days', 10, 2)->nullable();
            $table->decimal('recovered_value', 14, 2)->default(0);
            $table->decimal('disputed_value', 14, 2)->default(0);
            $table->decimal('recovered_vs_disputed_percent', 7, 2)->nullable();
            $table->unsignedInteger('aged_open_missed')->default(0);
            $table->unsignedInteger('aged_open_due_0_7_days')->default(0);
            $table->unsignedInteger('aged_open_due_8_14_days')->default(0);
            $table->unsignedInteger('aged_open_due_15_plus_days')->default(0);
            $table->unsignedInteger('aged_open_no_deadline')->default(0);
            $table->timestamps();
        });

        Schema::create('daily_inbound_split_carton_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('metric_date')->index();
            $table->string('fulfillment_center_id', 64)->default('UNKNOWN');
            $table->string('sku', 128)->default('');
            $table->string('carrier_name', 128)->default('UNKNOWN');
            $table->unsignedInteger('discrepancy_count')->default(0);
            $table->unsignedInteger('split_carton_count')->default(0);
            $table->decimal('split_carton_anomaly_rate_percent', 7, 2)->default(0);
            $table->timestamps();

            $table->unique(
                ['metric_date', 'fulfillment_center_id', 'sku', 'carrier_name'],
                'daily_split_carton_metrics_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_inbound_split_carton_metrics');
        Schema::dropIfExists('daily_inbound_discrepancy_metrics');
    }
};

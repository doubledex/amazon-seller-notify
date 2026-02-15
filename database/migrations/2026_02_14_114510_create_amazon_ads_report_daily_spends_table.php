<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amazon_ads_report_daily_spends', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('report_request_id');
            $table->string('report_id');
            $table->string('profile_id');
            $table->date('metric_date');
            $table->string('region', 8);
            $table->string('currency', 3);
            $table->decimal('amount_local', 14, 2);
            $table->timestamps();

            $table->unique(['report_id', 'metric_date', 'region', 'currency'], 'ads_report_daily_unique');
            $table->index(['metric_date', 'region'], 'ads_report_daily_date_region_idx');
            $table->foreign('report_request_id')->references('id')->on('amazon_ads_report_requests')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amazon_ads_report_daily_spends');
    }
};

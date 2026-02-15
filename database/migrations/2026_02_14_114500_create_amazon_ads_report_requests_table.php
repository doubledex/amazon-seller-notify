<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amazon_ads_report_requests', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->unique();
            $table->string('report_name')->nullable();
            $table->string('profile_id');
            $table->string('country_code', 8)->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->string('region', 8)->nullable();
            $table->string('ad_product', 64);
            $table->string('report_type_id', 64);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 32)->default('PENDING');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('waited_seconds')->default(0);
            $table->unsignedInteger('check_attempts')->default(0);
            $table->text('download_url')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('processed_rows')->default(0);
            $table->text('processing_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'processed_at', 'last_checked_at'], 'ads_reports_status_idx');
            $table->index(['start_date', 'end_date'], 'ads_reports_date_idx');
            $table->index(['profile_id', 'ad_product'], 'ads_reports_profile_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amazon_ads_report_requests');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amazon_ads_report_requests', function (Blueprint $table) {
            $table->index(
                ['profile_id', 'ad_product', 'report_type_id', 'start_date', 'end_date', 'processed_at', 'status'],
                'ads_reports_queue_dedupe_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('amazon_ads_report_requests', function (Blueprint $table) {
            $table->dropIndex('ads_reports_queue_dedupe_idx');
        });
    }
};

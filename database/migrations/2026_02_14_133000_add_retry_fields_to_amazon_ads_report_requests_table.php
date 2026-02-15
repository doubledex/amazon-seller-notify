<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amazon_ads_report_requests', function (Blueprint $table) {
            $table->unsignedInteger('retry_count')->default(0)->after('check_attempts');
            $table->timestamp('next_check_at')->nullable()->after('retry_count');
            $table->string('last_http_status', 16)->nullable()->after('next_check_at');
            $table->string('last_request_id', 128)->nullable()->after('last_http_status');
            $table->timestamp('stuck_alerted_at')->nullable()->after('last_request_id');

            $table->index(['next_check_at', 'processed_at', 'status'], 'ads_reports_next_check_idx');
        });
    }

    public function down(): void
    {
        Schema::table('amazon_ads_report_requests', function (Blueprint $table) {
            $table->dropIndex('ads_reports_next_check_idx');
            $table->dropColumn([
                'retry_count',
                'next_check_at',
                'last_http_status',
                'last_request_id',
                'stuck_alerted_at',
            ]);
        });
    }
};

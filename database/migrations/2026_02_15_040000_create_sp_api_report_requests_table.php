<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sp_api_report_requests', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->unique();
            $table->string('marketplace_id', 32);
            $table->string('report_type', 128);
            $table->string('status', 32)->default('IN_QUEUE');
            $table->string('report_document_id', 128)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->unsignedInteger('check_attempts')->default(0);
            $table->unsignedInteger('waited_seconds')->default(0);
            $table->string('last_http_status', 16)->nullable();
            $table->string('last_request_id', 128)->nullable();
            $table->timestamp('stuck_alerted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('rows_synced')->default(0);
            $table->unsignedInteger('parents_synced')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['processed_at', 'status', 'next_check_at'], 'sp_reports_poll_idx');
            $table->index(['marketplace_id', 'report_type'], 'sp_reports_marketplace_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sp_api_report_requests');
    }
};

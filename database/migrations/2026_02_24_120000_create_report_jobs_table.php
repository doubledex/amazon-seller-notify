<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64);
            $table->string('processor', 64)->nullable();
            $table->string('region', 16)->nullable();
            $table->string('marketplace_id', 32)->nullable();
            $table->string('report_type', 128);
            $table->string('status', 32)->default('queued');
            $table->json('scope')->nullable();
            $table->json('report_options')->nullable();
            $table->timestamp('data_start_time')->nullable();
            $table->timestamp('data_end_time')->nullable();
            $table->string('external_report_id', 128)->nullable();
            $table->string('external_document_id', 128)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('next_poll_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('rows_parsed')->default(0);
            $table->unsignedInteger('rows_ingested')->default(0);
            $table->string('document_url_sha256', 64)->nullable();
            $table->text('last_error')->nullable();
            $table->json('debug_payload')->nullable();
            $table->timestamps();

            $table->index(['provider', 'status', 'next_poll_at'], 'report_jobs_poll_idx');
            $table->index(['provider', 'processor', 'status'], 'report_jobs_processor_idx');
            $table->index(['marketplace_id', 'report_type'], 'report_jobs_marketplace_idx');
            $table->index(['provider', 'external_report_id'], 'report_jobs_external_report_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_jobs');
    }
};

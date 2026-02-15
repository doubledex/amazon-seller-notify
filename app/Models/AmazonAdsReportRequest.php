<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AmazonAdsReportRequest extends Model
{
    protected $fillable = [
        'report_id',
        'report_name',
        'profile_id',
        'country_code',
        'currency_code',
        'region',
        'ad_product',
        'report_type_id',
        'start_date',
        'end_date',
        'status',
        'requested_at',
        'last_checked_at',
        'completed_at',
        'waited_seconds',
        'check_attempts',
        'retry_count',
        'next_check_at',
        'last_http_status',
        'last_request_id',
        'stuck_alerted_at',
        'download_url',
        'failure_reason',
        'processed_at',
        'processed_rows',
        'processing_error',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'requested_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'next_check_at' => 'datetime',
        'stuck_alerted_at' => 'datetime',
        'completed_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}

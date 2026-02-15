<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpApiReportRequest extends Model
{
    protected $fillable = [
        'report_id',
        'marketplace_id',
        'report_type',
        'status',
        'report_document_id',
        'requested_at',
        'last_checked_at',
        'next_check_at',
        'retry_count',
        'check_attempts',
        'waited_seconds',
        'last_http_status',
        'last_request_id',
        'stuck_alerted_at',
        'processed_at',
        'rows_synced',
        'parents_synced',
        'error_message',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'next_check_at' => 'datetime',
        'stuck_alerted_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}

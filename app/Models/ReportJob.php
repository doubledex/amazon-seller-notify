<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportJob extends Model
{
    protected $fillable = [
        'provider',
        'processor',
        'region',
        'marketplace_id',
        'report_type',
        'status',
        'scope',
        'report_options',
        'data_start_time',
        'data_end_time',
        'external_report_id',
        'external_document_id',
        'requested_at',
        'last_polled_at',
        'next_poll_at',
        'completed_at',
        'attempt_count',
        'rows_parsed',
        'rows_ingested',
        'document_url_sha256',
        'last_error',
        'debug_payload',
    ];

    protected $casts = [
        'scope' => 'array',
        'report_options' => 'array',
        'debug_payload' => 'array',
        'data_start_time' => 'datetime',
        'data_end_time' => 'datetime',
        'requested_at' => 'datetime',
        'last_polled_at' => 'datetime',
        'next_poll_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}

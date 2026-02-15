<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AmazonAdsReportDailySpend extends Model
{
    protected $fillable = [
        'report_request_id',
        'report_id',
        'profile_id',
        'metric_date',
        'region',
        'currency',
        'source_currency',
        'source_amount',
        'amount_local',
    ];

    protected $casts = [
        'metric_date' => 'date',
        'source_amount' => 'decimal:2',
        'amount_local' => 'decimal:2',
    ];
}

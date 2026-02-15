<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyRegionMetric extends Model
{
    protected $fillable = [
        'metric_date',
        'region',
        'local_currency',
        'sales_local',
        'sales_gbp',
        'ad_spend_local',
        'ad_spend_gbp',
        'acos_percent',
        'order_count',
    ];

    protected $casts = [
        'metric_date' => 'date',
        'sales_local' => 'decimal:2',
        'sales_gbp' => 'decimal:2',
        'ad_spend_local' => 'decimal:2',
        'ad_spend_gbp' => 'decimal:2',
        'acos_percent' => 'decimal:2',
    ];
}


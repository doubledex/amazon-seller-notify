<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyRegionAdSpend extends Model
{
    protected $fillable = [
        'metric_date',
        'region',
        'currency',
        'amount_local',
        'source',
    ];

    protected $casts = [
        'metric_date' => 'date',
        'amount_local' => 'decimal:2',
    ];
}


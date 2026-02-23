<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderFeeEstimateLine extends Model
{
    protected $fillable = [
        'line_hash',
        'amazon_order_id',
        'asin',
        'marketplace_id',
        'fee_type',
        'description',
        'amount',
        'currency',
        'source',
        'estimated_at',
        'raw_line',
    ];

    protected $casts = [
        'estimated_at' => 'datetime',
        'raw_line' => 'array',
    ];
}


<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AmazonOrderFeeLine extends Model
{
    protected $fillable = [
        'fee_hash',
        'amazon_order_id',
        'region',
        'event_type',
        'fee_type',
        'description',
        'amount',
        'currency',
        'posted_date',
        'raw_line',
    ];

    protected $casts = [
        'posted_date' => 'datetime',
        'raw_line' => 'array',
    ];
}


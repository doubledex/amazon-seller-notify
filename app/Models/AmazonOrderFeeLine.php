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
        'deferred_amount',
        'released_amount',
        'posted_date',
        'maturity_date',
        'effective_payment_date',
        'raw_line',
    ];

    protected $casts = [
        'posted_date' => 'datetime',
        'maturity_date' => 'datetime',
        'effective_payment_date' => 'datetime',
        'raw_line' => 'array',
    ];
}


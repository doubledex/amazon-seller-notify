<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderSyncRun extends Model
{
    protected $fillable = [
        'source',
        'region',
        'days',
        'max_pages',
        'items_limit',
        'address_limit',
        'created_after_at',
        'created_before_at',
        'started_at',
        'finished_at',
        'status',
        'message',
        'orders_synced',
        'items_fetched',
        'addresses_fetched',
        'geocoded',
    ];

    protected $casts = [
        'created_after_at' => 'datetime',
        'created_before_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}


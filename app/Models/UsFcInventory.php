<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsFcInventory extends Model
{
    protected $fillable = [
        'marketplace_id',
        'fulfillment_center_id',
        'seller_sku',
        'asin',
        'fnsku',
        'item_condition',
        'quantity_available',
        'raw_row',
        'report_id',
        'report_type',
        'report_date',
        'last_seen_at',
    ];

    protected $casts = [
        'raw_row' => 'array',
        'last_seen_at' => 'datetime',
    ];
}

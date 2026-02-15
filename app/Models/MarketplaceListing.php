<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceListing extends Model
{
    protected $fillable = [
        'marketplace_id',
        'seller_sku',
        'asin',
        'item_name',
        'listing_status',
        'quantity',
        'parentage',
        'is_parent',
        'source_report_id',
        'last_seen_at',
        'raw_listing',
    ];

    protected $casts = [
        'is_parent' => 'boolean',
        'last_seen_at' => 'datetime',
        'raw_listing' => 'array',
    ];
}


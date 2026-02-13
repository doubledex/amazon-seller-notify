<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'amazon_order_id',
        'order_item_id',
        'asin',
        'seller_sku',
        'title',
        'quantity_ordered',
        'item_price_amount',
        'item_price_currency',
        'raw_item',
    ];

    protected $casts = [
        'raw_item' => 'array',
    ];
}

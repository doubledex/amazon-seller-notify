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
        'quantity_shipped',
        'quantity_unshipped',
        'item_price_amount',
        'line_net_ex_tax',
        'item_price_currency',
        'line_net_currency',
        'estimated_line_net_ex_tax',
        'estimated_line_currency',
        'estimated_line_source',
        'estimated_line_estimated_at',
        'raw_item',
    ];

    protected $casts = [
        'raw_item' => 'array',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'amazon_order_id',
        'purchase_date',
        'order_status',
        'fulfillment_channel',
        'payment_method',
        'sales_channel',
        'marketplace_id',
        'is_business_order',
        'order_total_amount',
        'order_total_currency',
        'shipping_city',
        'shipping_country_code',
        'shipping_postal_code',
        'shipping_company',
        'shipping_region',
        'raw_order',
        'last_synced_at',
    ];

    protected $casts = [
        'raw_order' => 'array',
        'is_business_order' => 'boolean',
        'purchase_date' => 'datetime',
        'last_synced_at' => 'datetime',
    ];
}

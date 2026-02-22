<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'amazon_order_id',
        'purchase_date',
        'purchase_date_local',
        'purchase_date_local_date',
        'order_status',
        'fulfillment_channel',
        'payment_method',
        'sales_channel',
        'marketplace_id',
        'marketplace_timezone',
        'is_business_order',
        'is_marketplace_facilitator',
        'order_total_amount',
        'order_net_ex_tax',
        'order_net_ex_tax_currency',
        'order_net_ex_tax_source',
        'amazon_fee_total',
        'amazon_fee_currency',
        'amazon_fee_last_synced_at',
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
        'is_marketplace_facilitator' => 'boolean',
        'purchase_date' => 'datetime',
        'purchase_date_local' => 'datetime',
        'purchase_date_local_date' => 'date',
        'amazon_fee_last_synced_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];
}

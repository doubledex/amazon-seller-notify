<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceProjection extends Model
{
    protected $fillable = [
        'sellable_unit_id',
        'mcu_id',
        'channel',
        'marketplace',
        'parent_asin',
        'child_asin',
        'seller_sku',
        'fnsku',
        'fulfilment_type',
        'fulfilment_region',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function sellableUnit()
    {
        return $this->belongsTo(SellableUnit::class);
    }

    public function mcu()
    {
        return $this->belongsTo(Mcu::class);
    }
}

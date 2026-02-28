<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mcu extends Model
{
    protected $fillable = [
        'family_id',
        'name',
        'base_uom',
        'net_weight',
        'net_length',
        'net_width',
        'net_height',
    ];

    protected $casts = [
        'net_weight' => 'decimal:4',
        'net_length' => 'decimal:4',
        'net_width' => 'decimal:4',
        'net_height' => 'decimal:4',
    ];

    public function family()
    {
        return $this->belongsTo(Family::class);
    }

    public function sellableUnits()
    {
        return $this->hasMany(SellableUnit::class);
    }

    public function marketplaceProjections()
    {
        return $this->hasMany(MarketplaceProjection::class);
    }

    public function identifiers()
    {
        return $this->hasMany(McuIdentifier::class);
    }

    public function costContexts()
    {
        return $this->hasMany(CostContext::class);
    }

    public function inventoryStates()
    {
        return $this->hasMany(InventoryState::class);
    }
}

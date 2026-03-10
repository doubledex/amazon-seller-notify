<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundShipmentCarton extends Model
{
    protected $fillable = [
        'shipment_id',
        'carton_id',
        'sku',
        'fnsku',
        'expected_units',
        'received_units',
        'units_per_carton',
        'carton_count',
    ];

    protected $casts = [
        'expected_units' => 'integer',
        'received_units' => 'integer',
        'units_per_carton' => 'integer',
        'carton_count' => 'integer',
    ];


    public function setSkuAttribute($value): void
    {
        $this->attributes['sku'] = $value ?? '';
    }

    public function setFnskuAttribute($value): void
    {
        $this->attributes['fnsku'] = $value ?? '';
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(InboundShipment::class, 'shipment_id', 'shipment_id');
    }
}

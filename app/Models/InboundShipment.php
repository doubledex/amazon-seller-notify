<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InboundShipment extends Model
{
    protected $fillable = [
        'shipment_id',
        'region_code',
        'marketplace_id',
        'carrier_name',
        'pro_tracking_number',
        'shipment_created_at',
        'shipment_closed_at',
    ];

    protected $casts = [
        'shipment_created_at' => 'datetime',
        'shipment_closed_at' => 'datetime',
    ];

    public function cartons(): HasMany
    {
        return $this->hasMany(InboundShipmentCarton::class, 'shipment_id', 'shipment_id');
    }

    public function receiptEvents(): HasMany
    {
        return $this->hasMany(InboundReceiptEvent::class, 'shipment_id', 'shipment_id');
    }

    public function discrepancies(): HasMany
    {
        return $this->hasMany(InboundDiscrepancy::class, 'shipment_id', 'shipment_id');
    }
}

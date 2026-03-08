<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InboundDiscrepancy extends Model
{
    protected $fillable = [
        'shipment_id',
        'sku',
        'fnsku',
        'expected_units',
        'received_units',
        'delta',
        'carton_delta',
        'status',
        'discrepancy_detected_at',
    ];

    protected $casts = [
        'expected_units' => 'integer',
        'received_units' => 'integer',
        'delta' => 'integer',
        'carton_delta' => 'integer',
        'discrepancy_detected_at' => 'datetime',
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

    public function claimCases(): HasMany
    {
        return $this->hasMany(InboundClaimCase::class, 'discrepancy_id');
    }
}

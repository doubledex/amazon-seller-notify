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
        'units_per_carton',
        'carton_count',
        'delta',
        'carton_delta',
        'carton_equivalent_delta',
        'split_carton',
        'value_impact',
        'challenge_deadline_at',
        'severity',
        'status',
        'discrepancy_detected_at',
    ];

    protected $casts = [
        'expected_units' => 'integer',
        'received_units' => 'integer',
        'units_per_carton' => 'integer',
        'carton_count' => 'integer',
        'delta' => 'integer',
        'carton_delta' => 'integer',
        'carton_equivalent_delta' => 'decimal:4',
        'split_carton' => 'boolean',
        'value_impact' => 'decimal:2',
        'challenge_deadline_at' => 'datetime',
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

    public function slaTransitions(): HasMany
    {
        return $this->hasMany(InboundClaimSlaTransition::class, 'discrepancy_id');
    }
}

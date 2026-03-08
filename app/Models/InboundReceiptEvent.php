<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundReceiptEvent extends Model
{
    protected $fillable = [
        'shipment_id',
        'received_snapshot_at',
        'received_units',
        'source_event_id',
    ];

    protected $casts = [
        'received_snapshot_at' => 'datetime',
        'received_units' => 'integer',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(InboundShipment::class, 'shipment_id', 'shipment_id');
    }
}

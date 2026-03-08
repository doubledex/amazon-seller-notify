<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundClaimSlaTransition extends Model
{
    protected $fillable = [
        'discrepancy_id',
        'from_state',
        'to_state',
        'metadata',
        'transitioned_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'transitioned_at' => 'datetime',
    ];

    public function discrepancy(): BelongsTo
    {
        return $this->belongsTo(InboundDiscrepancy::class, 'discrepancy_id');
    }
}

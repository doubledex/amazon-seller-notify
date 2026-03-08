<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundClaimCase extends Model
{
    protected $fillable = [
        'discrepancy_id',
        'challenge_deadline_at',
        'submitted_at',
        'outcome',
        'reimbursed_units',
        'reimbursed_amount',
    ];

    protected $casts = [
        'challenge_deadline_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reimbursed_units' => 'integer',
        'reimbursed_amount' => 'decimal:2',
    ];

    public function discrepancy(): BelongsTo
    {
        return $this->belongsTo(InboundDiscrepancy::class, 'discrepancy_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundClaimCaseEvidence extends Model
{
    protected $fillable = [
        'claim_case_id',
        'discrepancy_id',
        'artifact_type',
        'disk',
        'path',
        'checksum',
        'uploaded_at',
        'metadata',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function claimCase(): BelongsTo
    {
        return $this->belongsTo(InboundClaimCase::class, 'claim_case_id');
    }

    public function discrepancy(): BelongsTo
    {
        return $this->belongsTo(InboundDiscrepancy::class, 'discrepancy_id');
    }
}

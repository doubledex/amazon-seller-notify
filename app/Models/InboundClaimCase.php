<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InboundClaimCase extends Model
{
    protected $fillable = [
        'discrepancy_id',
        'challenge_deadline_at',
        'submitted_at',
        'outcome',
        'reimbursed_units',
        'reimbursed_amount',
        'evidence_validation',
        'dossier_payload',
        'dossier_summary',
        'submission_references',
    ];

    protected $casts = [
        'challenge_deadline_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reimbursed_units' => 'integer',
        'reimbursed_amount' => 'decimal:2',
        'evidence_validation' => 'array',
        'dossier_payload' => 'array',
        'submission_references' => 'array',
    ];

    public function discrepancy(): BelongsTo
    {
        return $this->belongsTo(InboundDiscrepancy::class, 'discrepancy_id');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(InboundClaimCaseEvidence::class, 'claim_case_id');
    }
}

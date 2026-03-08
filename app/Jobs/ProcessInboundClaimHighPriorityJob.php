<?php

namespace App\Jobs;

use App\Models\InboundClaimCase;
use App\Models\InboundDiscrepancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessInboundClaimHighPriorityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $discrepancyId,
        public string $priorityTier,
        public ?int $hoursRemaining,
    ) {
    }

    public function handle(): void
    {
        $discrepancy = InboundDiscrepancy::query()->find($this->discrepancyId);
        if ($discrepancy === null) {
            return;
        }

        InboundClaimCase::query()->firstOrCreate(
            ['discrepancy_id' => $discrepancy->id],
            [
                'challenge_deadline_at' => $discrepancy->challenge_deadline_at,
                'outcome' => null,
            ]
        );

        // Placeholder for immediate evidence assembly + draft generation orchestration.
        // Future implementation can fan out to dedicated evidence and draft jobs/services.
    }
}

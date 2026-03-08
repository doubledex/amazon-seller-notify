<?php

namespace App\Jobs;

use App\Services\Inbound\DailyInboundDiscrepancyRollupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class RefreshDailyInboundDiscrepancyMetricsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $fromDate,
        public string $toDate,
    ) {
    }

    public function handle(DailyInboundDiscrepancyRollupService $service): void
    {
        $service->refreshRange(
            Carbon::parse($this->fromDate),
            Carbon::parse($this->toDate),
        );
    }
}

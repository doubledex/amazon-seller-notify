<?php

namespace App\Console\Commands;

use App\Jobs\RefreshDailyInboundDiscrepancyMetricsJob;
use App\Services\Inbound\DailyInboundDiscrepancyRollupService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RefreshInboundDiscrepancyMetrics extends Command
{
    protected $signature = 'metrics:inbound-refresh {--from=} {--to=} {--queue}';

    protected $description = 'Refresh daily inbound discrepancy and claim KPI rollups.';

    public function handle(DailyInboundDiscrepancyRollupService $service): int
    {
        $from = Carbon::parse((string) ($this->option('from') ?: now()->subDay()->toDateString()));
        $to = Carbon::parse((string) ($this->option('to') ?: $from->toDateString()));

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        if ((bool) $this->option('queue')) {
            RefreshDailyInboundDiscrepancyMetricsJob::dispatch($from->toDateString(), $to->toDateString());
            $this->info(sprintf(
                'Queued inbound discrepancy KPI refresh for %s to %s.',
                $from->toDateString(),
                $to->toDateString()
            ));

            return self::SUCCESS;
        }

        $summary = $service->refreshRange($from, $to);
        $this->info(sprintf(
            'Inbound discrepancy KPI refresh complete: %d day(s), %d summary row(s), %d split-carton row(s).',
            $summary['days'],
            $summary['rows'],
            $summary['split_rows']
        ));

        return self::SUCCESS;
    }
}
